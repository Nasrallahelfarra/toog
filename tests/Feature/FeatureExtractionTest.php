<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\ExtractStoreFeatures;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Ai\Ai;
use App\Services\Ai\AiJsonResult;
use App\Services\Catalog\FeatureExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeatureExtractionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'mojo.ai.provider' => 'fake', 'inertia.ssr.enabled' => false]);
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Store']);
    }

    private function products(int $count, ?array $features = null): void
    {
        foreach (range(1, $count) as $i) {
            $this->store->products()->create(['name' => "Product {$i}", 'external_id' => uniqid(), 'features' => $features]);
        }
    }

    private function work(SyncRun $run): void
    {
        app()->call([new ExtractStoreFeatures($run->id, $this->store->owner->id), 'handle']);
    }

    /** A live model that answers $answer for every product. */
    private function model(array $answer, bool $fromModel = true): void
    {
        $this->mock(Ai::class, function ($mock) use ($answer, $fromModel): void {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('json')->andReturn(new AiJsonResult($answer, $fromModel, 0));
        });
    }

    public function test_one_click_queues_the_whole_catalog_and_repeat_clicks_join_the_run(): void
    {
        Queue::fake();
        $this->products(120);
        $this->products(1, ['colors' => ['black'], 'pattern' => 'solid']);

        foreach (range(1, 2) as $click) {
            $this->actingAs($this->store->owner)->post('/store/performance/extract-features')->assertRedirect();
        }

        Queue::assertPushed(ExtractStoreFeatures::class, 1);
        $run = SyncRun::query()->sole();
        $this->assertSame(['total' => 120, 'done' => 0, 'extracted' => 0, 'cursor' => 0], $run->stats);
        $this->get('/store/performance')->assertInertia(fn (Assert $page) => $page
            ->where('sync.missing_features', 120)->where('extraction.status', 'queued')->where('extraction.total', 120));
    }

    public function test_products_the_model_read_leave_the_queue_even_without_a_pattern(): void
    {
        config(['queue.default' => 'sync']);
        $this->products(3);
        $this->model(['colors' => ['black'], 'pattern' => null]);
        $run = app(FeatureExtraction::class)->start($this->store, null);

        $this->assertSame('completed', $run->status);
        $this->assertSame(3, $run->stats['extracted']);
        $product = $this->store->products()->first();
        $this->assertSame(['black'], $product->features['colors']);
        $this->assertSame('ai', $product->features['extracted_by']);
        $this->assertSame(0, app(FeatureExtraction::class)->pending($this->store->id)->count());
        $this->assertNull(app(FeatureExtraction::class)->start($this->store, null));
    }

    public function test_run_stops_when_the_ai_keeps_failing_and_keeps_products_for_a_retry(): void
    {
        Queue::fake();
        $this->products(8);
        $this->model([], fromModel: false);
        $run = app(FeatureExtraction::class)->start($this->store, null);
        $this->work($run);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('ai_unavailable', $run->error);
        $this->assertSame(5, $run->stats['done']);
        $this->assertSame(0, $run->stats['extracted']);
        $this->assertSame(8, app(FeatureExtraction::class)->pending($this->store->id)->count());
        $this->actingAs($this->store->owner)->get('/store/performance')->assertInertia(fn (Assert $page) => $page
            ->where('sync.missing_features', 8)->where('extraction.error', 'ai_unavailable'));
    }

    public function test_run_stops_at_the_store_ai_budget(): void
    {
        Queue::fake();
        $this->products(2);
        $this->store->update(['ai_monthly_budget' => 0]);
        $this->model(['colors' => ['black'], 'pattern' => 'solid']);
        $run = app(FeatureExtraction::class)->start($this->store, null);
        $this->work($run);

        $this->assertSame('budget_exceeded', $run->fresh()->error);
        $this->assertSame(2, app(FeatureExtraction::class)->pending($this->store->id)->count());
    }

    public function test_a_stalled_run_expires_so_extraction_can_start_again(): void
    {
        Queue::fake();
        $this->products(2);
        $stale = app(FeatureExtraction::class)->start($this->store, null);
        SyncRun::query()->whereKey($stale->id)->update(['updated_at' => now()->subMinutes(20)]);

        $retry = app(FeatureExtraction::class)->start($this->store, null);

        $this->assertSame('timed_out', $stale->fresh()->error);
        $this->assertNotSame($stale->id, $retry->id);
        $this->assertSame('queued', $retry->status);
    }

    public function test_progress_names_the_product_being_read_and_the_panel_hides_a_day_after(): void
    {
        Queue::fake();
        $this->products(1);
        $run = app(FeatureExtraction::class)->start($this->store, null);
        $this->mock(Ai::class, function ($mock): void {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('json')->once()->andReturnUsing(function () {
                $progress = app(FeatureExtraction::class)->latest($this->store);
                $this->assertSame('running', $progress['status']);
                $this->assertSame('Product 1', $progress['current']);
                $this->assertTrue($progress['recent']);

                return new AiJsonResult(['colors' => ['black'], 'pattern' => 'solid'], true, 0);
            });
        });
        $this->work($run);

        $this->actingAs($this->store->owner)->get('/store/performance')->assertInertia(fn (Assert $page) => $page
            ->where('extraction.status', 'completed')->where('extraction.done', 1)->where('extraction.current', null)
            ->where('extraction.recent', true)->where('sync.missing_features', 0));

        SyncRun::query()->whereKey($run->id)->update(['finished_at' => now()->subDays(2)]);
        $this->get('/store/performance')->assertInertia(fn (Assert $page) => $page->where('extraction.recent', false));
    }
}
