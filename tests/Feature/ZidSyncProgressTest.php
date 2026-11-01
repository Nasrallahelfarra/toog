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
use App\Services\Zid\ZidSyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function perform(SyncRun $run): void
    {
        $this->app->call([new SyncStoreData($this->store, runId: $run->id), 'handle']);
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
        $this->assertSame('import_failed', $run->fresh()->error);
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
}
