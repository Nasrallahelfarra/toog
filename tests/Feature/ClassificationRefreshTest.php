<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\ReclassifyProducts;
use App\Jobs\SyncStoreData;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Analytics\ClassificationRefresh;
use App\Services\Analytics\PerformanceClassifier;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ClassificationRefreshTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['queue.default' => 'database', 'inertia.ssr.enabled' => false]);
        Queue::fake();
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Classification queue']);
        $this->actingAs($this->store->owner);
    }

    public function test_request_only_queues_work_and_repeated_clicks_join_it_from_both_pages(): void
    {
        $this->partialMock(PerformanceClassifier::class)->shouldNotReceive('classify');
        foreach (['/store/stagnant', '/store/performance'] as $page) {
            $this->from($page)->post('/store/performance/reclassify')->assertRedirect($page);
            $this->get($page)->assertInertia(fn (Assert $p) => $p->where('classification.status', 'queued'));
        }
        Queue::assertPushed(ReclassifyProducts::class, 1);
        $this->assertDatabaseCount('sync_runs', 1);
    }

    public function test_worker_updates_results_and_completion_is_available_to_partial_polling(): void
    {
        $product = $this->store->products()->create(['name' => 'Unsold', 'stock' => 50, 'features' => ['colors' => ['black']]]);
        ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $product->id, 'period' => now()->startOfMonth(), 'units' => 0]);
        $run = app(ClassificationRefresh::class)->start($this->store, $this->store->owner_id);
        $this->assertNull($product->fresh()->performance);
        app()->call([new ReclassifyProducts($run->id, $this->store->owner_id), 'handle']);
        $this->assertSame(Product::AT_RISK, $product->fresh()->performance);
        $this->assertSame(['colors' => ['black']], $product->fresh()->features);
        $this->get('/store/stagnant')->assertInertia(fn (Assert $page) => $page
            ->where('classification.status', 'completed')->where('classification.total', 1)
            ->where('summary.total', 1)->where('summary.at_risk', 1)->where('summary.stagnant', 0)
            ->where('summary.needs_reclassification', false)->reloadOnly('classification', fn (Assert $poll) => $poll
            ->where('classification.status', 'completed')->missing('products')));
        $this->assertDatabaseHas('activity_logs', ['action' => 'data.reclassified', 'user_id' => $this->store->owner_id]);
        $this->mock(PerformanceClassifier::class)->shouldNotReceive('classify');
        app()->call([new ReclassifyProducts($run->id), 'handle']);
        (new ReclassifyProducts($run->id))->failed(new RuntimeException('Late failure'));
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_inline_or_missing_queue_drivers_never_run_classification_inside_the_request(): void
    {
        $this->mock(PerformanceClassifier::class)->shouldNotReceive('classify');
        foreach (['sync', 'deferred', 'background', 'null', 'failover', 'missing'] as $driver) {
            config(['queue.default' => $driver]);
            $this->from('/store/stagnant')->post('/store/performance/reclassify')->assertRedirect();
            $this->assertSame('failed', SyncRun::latest('id')->first()->status);
            $this->assertSame('queue_unavailable', SyncRun::latest('id')->first()->error);
        }
        Queue::assertNothingPushed();
    }

    public function test_enqueue_does_not_wait_for_the_catalog_write_lock_and_worker_releases_until_available(): void
    {
        $lock = Cache::lock(SyncStoreData::lockKey($this->store), 600);
        $lock->get();
        $this->post('/store/performance/reclassify')->assertRedirect();
        $run = SyncRun::sole();
        $this->mock(PerformanceClassifier::class)->shouldNotReceive('classify');
        $worker = new ReclassifyProducts($run->id);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(15);
        $worker->setJob($queueJob);
        app()->call([$worker, 'handle']);
        $this->assertSame('queued', $run->fresh()->status);
        $lock->release();
    }

    public function test_failed_work_rolls_back_product_changes_and_allows_a_new_request(): void
    {
        $product = $this->store->products()->create(['name' => 'Existing', 'performance' => Product::AVERAGE]);
        $this->mock(PerformanceClassifier::class)->shouldReceive('classify')->once()->andReturnUsing(function () use ($product): void {
            $product->update(['performance' => Product::STAGNANT]);
            throw new RuntimeException('Write failed');
        });
        $service = app(ClassificationRefresh::class);
        $run = $service->start($this->store, null);
        $worker = new ReclassifyProducts($run->id);
        try {
            app()->call([$worker, 'handle']);
            $this->fail('Expected failure');
        } catch (RuntimeException $e) {
            $worker->failed($e);
        }
        $this->assertSame(Product::AVERAGE, $product->fresh()->performance);
        $this->assertSame('failed', $run->fresh()->status);
        $next = $service->start($this->store, null);
        $this->assertNotSame($run->id, $next->id);
        $worker->failed(new RuntimeException('Old delivery'));
        $this->assertSame('queued', $next->fresh()->status);
    }

    public function test_request_does_not_enqueue_another_catalog_writer_while_sales_sync_is_active(): void
    {
        $this->store->syncRuns()->create(['type' => 'zid_sync', 'status' => 'running']);
        $this->post('/store/performance/reclassify')->assertRedirect();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('sync_runs', 1);
    }

    public function test_abandoned_jobs_expire_and_other_stores_cannot_see_their_status(): void
    {
        $service = app(ClassificationRefresh::class);
        $run = $service->start($this->store, null);
        $this->travel(31)->minutes();
        $this->assertSame('failed', $service->latest($this->store)['status']);
        $this->mock(PerformanceClassifier::class)->shouldNotReceive('classify');
        app()->call([new ReclassifyProducts($run->id), 'handle']);
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $this->actingAs($other->owner)->get('/store/stagnant')->assertInertia(fn (Assert $p) => $p->where('classification', null));
    }

    public function test_large_catalog_is_saved_in_bounded_batches_without_touching_other_product_fields(): void
    {
        $rows = [];
        foreach (range(1, 3541) as $i) {
            $rows[] = ['store_id' => $this->store->id, 'name' => "Product {$i}", 'stock' => 1,
                'performance' => Product::WINNER, 'performance_score' => 99, 'features' => '{"colors":["black"]}'];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_starts_with(strtoupper($query->sql), 'UPDATE "PRODUCTS"')) {
                $updates++;
            }
        });
        app(PerformanceClassifier::class)->classify($this->store);
        $this->assertSame(36, $updates);
        $this->assertSame(3541, $this->store->products()->whereNull('performance')->count());
        $first = $this->store->products()->first();
        $this->assertSame('Product 1', $first->name);
        $this->assertSame(1, $first->stock);
        $this->assertSame(['colors' => ['black']], $first->features);
        app(PerformanceClassifier::class)->classify($this->store);
        $this->assertSame(36, $updates, 'Unchanged metrics should not be written again.');
    }
}
