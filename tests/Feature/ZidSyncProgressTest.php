<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\CheckZidConnection;
use App\Jobs\SyncStoreData;
use App\Models\Product;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Analytics\PerformanceClassifier;
use App\Services\Zid\ZidClient;
use App\Services\Zid\ZidMapper;
use App\Services\Zid\ZidSyncState;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class ZidSyncProgressTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private ZidSyncState $state;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Bus::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $this->store = app(CreateStore::class)->handle($owner, ['name' => 'Sync test']);
        $this->store->update([
            'platform' => 'zid', 'platform_store_id' => '41960', 'connection_status' => 'connected',
            'zid_authorization' => 'app-token', 'zid_access_token' => 'manager-token',
        ]);
        $this->state = app(ZidSyncState::class);
        $this->actingAs($owner);
    }

    private function products(int $count = 1, int $offset = 0): array
    {
        return array_map(fn ($n) => ['id' => 'real-'.$n, 'name' => 'Product '.$n, 'price' => 20, 'quantity' => 5], range($offset + 1, $offset + $count));
    }

    private function order(): array
    {
        return ['id' => 200, 'created_at' => now()->toDateTimeString(), 'status' => 'delivered', 'products' => [['id' => 'real-1', 'quantity' => 2, 'total' => 40]]];
    }

    private function perform(SyncRun $run, ?SyncStoreData $job = null): void
    {
        $job ??= new SyncStoreData($this->store, runId: $run->id);
        for ($step = 0; $step < 20; $step++) {
            $this->app->call([$job, 'handle']);
            if ($job->chained === []) {
                return;
            }
            $next = unserialize(array_shift($job->chained));
            $next->chained = $job->chained;
            $job = $next;
        }
        $this->fail('The import did not terminate.');
    }

    public function test_requests_reserve_one_visible_queued_run_before_dispatch(): void
    {
        $this->post('/store/integrations/zid/sync')->assertRedirect();
        $this->post('/store/integrations/zid/sync')->assertRedirect();
        $this->assertDatabaseCount('sync_runs', 1);
        $run = SyncRun::firstOrFail();
        $this->assertSame('queued', $run->status);
        Bus::assertDispatchedTimes(SyncStoreData::class, 1);
        Bus::assertDispatched(SyncStoreData::class, fn ($job) => $job->runId === $run->id && $job->timeout < config('queue.connections.database.retry_after'));
        $this->get('/store/settings/integration')->assertInertia(fn ($page) => $page
            ->where('sync.status', 'queued')->where('sync.products_read', 0)->where('runs.0.status', 'queued'));
        $this->get('/store')->assertInertia(fn ($page) => $page->where('sync.id', $run->id)->where('setup.state', 'syncing'));
    }

    public function test_progress_is_committed_between_pages_before_atomic_catalog_save(): void
    {
        $run = $this->state->enqueue($this->store);
        $baseline = DB::transactionLevel();
        Http::fake(function ($request) use ($run, $baseline) {
            $this->assertSame($baseline, DB::transactionLevel(), 'Network requests must not hold the catalog transaction.');
            $this->assertDatabaseCount('products', 0);
            if (str_contains($request->url(), 'products/')) {
                if ((int) $request['page'] === 1) {
                    return Http::response(['results' => $this->products(50), 'next' => 'https://api.zid.sa/v1/products/?page=2']);
                }
                $this->assertSame(50, $run->fresh()->stats['products_read']);
                $this->assertSame(1, $run->fresh()->stats['product_pages']);

                return Http::response(['results' => $this->products(1, 50), 'next' => null]);
            }
            $this->assertSame('orders', $run->fresh()->stats['phase']);
            $this->assertSame(51, $run->fresh()->stats['products_read']);

            return Http::response(['orders' => [$this->order()], 'pagination' => ['last_page' => 1]]);
        });
        $this->perform($run);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(51, $run->stats['products']);
        $this->assertSame(1, $run->stats['orders']);
        $this->assertDatabaseCount('products', 51);
        $this->assertDatabaseHas('product_sales', ['store_id' => $this->store->id, 'units' => 2, 'revenue' => 40]);
        $this->assertNotNull($this->store->fresh()->last_synced_at);
        Http::assertSentCount(3);
    }

    public function test_api_failure_keeps_previous_data_and_credentials_and_records_failure(): void
    {
        $product = Product::create(['store_id' => $this->store->id, 'external_id' => 'real-1', 'name' => 'Existing', 'price' => 99]);
        $run = $this->state->enqueue($this->store);
        Http::fake([
            '*products/*' => Http::response(['results' => $this->products(), 'next' => null]),
            '*orders*' => Http::response(['message' => ['description' => 'No permission']], 403),
        ]);
        try {
            $this->perform($run);
            $this->fail('Expected API error to reach the queue worker.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
        }
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('authorization_failed', $run->fresh()->error);
        $this->assertSame('Existing', $product->fresh()->name);
        $this->assertNull($this->store->fresh()->last_synced_at);
        $this->assertSame('connected', $this->store->fresh()->connection_status);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertTrue(Cache::lock(SyncStoreData::lockKey($this->store), 1)->get());
    }

    public function test_save_or_analysis_failure_rolls_back_all_catalog_changes(): void
    {
        $run = $this->state->enqueue($this->store);
        Http::fake([
            '*products/*' => Http::response(['results' => $this->products(), 'next' => null]),
            '*orders*' => Http::response(['orders' => [$this->order()]]),
        ]);
        $this->mock(PerformanceClassifier::class)->shouldReceive('classify')->once()->andThrow(new RuntimeException('Analysis failed'));
        try {
            $this->perform($run);
            $this->fail('Expected analysis error.');
        } catch (RuntimeException $e) {
            $this->assertSame('Analysis failed', $e->getMessage());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($this->store->fresh()->last_synced_at);
    }

    public function test_known_last_page_and_null_next_stop_even_for_full_pages(): void
    {
        Http::fake([
            '*products/*' => Http::response(['results' => $this->products(50), 'next' => null]),
            '*orders*' => Http::response(['orders' => $this->products(50), 'pagination' => ['last_page' => 1]]),
        ]);
        $client = new ZidClient($this->store);
        $this->assertCount(50, iterator_to_array($client->products()));
        $this->assertCount(50, iterator_to_array($client->orders(now()->subYear())));
        Http::assertSentCount(2);
    }

    public function test_repeated_or_malformed_api_pages_cannot_report_success(): void
    {
        foreach ([['status' => 'error'], ['results' => $this->products(50), 'next' => 'page2']] as $payload) {
            Http::fake(['*products/*' => Http::response($payload)]);
            try {
                iterator_to_array((new ZidClient($this->store))->products());
                $this->fail('Expected invalid pagination to fail.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Zid', $e->getMessage());
            }
        }
    }

    public function test_expiry_is_scoped_and_late_jobs_cannot_restart_a_failed_run(): void
    {
        $old = $this->store->syncRuns()->create(['type' => 'zid_sync', 'status' => 'running', 'started_at' => now()->subMinutes(20)]);
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other store']);
        $untouched = $other->syncRuns()->create(['type' => 'zid_sync', 'status' => 'running', 'started_at' => now()->subMinutes(20)]);
        $this->state->expire($this->store);
        $this->assertSame('failed', $old->fresh()->status);
        $this->assertSame('running', $untouched->fresh()->status);
        $queued = $this->state->enqueue($this->store);
        $this->travel(16)->minutes();
        $this->state->expire($this->store);
        $this->assertSame('queue_stalled', $queued->fresh()->error);
        $late = new SyncStoreData($this->store, runId: $queued->id);
        $late->chain([new CheckZidConnection($this->store->id)]);
        $this->app->call([$late, 'handle']);
        $this->assertSame([], $late->chained);
        Http::assertNothingSent();
        $this->assertSame('failed', $queued->fresh()->status);
    }

    public function test_failed_hook_uses_the_serialized_run_id_and_does_not_relabel_success(): void
    {
        $run = $this->state->enqueue($this->store);
        $freshJob = unserialize(serialize(new SyncStoreData($this->store, runId: $run->id)));
        $this->state->claim($run->id, $this->store);
        $freshJob->failed(new RuntimeException('Worker failure'));
        $this->assertSame('failed', $run->fresh()->status);
        $run->update(['status' => 'completed']);
        $freshJob->failed(new RuntimeException('Late notification failure'));
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_no_background_queue_reports_failure_instead_of_claiming_import_started(): void
    {
        config(['queue.default' => 'sync']);
        $run = $this->state->enqueue($this->store);
        $this->assertSame('queue_failed', $run->error);
        $this->assertSame('failed', $run->status);
        Bus::assertNothingDispatched();
    }

    public function test_page_limit_does_not_silently_truncate_an_import(): void
    {
        Http::fake(fn ($request) => Http::response(['results' => $this->products(1, (int) $request['page']), 'next' => 'more']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeded the page limit');
        iterator_to_array((new ZidClient($this->store))->products());
    }

    public function test_changing_the_connected_store_invalidates_queued_imports(): void
    {
        $run = $this->state->enqueue($this->store);
        $this->store->update(['platform_store_id' => '999']);
        try {
            $this->perform($run);
            $this->fail('A queued import must match the connected store.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connection changed', $e->getMessage());
        }
        $this->assertSame('failed', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_legacy_payload_without_run_id_cannot_start_a_parallel_import(): void
    {
        $this->state->enqueue($this->store);
        // Unserializing a pre-deployment job does not call its constructor.
        $legacy = (new \ReflectionClass(SyncStoreData::class))->newInstanceWithoutConstructor();
        $legacy->store = $this->store;
        $legacy->userId = null;
        $legacy->chain([new CheckZidConnection($this->store->id)]);
        $this->app->call([$legacy, 'handle']);
        $this->assertNull($legacy->runId);
        $this->assertSame([], $legacy->chained);
        $this->assertDatabaseCount('sync_runs', 1);
        Http::assertNothingSent();
    }

    public function test_batches_checkpoint_and_continue_in_a_fresh_job_without_rereading_pages(): void
    {
        $run = $this->state->enqueue($this->store);
        $pages = [];
        Http::fake(function ($request) use (&$pages) {
            if (str_contains($request->url(), 'products/')) {
                $page = (int) $request['page'];
                $pages[] = $page;

                return Http::response(['results' => $this->products(1, $page), 'next' => $page < 3 ? 'more' : null]);
            }

            return Http::response(['orders' => []]);
        });
        $first = new SyncStoreData($this->store, runId: $run->id);
        $first->chain([new CheckZidConnection($this->store->id)]);
        $this->app->call([$first, 'handle']);
        $this->assertSame([1, 2], $pages);
        $this->assertDatabaseCount('zid_sync_pages', 2);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame(3, $run->fresh()->stats['next_page']);
        $next = unserialize($first->chained[0]);
        $first->dispatchNextJobInChain();
        Bus::assertDispatched(SyncStoreData::class, fn ($job) => $job->step === 1 && $job->delay === 3 && count($job->chained) === 1);

        // A late failure from the previous payload cannot fail the new step.
        $first->failed(new RuntimeException('Late delivery'));
        $this->assertSame('running', $run->fresh()->status);
        $duplicate = new SyncStoreData($this->store, runId: $run->id);
        $this->app->call([$duplicate, 'handle']);
        $this->assertSame([1, 2], $pages);

        $this->app->call([$next, 'handle']);
        $this->assertSame([1, 2, 3], $pages);
        $this->assertSame('saving', $run->fresh()->stats['cursor_phase']);
        $this->assertDatabaseCount('products', 0);
        $save = unserialize($next->chained[0]);
        $this->app->call([$save, 'handle']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseCount('zid_sync_pages', 0);
    }

    public function test_429_waits_retry_after_and_resumes_the_same_page_without_losing_progress(): void
    {
        $run = $this->state->enqueue($this->store);
        $secondPageCalls = 0;
        $pages = [];
        Http::fake(function ($request) use (&$secondPageCalls, &$pages) {
            if (! str_contains($request->url(), 'products/')) {
                return Http::response(['orders' => [$this->order()]]);
            }
            $page = (int) $request['page'];
            $pages[] = $page;
            if ($page === 2 && ++$secondPageCalls === 1) {
                return Http::response([], 429, ['Retry-After' => '37']);
            }

            return Http::response(['results' => $this->products(1, $page - 1), 'next' => $page === 1 ? 'more' : null]);
        });
        $first = new SyncStoreData($this->store, runId: $run->id);
        $this->app->call([$first, 'handle']);
        $this->assertSame([1, 2], $pages, '429 must not trigger an immediate HTTP retry.');
        $this->assertSame('waiting', $run->fresh()->stats['phase']);
        $this->assertSame(1, $run->fresh()->stats['products_read']);
        $this->assertSame(2, $run->fresh()->stats['next_page']);
        $this->assertDatabaseCount('zid_sync_pages', 1);
        $next = unserialize($first->chained[0]);
        $this->assertSame(37, $next->delay);
        $this->travel(37)->seconds();
        $this->app->call([$next, 'handle']);
        $save = unserialize($next->chained[0]);
        $this->app->call([$save, 'handle']);
        $this->assertSame([1, 2, 2], $pages);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('products', 2);
        $this->assertSame(1, $run->fresh()->stats['orders']);
    }

    public function test_a_long_import_stays_active_while_batches_keep_progressing(): void
    {
        $run = $this->state->enqueue($this->store);
        $run = $this->state->claim($run->id, $this->store);
        $this->travel(20)->minutes();
        $this->state->progress($run, 'orders', ['orders_read' => 3000]);
        $this->state->expire($this->store);
        $this->assertSame('running', $run->fresh()->status);
        $this->travel(12)->minutes();
        $this->state->expire($this->store);
        $this->assertSame('worker_stopped', $run->fresh()->error);
    }

    public function test_zero_affected_rows_does_not_fail_an_identical_heartbeat(): void
    {
        $this->freezeTime();
        $run = $this->state->enqueue($this->store);
        $run = $this->state->claim($run->id, $this->store);
        $this->state->progress($run, 'products');

        // SQLite's result-count mode emulates MySQL/PDO returning zero for
        // an update that matched an active row but did not change its values.
        DB::statement('PRAGMA count_changes = ON');
        try {
            $this->assertSame(0, SyncRun::query()->whereKey($run->id)->update(['updated_at' => now()]));
            $this->state->progress($run, 'products');
            $this->assertSame('running', $run->fresh()->status);
        } finally {
            DB::statement('PRAGMA count_changes = OFF');
        }
        $this->state->fail($run->id, 'job_failed');
        $this->expectException(RuntimeException::class);
        $this->state->progress($run, 'products');
    }

    /** @return list<array<string, mixed>> */
    private function orders(int $from, int $to): array
    {
        $start = now()->subMonths(2)->startOfDay();

        return array_map(fn ($n) => [
            'id' => $n, 'created_at' => $start->copy()->addHours($n)->toDateTimeString(), 'status' => 'delivered',
            'products' => [['id' => 'real-1', 'quantity' => 1, 'total' => 10]],
        ], range($from, $to));
    }

    private function rejected(): PromiseInterface
    {
        return Http::response(['status' => 'validation_error', 'message' => [
            'type' => 'validation_errors', 'code' => 'ERROR_POPUP_OK', 'name' => 'المدخلات', 'description' => 'رقم الصفحة غير صالح',
        ]], 422);
    }

    public function test_rejected_deep_order_page_restarts_from_the_newest_order_and_counts_each_order_once(): void
    {
        $run = $this->state->enqueue($this->store);
        $windows = [];
        Http::fake(function ($request) use (&$windows) {
            if (str_contains($request->url(), 'products/')) {
                return Http::response(['results' => $this->products(), 'next' => null]);
            }
            $this->assertSame(ZidClient::ORDERS_PER_PAGE, (int) $request['per_page']);
            $windows[] = [$request['date_from'], (int) $request['page']];
            if (count($windows) === 1) {
                return Http::response(['orders' => $this->orders(1, 100)]);
            }
            if (count($windows) === 2) {
                return $this->rejected();
            }

            // The new window overlaps the last day already read.
            return Http::response(['orders' => $this->orders(91, 120)]);
        });
        $this->perform($run);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame([1, 2, 1], array_column($windows, 1));
        $this->assertSame($windows[0][0], $windows[1][0]);
        $this->assertTrue(Carbon::parse($windows[2][0])->gt(Carbon::parse($windows[0][0])));
        $this->assertSame(120, $run->stats['orders']);
        $this->assertSame(1, $run->stats['order_windows']);
        $this->assertSame(120, (int) DB::table('product_sales')->sum('units'));
    }

    public function test_failed_read_keeps_saved_pages_and_resumes_without_rereading_them(): void
    {
        $run = $this->state->enqueue($this->store);
        $requests = ['products' => 0, 'orders' => 0];
        $ordersFail = true;
        Http::fake(function ($request) use (&$requests, &$ordersFail) {
            if (str_contains($request->url(), 'products/')) {
                $requests['products']++;

                return Http::response(['results' => $this->products(), 'next' => null]);
            }
            $requests['orders']++;

            return $ordersFail ? $this->rejected() : Http::response(['orders' => $this->orders(1, 3)]);
        });
        try {
            $this->perform($run);
            $this->fail('Expected Zid to reject the order request.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 422', $e->getMessage());
        }
        $run->refresh();
        $this->assertSame('zid_rejected', $run->error);
        $this->assertSame('رقم الصفحة غير صالح', $run->stats['error_detail']);
        $this->assertDatabaseCount('zid_sync_pages', 1);
        $this->get('/store/settings/integration')->assertInertia(fn ($page) => $page
            ->where('sync.can_resume', true)->where('sync.error_detail', 'رقم الصفحة غير صالح'));

        $ordersFail = false;
        $this->post("/store/integrations/zid/sync/{$run->id}/resume")->assertRedirect();
        $this->assertSame('queued', $run->fresh()->status);
        $job = Bus::dispatched(SyncStoreData::class)->last();
        $this->perform($run, $job);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $requests['products'], 'Saved product pages must not be read again.');
        $this->assertSame(3, $run->stats['orders']);
        $this->assertDatabaseCount('zid_sync_pages', 0);

        // A completed run cannot be resumed again.
        $this->post("/store/integrations/zid/sync/{$run->id}/resume")->assertRedirect();
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_a_new_sync_discards_checkpoints_of_earlier_runs(): void
    {
        $old = $this->store->syncRuns()->create(['type' => 'zid_sync', 'status' => 'failed', 'error' => 'zid_rejected']);
        DB::table('zid_sync_pages')->insert(['sync_run_id' => $old->id, 'phase' => 'products', 'page' => 1, 'fingerprint' => 'x', 'rows' => '[]']);
        $this->state->enqueue($this->store);
        $this->assertDatabaseCount('zid_sync_pages', 0);
    }

    public function test_product_values_fit_their_columns(): void
    {
        $row = (new ZidMapper)->product([
            'id' => 7, 'name' => str_repeat('ب', 300), 'sku' => str_repeat('s', 300),
            'images' => [['image' => ['medium' => 'https://media.zid.store/'.str_repeat('%D8%A7', 50).'.jpg']]],
        ]);
        $this->assertSame(255, mb_strlen($row['name']));
        $this->assertSame(255, mb_strlen($row['sku']));
        $this->assertNull($row['image_url']);
    }
}
