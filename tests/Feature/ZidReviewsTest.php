<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\AnalyzeReviews;
use App\Jobs\ImportZidReviews;
use App\Models\CustomerReview;
use App\Models\Product;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Zid\ZidReviewImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Customer reviews come from the store's own Zid reviews: seeded samples
 * are never shown in a connected store, re-imports update in place and
 * an edited comment is analysed again.
 */
class ZidReviewsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $abaya;

    /** @var list<array<string, mixed>> */
    private array $zidReviews = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'fake']);
        Http::preventStrayRequests();
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Dar Lama']);
        $this->store->update([
            'platform' => 'zid', 'platform_store_id' => '41960', 'connection_status' => 'connected',
            'zid_authorization' => 'app-token', 'zid_access_token' => 'manager-token',
        ]);
        $this->abaya = $this->store->products()->create(['name' => 'عباية كريب سوداء', 'external_id' => 'A1B2C3D4-0000-4000-8000-000000000001']);

        $this->zidReviews = [
            $this->zidReview('r-1', 'العباية فخمة والقماش ثقيل، أنصح فيها', 5, 'approved', 'a1b2c3d4000040008000000000000001', true),
            $this->zidReview('r-2', 'Sizes run small', 3, 'pending', 'unknown-product', false),
            $this->zidReview('r-3', 'اربح جوال مجانا اضغط الرابط', 5, 'spam', 'unknown-product', false),
            $this->zidReview('r-4', '', 4, 'approved', 'unknown-product', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function zidReview(string $id, string $comment, int $rating, string $status, string $productId, bool $bought): array
    {
        return [
            'id' => $id, 'customer' => ['id' => 77, 'name' => 'نورة'], 'status' => $status, 'is_anonymous' => false,
            'rating' => $rating, 'comment' => $comment, 'edit_requested' => false, 'original_snapshot' => null, 'reply' => null,
            'images' => [], 'created_at' => '2026-11-01 09:30:00', 'updated_at' => '2026-11-01 09:30:00',
            'product' => ['id' => $productId, 'name' => 'عباية كريب سوداء', 'bought_this_item' => $bought, 'image' => null],
        ];
    }

    private function fakeZid(int $status = 200): void
    {
        Http::fake(function (Request $request) use ($status) {
            $this->assertStringContainsString('/managers/store/reviews/product', $request->url());
            $this->assertSame('manager-token', $request->header('X-Manager-Token')[0] ?? null);

            return $status === 200
                ? Http::response(['status' => 'object', 'reviews' => $this->zidReviews,
                    'pagination' => ['page' => 1, 'next_page' => null, 'last_page' => 1, 'result_count' => count($this->zidReviews)]])
                : Http::response(['status' => 'error', 'message' => ['type' => 'error', 'code' => 'ERROR_SESSION_INVALID', 'name' => null, 'description' => null]], $status);
        });
    }

    private function sample(): CustomerReview
    {
        return CustomerReview::query()->create(['store_id' => $this->store->id, 'source' => 'competitor', 'text' => 'ما عجبني القصة الواسعة',
            'rating' => 2, 'sentiment' => 'negative', 'analyzed_at' => now()]);
    }

    public function test_real_reviews_replace_the_samples_and_link_to_their_product(): void
    {
        $sample = $this->sample();
        $this->fakeZid();

        $stats = app(ZidReviewImporter::class)->import($this->store);

        $this->assertSame(['fetched' => 4, 'created' => 2, 'updated' => 0, 'skipped' => 2], $stats);
        $this->assertModelMissing($sample);

        $review = CustomerReview::query()->where('external_id', 'r1')->sole();
        $this->assertSame('store', $review->source);
        $this->assertSame($this->abaya->id, $review->product_id);
        $this->assertSame('ar', $review->language);
        $this->assertSame(5, $review->rating);
        $this->assertTrue($review->evidence['verified_purchase']);
        $this->assertSame('2026-11-01', $review->reviewed_at->toDateString());
        $this->assertNull($review->analyzed_at);
        // The customer's name is never kept.
        $this->assertStringNotContainsString('نورة', json_encode($review->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('en', CustomerReview::query()->where('external_id', 'r2')->value('language'));
    }

    public function test_a_re_import_updates_in_place_and_reanalyses_only_edited_comments(): void
    {
        $this->fakeZid();
        app(ZidReviewImporter::class)->import($this->store);
        CustomerReview::query()->update(['sentiment' => 'positive', 'analyzed_at' => now()]);

        $this->zidReviews[0]['comment'] = 'العباية فخمة لكن المقاس صغير';
        $stats = app(ZidReviewImporter::class)->import($this->store);

        $this->assertSame(2, $stats['updated']);
        $this->assertSame(2, CustomerReview::query()->count());
        $this->assertNull(CustomerReview::query()->where('external_id', 'r1')->value('analyzed_at'));
        $this->assertNotNull(CustomerReview::query()->where('external_id', 'r2')->value('analyzed_at'));
    }

    public function test_the_job_records_its_run_and_queues_the_analysis(): void
    {
        $this->fakeZid();
        Queue::fake([AnalyzeReviews::class]);

        (new ImportZidReviews($this->store->id))->handle(app(ZidReviewImporter::class));

        $run = SyncRun::query()->where('store_id', $this->store->id)->where('type', 'zid_reviews')->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->stats['created']);
        Queue::assertPushed(AnalyzeReviews::class);
    }

    public function test_a_refused_read_is_reported_and_changes_nothing(): void
    {
        $sample = $this->sample();
        $this->fakeZid(403);

        (new ImportZidReviews($this->store->id))->handle(app(ZidReviewImporter::class));

        $run = SyncRun::query()->where('store_id', $this->store->id)->where('type', 'zid_reviews')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('authorization_failed', $run->error);
        $this->assertModelExists($sample);
    }

    public function test_a_connected_store_never_shows_sample_reviews(): void
    {
        $this->sample();
        Queue::fake();
        $owner = $this->store->owner;

        $this->actingAs($owner)->get('/store/market')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reviews.source', 'zid')
                ->where('reviews.total', 0)
                ->where('reviews.sync.status', 'queued'));
        // The first visit reads the reviews once.
        Queue::assertPushed(ImportZidReviews::class, 1);
    }

    public function test_the_merchant_can_ask_for_a_fresh_read(): void
    {
        Queue::fake();

        $this->actingAs($this->store->owner)->post('/store/market/reviews/sync')->assertRedirect();

        Queue::assertPushed(ImportZidReviews::class, fn (ImportZidReviews $job) => $job->storeId === $this->store->id);
    }

    public function test_a_sample_store_keeps_its_samples_and_cannot_read_from_zid(): void
    {
        $this->store->update(['zid_access_token' => null, 'zid_authorization' => null, 'connection_status' => 'disconnected']);
        $this->sample();
        Queue::fake();
        $owner = $this->store->owner;

        $this->actingAs($owner)->get('/store/market')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reviews.source', 'sample')
                ->where('reviews.total', 1)
                ->where('reviews.sync', null));

        $this->post('/store/market/reviews/sync')->assertRedirect();
        Queue::assertNotPushed(ImportZidReviews::class);
    }
}
