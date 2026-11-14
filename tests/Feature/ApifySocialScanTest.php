<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\DetectTrends;
use App\Jobs\ScanCompetitor;
use App\Models\Competitor;
use App\Models\CompetitorScan;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Ai;
use App\Services\Ai\AiJsonResult;
use App\Services\Market\CompetitorMonitor;
use App\Services\Market\PublicWebClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/** Competitors' social posts read through Apify; websites keep their crawler. */
class ApifySocialScanTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const ACTS = 'https://api.apify.com/v2/acts/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mojo.ai.provider' => 'fake', 'mojo.apify.token' => 'apify-test-token', 'mojo.apify.posts_limit' => 30]);
        Storage::fake('public');
        $this->partialMock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    }

    /** @return array{0: User, 1: Store} */
    private function ownerWithStore(): array
    {
        $owner = User::factory()->create();

        return [$owner, app(CreateStore::class)->handle($owner, ['name' => 'Store'])];
    }

    private function competitor(Store $store, string $platform, string $handle = 'abaya.rival', string $name = 'Rival'): Competitor
    {
        return Competitor::query()->create(['store_id' => $store->id, 'name' => $name, 'platform' => $platform, 'handle' => $handle]);
    }

    /** @return list<array<string, mixed>> */
    private function instagramPosts(int $likes = 900): array
    {
        return [
            ['id' => '111', 'shortCode' => 'AbC', 'type' => 'Video', 'caption' => 'عباية سوداء مطرزة #عبايات #رمضان',
                'url' => 'https://www.instagram.com/p/AbC/', 'displayUrl' => 'https://scontent.cdninstagram.com/v/abc.jpg',
                'likesCount' => $likes, 'commentsCount' => 100, 'videoPlayCount' => 20000, 'timestamp' => now()->subDays(2)->toIso8601String(),
                'hashtags' => ['عبايات', 'رمضان']],
            ['id' => '222', 'shortCode' => 'DeF', 'type' => 'Sidecar', 'caption' => 'Beige linen abaya',
                'url' => 'https://www.instagram.com/p/DeF/', 'displayUrl' => 'https://scontent.cdninstagram.com/v/def.jpg',
                'likesCount' => -1, 'commentsCount' => 50, 'timestamp' => now()->subDays(5)->toIso8601String(), 'hashtags' => []],
        ];
    }

    /** One Instagram scan per like count, in order. */
    private function fakeInstagram(int ...$likes): void
    {
        $posts = Http::sequence();
        foreach ($likes ?: [900] as $count) {
            $posts->push($this->instagramPosts($count));
        }
        Http::fake([
            self::ACTS.'apify~instagram-profile-scraper/*' => Http::response([['username' => 'abaya.rival', 'followersCount' => 10000]]),
            self::ACTS.'apify~instagram-post-scraper/*' => $posts,
            'scontent.cdninstagram.com/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_instagram_posts_are_read_through_apify_and_rescans_update_them(): void
    {
        [, $store] = $this->ownerWithStore();
        $competitor = $this->competitor($store, 'instagram');
        $this->fakeInstagram(900, 1900);

        $result = app(CompetitorMonitor::class)->scan($competitor);

        $this->assertSame('ok', $result->status);
        $this->assertSame(2, $result->added);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'apify~instagram-post-scraper/run-sync-get-dataset-items')
            && $r->hasHeader('Authorization', 'Bearer apify-test-token')
            && str_contains($r->url(), 'maxItems=30')
            && $r['username'] === ['abaya.rival'] && $r['resultsLimit'] === 30
            && $r['onlyPostsNewerThan'] === now()->subDays(90)->toDateString());

        $video = SocialPost::query()->where('external_id', '111')->sole();
        $this->assertSame($competitor->id, $video->competitor_id);
        $this->assertSame(20000, $video->views);
        // (900 likes + 100 comments) / 10,000 followers.
        $this->assertSame(10.0, $video->measuredEngagement());
        $this->assertSame('followers', $video->evidence['engagement_basis']);
        $this->assertSame('video', $video->evidence['type']);
        $this->assertSame(['عبايات', 'رمضان'], $video->evidence['hashtags']);
        $this->assertSame('ramadan', $video->features['season']);
        Storage::disk('public')->assertExists($video->evidence['image_path']);

        $hidden = SocialPost::query()->where('external_id', '222')->sole();
        $this->assertTrue($hidden->evidence['likes_hidden']);
        $this->assertSame(0, $hidden->likes);
        $this->assertSame('carousel', $hidden->evidence['type']);

        $analysis = $competitor->fresh()->analysis;
        $this->assertSame('social', $analysis['kind']);
        $this->assertSame(2, $analysis['facts']['posts']);
        $this->assertSame(10000, $analysis['facts']['followers']);
        $this->assertSame([$video->id, $hidden->id], $analysis['facts']['top_post_ids']);
        $this->assertSame('not_configured', $analysis['ai']['status']);
        $this->assertSame('completed', CompetitorScan::query()->where('competitor_id', $competitor->id)->latest('id')->first()->status);

        // A rescan updates the same posts, keeps their images and adds none.
        $again = app(CompetitorMonitor::class)->scan($competitor);
        $this->assertSame([0, 2], [$again->added, $again->updated]);
        $this->assertSame(2, SocialPost::query()->where('competitor_id', $competitor->id)->count());
        $this->assertSame(20.0, $video->fresh()->measuredEngagement());
    }

    public function test_tiktok_and_facebook_items_are_mapped_with_an_honest_engagement_basis(): void
    {
        [, $store] = $this->ownerWithStore();
        $tiktok = $this->competitor($store, 'tiktok', '@lamsat.sa');
        $facebook = $this->competitor($store, 'facebook', 'https://www.facebook.com/rival.page/');
        Http::fake([
            self::ACTS.'clockworks~tiktok-scraper/*' => Http::response([
                ['id' => '7300', 'text' => 'فستان سهرة وردي', 'webVideoUrl' => 'https://www.tiktok.com/@lamsat.sa/video/7300',
                    'createTimeISO' => now()->subDay()->toIso8601String(), 'playCount' => 50000, 'diggCount' => 4000,
                    'commentCount' => 500, 'shareCount' => 500, 'hashtags' => [['name' => 'فساتين']],
                    'authorMeta' => ['name' => 'lamsat.sa', 'fans' => 100000], 'videoMeta' => ['coverUrl' => 'https://p16.tiktokcdn.com/cover.jpg']],
            ]),
            self::ACTS.'apify~facebook-posts-scraper/*' => Http::response([
                ['postId' => 'fb1', 'url' => 'https://www.facebook.com/rival.page/posts/fb1', 'text' => 'New collection',
                    'time' => now()->subDays(3)->toIso8601String(), 'likes' => 40, 'comments' => 5, 'shares' => 2],
                ['postId' => 'fb2', 'url' => 'https://www.facebook.com/rival.page/videos/fb2', 'text' => 'Try-on video',
                    'time' => now()->subDays(4)->toIso8601String(), 'likes' => 60, 'comments' => 20, 'shares' => 20, 'viewsCount' => 1000],
            ]),
            '*' => Http::response('', 404),
        ]);

        app(CompetitorMonitor::class)->scan($tiktok);
        app(CompetitorMonitor::class)->scan($facebook);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'clockworks~tiktok-scraper') && $r['profiles'] === ['lamsat.sa']
            && $r['shouldDownloadVideos'] === false);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'facebook-posts-scraper')
            && $r['startUrls'] === [['url' => 'https://www.facebook.com/rival.page']]);

        $video = SocialPost::query()->where('external_id', '7300')->sole();
        $this->assertSame([50000, 4000, 500, 500], [$video->views, $video->likes, $video->comments, $video->shares]);
        $this->assertSame(5.0, $video->measuredEngagement());
        $this->assertSame(['فساتين'], $video->evidence['hashtags']);
        $this->assertNull($video->evidence['image_path']);

        // Without followers or views there is nothing to measure against.
        $this->assertNull(SocialPost::query()->where('external_id', 'fb1')->sole()->measuredEngagement());
        $this->assertSame(10.0, SocialPost::query()->where('external_id', 'fb2')->sole()->measuredEngagement());
        $this->assertEquals(10.0, $facebook->fresh()->analysis['facts']['avg_engagement']);
        $this->assertSame(1, $facebook->fresh()->analysis['facts']['measured_posts']);
    }

    public function test_without_a_token_social_competitors_are_listed_but_not_scanned(): void
    {
        config(['mojo.apify.token' => null]);
        Http::fake();
        [$owner, $store] = $this->ownerWithStore();
        $competitor = $this->competitor($store, 'instagram');
        $snapchat = $this->competitor($store, 'snapchat', 'riyadh.style', 'Snap');

        app(CompetitorMonitor::class)->scan($competitor);

        Http::assertNothingSent();
        $this->assertSame('needs_apify', CompetitorScan::query()->where('competitor_id', $competitor->id)->sole()->error_code);
        $this->assertNull($competitor->fresh()->last_checked_at);
        $this->actingAs($owner)->get('/store/competitors')->assertInertia(fn (Assert $page) => $page
            ->where('competitors.0.auto_scan', false)->where('competitors.0.scan_blocker', 'needs_apify')
            ->where('competitors.0.profile_url', 'https://www.instagram.com/abaya.rival/')
            ->where('competitors.1.id', $snapchat->id)->where('competitors.1.scan_blocker', 'needs_api_access'));
    }

    public function test_apify_failures_are_reported_with_a_reason_the_merchant_can_act_on(): void
    {
        [, $store] = $this->ownerWithStore();
        $competitor = $this->competitor($store, 'tiktok');
        Http::fake([self::ACTS.'*' => Http::sequence()
            ->push(['error' => ['message' => 'Not enough usage credit']], 402)
            ->push([['url' => 'https://www.tiktok.com/@abaya.rival', 'error' => 'This profile is private']])]);

        app(CompetitorMonitor::class)->scan($competitor);
        $scan = CompetitorScan::query()->where('competitor_id', $competitor->id)->latest('id')->first();
        $this->assertSame('apify_payment', $scan->error_code);
        $this->assertStringContainsString('no credit', $competitor->fresh()->last_error);

        app(CompetitorMonitor::class)->scan($competitor);
        $scan = CompetitorScan::query()->where('competitor_id', $competitor->id)->latest('id')->first();
        $this->assertSame('no_posts', $scan->error_code);
        $this->assertStringContainsString('This profile is private', $scan->error);
        $this->assertSame(0, SocialPost::query()->count());
    }

    public function test_the_store_chooses_how_many_latest_posts_a_scan_reads(): void
    {
        config(['mojo.apify.posts_limit' => 15]);
        [$owner, $store] = $this->ownerWithStore();
        $this->actingAs($owner)->get('/store/competitors')->assertInertia(fn (Assert $page) => $page
            ->where('socialPosts.limit', 15)->where('socialPosts.max', 100));

        $this->put('/store/competitors/social-settings', ['posts_limit' => 0])->assertSessionHasErrors('posts_limit');
        $this->put('/store/competitors/social-settings', ['posts_limit' => 101])->assertSessionHasErrors('posts_limit');
        $this->put('/store/competitors/social-settings', ['posts_limit' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, $store->fresh()->socialPostsLimit());
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.updated']);

        $competitor = $this->competitor($store, 'instagram');
        $this->fakeInstagram();
        app(CompetitorMonitor::class)->scan($competitor);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'instagram-post-scraper')
            && str_contains($r->url(), 'maxItems=1') && $r['resultsLimit'] === 1);
        // Apify may still return more; only the latest one is kept.
        $this->assertSame(1, SocialPost::query()->where('competitor_id', $competitor->id)->count());
        $this->assertTrue($competitor->fresh()->analysis['crawl']['limited']);
    }

    public function test_a_batch_scan_hands_each_social_account_its_own_job(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        [, $store] = $this->ownerWithStore();
        $competitor = $this->competitor($store, 'instagram');
        $this->fakeInstagram();

        $totals = app(CompetitorMonitor::class)->scanStore($store);

        $this->assertSame(1, $totals['queued']);
        $scan = CompetitorScan::query()->where('competitor_id', $competitor->id)->sole();
        $this->assertSame('queued', $scan->status);
        Queue::assertPushed(ScanCompetitor::class, fn (ScanCompetitor $job) => $job->scanId === $scan->id);
        Http::assertNothingSent();

        app()->call([new ScanCompetitor($scan->id), 'handle']);
        $this->assertSame('completed', $scan->fresh()->status);
        $this->assertSame(2, SocialPost::query()->where('competitor_id', $competitor->id)->count());
        Queue::assertPushed(DetectTrends::class, 1);
    }

    public function test_gemini_reads_the_posts_and_insights_must_cite_them(): void
    {
        [, $store] = $this->ownerWithStore();
        $competitor = $this->competitor($store, 'instagram');
        $this->fakeInstagram();
        $ai = Mockery::mock(Ai::class);
        $ai->shouldReceive('isLive')->andReturn(true);
        $ai->shouldReceive('json')->once()->withArgs(function ($operation, $store, $system, $prompt, $schema, $fallback, $images) {
            $data = json_decode($prompt, true);
            $this->assertSame('competitor_social_analysis', $operation);
            $this->assertStringContainsString('silhouette, cut, colour, pattern, embroidery', $system);
            $this->assertCount(2, $data['posts']);
            $this->assertCount(2, $images);
            $this->assertSame('image/png', $images[0]['mime']);

            return true;
        })->andReturnUsing(fn () => new AiJsonResult(['summary' => 'العبايات المطرزة بالفيديو تتصدر التفاعل', 'insights' => [
            ['title' => 'الفيديو', 'description' => 'منشور الفيديو الأعلى تفاعلًا', 'post_ids' => [SocialPost::query()->where('external_id', '111')->value('id')]],
            ['title' => 'مختلق', 'description' => 'يشير لمنشور غير موجود', 'post_ids' => [999]],
        ]], true, 0.01));
        $this->app->instance(Ai::class, $ai);

        app(CompetitorMonitor::class)->scan($competitor);

        $analysis = $competitor->fresh()->analysis['ai'];
        $this->assertSame('completed', $analysis['status']);
        $this->assertSame('العبايات المطرزة بالفيديو تتصدر التفاعل', $analysis['summary']);
        $this->assertCount(1, $analysis['insights']);
    }

    public function test_workspace_shows_this_scans_posts_and_handles_are_normalised(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->actingAs($owner)->post('/store/competitors', [
            'name' => 'Rival', 'platform' => 'instagram', 'handle' => 'https://www.instagram.com/abaya.rival/?hl=ar', 'is_active' => true,
        ])->assertRedirect();
        $competitor = $store->competitors()->sole();
        $this->assertSame('abaya.rival', $competitor->handle);
        SocialPost::query()->create(['store_id' => $store->id, 'competitor_id' => $competitor->id, 'platform' => 'instagram',
            'caption' => 'Old post from an earlier scan', 'posted_at' => now()->subYear(), 'evidence' => ['observed_at' => 'old', 'engagement_basis' => null]]);
        $this->fakeInstagram();

        app(CompetitorMonitor::class)->scan($competitor);

        $this->get("/store/competitors?competitor={$competitor->id}")->assertInertia(fn (Assert $page) => $page
            ->where('selected.analysis.kind', 'social')
            ->has('selected.posts', 2)
            ->where('selected.posts.0.engagement_rate', fn ($rate) => (float) $rate === 10.0)
            ->where('selected.posts.0.thumbnail_url', fn ($url) => str_contains($url, '/storage/social-posts/'))
            ->where('selected.posts.1.likes_hidden', true));
        // The market page reads the real scan only: the year-old post is out of its window.
        $this->get('/store/market')->assertInertia(fn (Assert $page) => $page
            ->where('board.basis', 'live')
            ->where('board.coverage.posts', 2));
    }
}
