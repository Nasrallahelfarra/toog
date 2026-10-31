<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\DetectTrends;
use App\Jobs\ScanCompetitor;
use App\Jobs\ScanCompetitors;
use App\Models\Competitor;
use App\Models\CompetitorScan;
use App\Models\User;
use App\Services\Ai\Ai;
use App\Services\Ai\AiJsonResult;
use App\Services\Market\CompetitorScanState;
use App\Services\Market\PublicWebClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetitorScanProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'mojo.ai.provider' => 'fake', 'inertia.ssr.enabled' => false]);
        Queue::fake();
        Http::preventStrayRequests();
        $this->partialMock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    }

    private function rival(): Competitor
    {
        $store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Store']);

        return $store->competitors()->create(['name' => 'Rival', 'platform' => 'website', 'url' => 'https://shop.example', 'is_active' => true]);
    }

    private function pages(): void
    {
        Http::fake([
            'shop.example/robots.txt' => Http::response('User-agent: *'),
            'https://shop.example' => Http::response('<script>Shopify.currency={"active":"SAR"};</script>'),
            'shop.example/products.json*' => Http::response(['products' => [
                ['id' => 1, 'handle' => 'shirt', 'title' => 'Cotton shirt', 'variants' => [['price' => '99']]],
            ]]),
        ]);
    }

    private function runScan(CompetitorScan $scan): void
    {
        app()->call([new ScanCompetitor($scan->id), 'handle']);
    }

    public function test_scan_request_returns_queued_progress_without_crawling_and_deduplicates_clicks(): void
    {
        $rival = $this->rival();
        foreach (range(1, 2) as $attempt) {
            $this->actingAs($rival->store->owner)->post('/store/competitors/'.$rival->id.'/scan')
                ->assertRedirect('/store/competitors?competitor='.$rival->id);
        }
        Queue::assertPushed(ScanCompetitor::class, 1);
        $this->assertDatabaseCount('competitor_scans', 1);
        Http::assertNothingSent();
        $this->get('/store/competitors?competitor='.$rival->id)->assertInertia(fn (Assert $page) => $page
            ->where('competitors.0.scan.status', 'queued')->where('competitors.0.scan.progress', 0)->where('selected.analysis', null));
    }

    public function test_worker_exposes_collected_facts_while_waiting_for_ai_then_finishes_the_report(): void
    {
        $this->pages();
        $rival = $this->rival();
        $scan = app(CompetitorScanState::class)->enqueue($rival);
        $this->mock(Ai::class, function ($mock) use ($rival, $scan): void {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('json')->once()->andReturnUsing(function () use ($rival, $scan) {
                $this->assertSame('running', $scan->fresh()->status);
                $this->assertSame('analysis', $scan->fresh()->stage);
                $this->assertSame(80, $scan->fresh()->progress);
                $this->assertSame(1, $rival->fresh()->analysis['facts']['products']);
                $this->assertSame('processing', $rival->fresh()->analysis['ai']['status']);

                return new AiJsonResult([], false, 0);
            });
        });
        $this->runScan($scan);
        $this->assertSame('completed', $scan->fresh()->status);
        $this->assertSame(100, $scan->fresh()->progress);
        $this->assertSame(['pages' => 2, 'products' => 1], $scan->fresh()->metrics);
        $this->assertSame('unavailable', $rival->fresh()->analysis['ai']['status']);
        $this->actingAs($rival->store->owner)->get('/store/competitors?competitor='.$rival->id)->assertInertia(fn (Assert $page) => $page
            ->where('competitors.0.scan.progress', 100)->where('selected.analysis.facts.products', 1)->has('selected.products', 1));
        // Redelivery of a completed job must not crawl again.
        $this->runScan($scan);
        $this->assertDatabaseCount('competitor_products', 1);
        Http::assertSentCount(3);
    }

    public function test_failed_crawl_keeps_previous_report_and_a_retry_creates_a_new_scan(): void
    {
        Http::fake(['shop.example/robots.txt' => Http::sequence()->push('User-agent: *')->push('Forbidden', 403)]);
        $this->pages();
        $rival = $this->rival();
        $state = app(CompetitorScanState::class);
        $this->runScan($state->enqueue($rival));
        $report = $rival->fresh()->analysis;
        $failed = $state->enqueue($rival);
        $this->runScan($failed);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('fetch_failed', $failed->fresh()->error_code);
        $this->assertNotEmpty($failed->fresh()->error);
        $this->assertSame($report, $rival->fresh()->analysis);
        $retry = $state->enqueue($rival);
        $this->assertNotSame($failed->id, $retry->id);
        $this->assertSame('queued', $retry->status);
    }

    public function test_scans_are_tenant_scoped_and_running_competitors_cannot_be_changed(): void
    {
        $mine = $this->rival();
        $other = $this->rival();
        app(CompetitorScanState::class)->enqueue($other);
        $this->actingAs($mine->store->owner)->post('/store/competitors/'.$other->id.'/scan')->assertNotFound();
        $this->get('/store/competitors?competitor='.$other->id)->assertInertia(fn (Assert $page) => $page
            ->has('competitors', 1)->where('competitors.0.scan', null)->where('selected', null));
        app(CompetitorScanState::class)->enqueue($mine);
        $this->delete('/store/competitors/'.$mine->id)->assertSessionHasErrors('scan');
        $this->put('/store/competitors/'.$mine->id, ['name' => 'Changed'])->assertSessionHasErrors('scan');
        $this->assertSame('Rival', $mine->fresh()->name);
    }

    public function test_stalled_workers_and_invalid_queue_config_produce_recoverable_failures(): void
    {
        $rival = $this->rival();
        $state = app(CompetitorScanState::class);
        $queued = $state->enqueue($rival);
        $this->travel(16)->minutes();
        $state->expire($rival->id);
        $this->assertSame('queue_timeout', $queued->fresh()->error_code);
        $this->runScan($queued);
        Http::assertNothingSent();

        $running = $state->enqueue($rival);
        $state->claim($running);
        $this->travel(12)->minutes();
        $state->expire($rival->id);
        $this->assertSame('timed_out', $running->fresh()->error_code);

        config(['queue.default' => 'sync']);
        $failed = $state->enqueue($rival);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('queue_failed', $failed->error_code);
    }

    public function test_scan_all_queues_each_active_competitor_once(): void
    {
        $this->pages();
        $rival = $this->rival();
        $rival->store->competitors()->create(['name' => 'Paused', 'platform' => 'website', 'url' => 'https://paused.example', 'is_active' => false]);
        $this->actingAs($rival->store->owner)->post('/store/competitors/scan')->assertRedirect();
        $this->post('/store/competitors/scan')->assertRedirect();
        Queue::assertPushed(ScanCompetitors::class, 1);
        Queue::assertNotPushed(ScanCompetitor::class);
        $this->assertDatabaseCount('competitor_scans', 1);
        app()->call([new ScanCompetitors($rival->store_id), 'handle']);
        $this->assertSame('completed', $rival->fresh()->latestScan->status);
        $this->assertDatabaseHas('sync_runs', ['store_id' => $rival->store_id, 'type' => 'competitor_scan', 'status' => 'completed']);
        Queue::assertPushed(DetectTrends::class, 1);
    }
}
