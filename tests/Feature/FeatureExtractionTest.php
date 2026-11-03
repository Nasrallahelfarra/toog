<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\ExtractProductFeatures;
use App\Jobs\ExtractStoreFeatures;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Ai\Ai;
use App\Services\Ai\AiJsonResult;
use App\Services\Catalog\FeatureExtraction;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        app()->call([new ExtractStoreFeatures($run->id, $this->store->owner->id, $run->fresh()->stats['step'] ?? 0), 'handle']);
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
        $this->assertSame(['total' => 120, 'done' => 0, 'extracted' => 0, 'cursor' => 0, 'step' => 0, 'step_claimed' => false], $run->stats);
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

    public function test_a_run_whose_job_left_the_queue_fails_with_the_worker_error_and_can_resume(): void
    {
        $this->products(2);
        $stale = app(FeatureExtraction::class)->start($this->store, null);
        $job = DB::table('jobs')->sole();
        DB::table('failed_jobs')->insert(['uuid' => 'x', 'connection' => 'database', 'queue' => 'default',
            'payload' => $job->payload, 'exception' => "RuntimeException: Gemini key rejected\n#0 trace", 'failed_at' => now()]);
        DB::table('jobs')->delete();

        // Within the grace period the run is still waiting for its job to be saved.
        $this->assertSame('queued', app(FeatureExtraction::class)->latest($this->store)['status']);
        SyncRun::query()->whereKey($stale->id)->update(['updated_at' => now()->subMinutes(3)]);
        $progress = app(FeatureExtraction::class)->latest($this->store);

        $this->assertSame('failed', $progress['status']);
        $this->assertSame('job_failed', $progress['error']);
        $this->assertSame('RuntimeException: Gemini key rejected', $progress['error_detail']);
        $retry = app(FeatureExtraction::class)->start($this->store, null);
        $this->assertSame($stale->id, $retry->id);
        $this->assertSame('queued', $retry->status);
        $this->assertSame(1, $retry->stats['step']);
        $this->assertNull($retry->stats['error_detail']);
    }

    public function test_resuming_a_legacy_run_preserves_178_of_3541_and_reads_only_remaining_products(): void
    {
        Queue::fake();
        $saved = ['colors' => ['black'], 'extracted_by' => 'ai', 'extracted_at' => now()->subHour()->toIso8601String()];
        $rows = [];
        foreach (range(1, 3541) as $i) {
            $rows[] = ['store_id' => $this->store->id, 'external_id' => "resume-{$i}",
                'name' => $i <= 178 ? "Saved {$i}" : "Remaining {$i}", 'features' => $i <= 178 ? json_encode($saved) : null];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        $cursor = $this->store->products()->where('external_id', 'resume-178')->value('id');
        $run = $this->store->syncRuns()->create(['type' => FeatureExtraction::TYPE, 'status' => 'failed',
            'stats' => ['total' => 3541, 'done' => 178, 'extracted' => 178, 'cursor' => $cursor],
            'error' => 'job_failed', 'started_at' => now()->subHour(), 'finished_at' => now()->subMinutes(10)]);

        $this->actingAs($this->store->owner)->post('/store/performance/extract-features')->assertRedirect();
        $this->get('/store/performance')->assertInertia(fn (Assert $page) => $page
            ->where('extraction.id', $run->id)->where('extraction.status', 'queued')
            ->where('extraction.total', 3541)->where('extraction.done', 178)->where('extraction.extracted', 178)
            ->where('extraction.resume_done', 178)->where('sync.missing_features', 3363));
        $this->assertSame(1, SyncRun::query()->count());
        $this->assertNotNull($run->fresh()->stats['resumed_at']);

        $this->mock(Ai::class, function ($mock): void {
            $mock->shouldReceive('json')->times(10)->withArgs(function (...$args): bool {
                $this->assertStringContainsString('Remaining ', $args[3]);

                return true;
            })->andReturn(new AiJsonResult(['colors' => ['white']], true, 0));
        });
        $this->work($run);
        $this->assertSame(188, $run->fresh()->stats['extracted']);
        $this->assertSame(3541, $run->fresh()->stats['total']);
        $this->assertSame($saved, $this->store->products()->where('external_id', 'resume-1')->first()->features);
    }

    public function test_short_batches_ignore_duplicate_delivery_and_late_failure_from_the_previous_step(): void
    {
        Queue::fake();
        $this->products(12);
        $this->mock(Ai::class)->shouldReceive('json')->times(12)
            ->andReturn(new AiJsonResult(['colors' => ['black']], true, 0));
        $run = app(FeatureExtraction::class)->start($this->store, null);
        $this->work($run);
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame(10, $run->fresh()->stats['done']);
        $this->assertSame(1, $run->fresh()->stats['step']);

        $old = new ExtractStoreFeatures($run->id);
        app()->call([$old, 'handle']);
        $old->failed(new MaxAttemptsExceededException('Too many attempts'));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame(10, $run->fresh()->stats['done']);

        $this->work($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(12, $run->fresh()->stats['extracted']);
        Queue::assertPushed(ExtractStoreFeatures::class, 2);
    }

    public function test_resume_retries_failed_products_before_the_cursor_but_skips_saved_products(): void
    {
        Queue::fake();
        $this->products(1, ['colors' => ['black'], 'pattern' => 'solid', 'extracted_by' => 'name']);
        $this->products(2, ['extracted_at' => now()->toIso8601String()]);
        $this->products(2);
        $run = $this->store->syncRuns()->create(['type' => FeatureExtraction::TYPE, 'status' => 'failed',
            'stats' => ['total' => 5, 'done' => 3, 'extracted' => 2, 'cursor' => $this->store->products()->max('id')],
            'finished_at' => now()]);
        $service = app(FeatureExtraction::class);
        $resumed = $service->start($this->store, null);
        $this->assertSame($run->id, $resumed->id);
        $this->assertSame(2, $resumed->stats['done']);
        $this->assertSame(5, $resumed->stats['total']);
        $this->mock(Ai::class)->shouldReceive('json')->times(3)
            ->andReturn(new AiJsonResult(['colors' => ['white']], true, 0));
        $this->work($resumed);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(5, $run->fresh()->stats['done']);
        $this->assertSame(5, $run->fresh()->stats['extracted']);
        $this->assertSame(0, $service->pending($this->store->id)->count());
    }

    public function test_late_results_and_failure_cannot_overwrite_a_resumed_run(): void
    {
        Queue::fake();
        $this->products(1);
        $service = app(FeatureExtraction::class);
        $run = $service->start($this->store, null);
        $old = $service->claim($run->id, 0);
        (new ExtractStoreFeatures($run->id))->failed(new MaxAttemptsExceededException('Too many attempts'));
        $this->assertSame('worker_stopped', $run->fresh()->error);
        $service->start($this->store, null);
        $service->start($this->store, null);

        $result = ['features' => ['extracted_at' => now()->toIso8601String()], 'answered' => true];
        $this->assertFalse($service->checkpoint($old, 0, $this->store->products()->first()->id, $result));
        (new ExtractStoreFeatures($run->id))->failed(new \RuntimeException('Late failure'));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertNull($run->fresh()->error);
        $this->assertSame(0, $run->fresh()->stats['done']);
        $this->assertNull($this->store->products()->first()->features);
        Queue::assertPushed(ExtractStoreFeatures::class, 2);
    }

    public function test_product_features_and_progress_are_saved_in_one_transaction(): void
    {
        Queue::fake();
        $this->products(1);
        $service = app(FeatureExtraction::class);
        $run = $service->start($this->store, null);
        $claimed = $service->claim($run->id, 0);
        Event::listen('eloquent.updating: '.SyncRun::class, function (SyncRun $saving): void {
            if (($saving->stats['done'] ?? 0) === 1) {
                throw new \RuntimeException('Checkpoint interrupted');
            }
        });
        try {
            $service->checkpoint($claimed, 0, $this->store->products()->first()->id,
                ['features' => ['extracted_at' => now()->toIso8601String()], 'answered' => true]);
            $this->fail('The interrupted checkpoint should throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Checkpoint interrupted', $e->getMessage());
        }
        $this->assertNull($this->store->products()->first()->features);
        $this->assertSame(0, $run->fresh()->stats['done']);
    }

    public function test_an_abandoned_reserved_job_can_resume_without_matching_the_old_job(): void
    {
        $this->products(1);
        $service = app(FeatureExtraction::class);
        $run = $service->start($this->store, null);
        DB::table('jobs')->update(['reserved_at' => now()->subMinutes(16)->timestamp, 'attempts' => 1]);
        SyncRun::query()->whereKey($run->id)->update(['status' => 'running', 'updated_at' => now()->subMinutes(16)]);
        $this->assertSame('failed', $service->latest($this->store)['status']);
        $service->start($this->store, null);
        $this->assertSame('queued', $run->fresh()->status);
        $progress = $service->latest($this->store);
        $this->assertTrue($progress['queue']['found']);
        $this->assertFalse($progress['queue']['reserved']);
        (new ExtractStoreFeatures($run->id))->failed(new MaxAttemptsExceededException('Late attempt'));
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_a_run_waiting_behind_other_jobs_reports_its_place_and_is_not_expired(): void
    {
        $this->products(2);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['displayName' => 'App\Jobs\SyncStoreData']),
            'attempts' => 1, 'reserved_at' => now()->subMinutes(5)->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['displayName' => 'App\Jobs\RunEvaluation']),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $run = app(FeatureExtraction::class)->start($this->store, null);
        SyncRun::query()->whereKey($run->id)->update(['updated_at' => now()->subMinutes(30)]);

        $progress = app(FeatureExtraction::class)->latest($this->store);

        $this->assertSame('queued', $progress['status']);
        $this->assertSame(['found' => true, 'reserved' => false, 'ahead' => 1, 'busy' => 'SyncStoreData'],
            array_diff_key($progress['queue'], ['busy_since' => true]));
    }

    public function test_worker_failure_closes_the_run_and_old_queued_batches_skip_their_products(): void
    {
        Queue::fake();
        $this->products(1);
        $run = app(FeatureExtraction::class)->start($this->store, null);
        (new ExtractStoreFeatures($run->id))->failed(new \RuntimeException('Job timed out'));
        $this->assertSame('job_failed', $run->fresh()->error);
        $this->assertSame('Job timed out', $run->fresh()->stats['error_detail']);

        $this->mock(Ai::class)->shouldNotReceive('json');
        $legacy = new ExtractProductFeatures($this->store->products()->first());
        $legacy->setJob(\Mockery::mock(Job::class));
        $this->assertFalse(app()->call([$legacy, 'handle']));
        $this->assertNull($this->store->products()->first()->features);
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
