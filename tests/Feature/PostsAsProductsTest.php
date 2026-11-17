<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Competitor;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\User;
use App\Services\Market\MarketTrends;
use App\Services\Market\PostLookReader;
use App\Services\Market\PostTagger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Competitors' posts are pictures of their products: each picture is read
 * once for the product it shows, and posts then count as products in the
 * market trends even without a competitor store.
 */
class PostsAsProductsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Competitor $account;

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'fake']);
        Storage::fake('public');
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Barlina']);
        foreach (['فستان سهرة أسود', 'فستان سهرة عنابي', 'فستان ناعم بيج'] as $i => $name) {
            $this->store->products()->create(['name' => $name, 'external_id' => "p{$i}", 'category' => 'فساتين سهرة', 'stock' => 5,
                'features' => ['colors' => [['black', 'burgundy', 'beige'][$i]], 'pattern' => 'solid']]);
        }
        $this->account = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Sadeim', 'platform' => 'instagram', 'handle' => 'sadeimfashion', 'is_active' => true]);
    }

    private function socialPost(int $likes, ?array $look = null): SocialPost
    {
        $image = imagecreatetruecolor(20, 30);
        ob_start();
        imagepng($image);
        $path = 'social-posts/'.uniqid().'.png';
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return SocialPost::query()->create(['store_id' => $this->store->id, 'competitor_id' => $this->account->id, 'external_id' => uniqid(), 'platform' => 'instagram',
            'caption' => 'جديدنا ✨', 'likes' => $likes, 'comments' => 1, 'shares' => 0, 'views' => 0, 'engagement_rate' => 0, 'posted_at' => now()->subDays(3),
            'evidence' => ['type' => 'image', 'engagement_basis' => null, 'observed_at' => now()->toIso8601String(), 'image_path' => $path],
            'ai_reading' => $look ? ['look' => ['version' => PostLookReader::VERSION, 'product_visible' => true, 'style' => null, ...$look]] : null]);
    }

    private function live(): void
    {
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.text_model' => 'test-text']);
        Http::swap(new Factory);
        Http::fake(function ($request) {
            $this->calls++;
            $parts = $request['contents'][0]['parts'];
            $ids = collect($parts)->pluck('text')->filter(fn ($t) => is_string($t) && str_starts_with($t, 'Post '))->map(fn ($t) => (int) substr($t, 5))->values();
            // One picture per post, each introduced by its label.
            $this->assertCount($ids->count(), array_filter($parts, fn (array $p) => isset($p['inlineData'])));
            $results = $ids->map(fn (int $id, int $i) => $i === 0
                ? ['id' => $id, 'product_visible' => false, 'garment' => '', 'colors' => [], 'pattern' => '', 'style' => '']
                : ['id' => $id, 'product_visible' => true, 'garment' => 'dress_evening', 'colors' => ['emerald', 'not-a-colour'], 'pattern' => 'embroidery', 'style' => 'luxury'])->all();

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['results' => $results])]]]]], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]]);
        });
    }

    public function test_each_post_picture_is_read_once_for_the_product_it_shows(): void
    {
        $posts = collect(range(1, 8))->map(fn (int $i) => $this->socialPost(100 + $i));
        $this->live();

        $this->assertSame(8, app(PostLookReader::class)->read($this->store));
        // Eight pictures in two calls of at most six.
        $this->assertSame(2, $this->calls);

        $shown = $posts->map(fn (SocialPost $p) => PostLookReader::look($p->fresh()));
        $this->assertSame(6, $shown->where('product', true)->count());
        $product = $shown->firstWhere('product', true);
        $this->assertSame('dress_evening', $product['garment']);
        $this->assertSame(['emerald'], $product['colors'], 'only colours the platform knows');
        // The caption says nothing; the picture fills the tags the radar and the designer use.
        $tagged = app(PostTagger::class)->tag($posts->first(fn (SocialPost $p) => PostLookReader::look($p->fresh())['product'])->fresh());
        $this->assertSame(['dress_evening', ['emerald'], 'embroidery'], [$tagged['garment'], $tagged['colors'], $tagged['pattern']]);

        // A second run reads nothing again, and a scan's update keeps the reading.
        $this->assertSame(0, app(PostLookReader::class)->read($this->store));
        $this->assertSame(2, $this->calls);
        $posts[1]->fresh()->update(['likes' => 999, 'features' => null]);
        $this->assertNotNull($posts[1]->fresh()->ai_reading['look'] ?? null);
    }

    public function test_nothing_is_read_or_marked_in_demo_mode(): void
    {
        $post = $this->socialPost(100);

        $this->assertSame(0, app(PostLookReader::class)->read($this->store));
        $this->assertNull($post->fresh()->ai_reading);
    }

    public function test_posts_stand_in_for_products_in_the_market_trends(): void
    {
        // Six emerald embroidered evening dresses and two black ones; the store has no emerald.
        foreach (range(1, 6) as $i) {
            $this->socialPost(100 + $i, ['garment' => 'dress_evening', 'colors' => ['emerald'], 'pattern' => 'embroidery']);
        }
        foreach (range(1, 2) as $i) {
            $this->socialPost(100, ['garment' => 'dress_evening', 'colors' => ['black'], 'pattern' => 'solid']);
        }

        $board = app(MarketTrends::class)->build($this->store, 'ar');

        $this->assertSame(0, $board['coverage']['products']);
        $this->assertSame(8, $board['coverage']['product_posts']);
        $this->assertSame('social', $board['coverage']['competitors'][0]['status']);

        $emerald = collect($board['opportunities'])->firstWhere('key', 'color:emerald');
        $this->assertNotNull($emerald, 'a colour their posts show and the store lacks');
        $this->assertSame('posts', $emerald['market']['basis']);
        $this->assertSame(6, $emerald['market']['shown']);
        $this->assertGreaterThanOrEqual(70, $emerald['market']['pct']);
        $this->assertTrue($emerald['market']['examples'][0]['post']);
        // What the store already carries is in the pulse, not an opportunity.
        $this->assertContains('garment:dress_evening', array_column($board['pulse'], 'key'));
        $this->assertNotContains('garment:dress_evening', array_column($board['opportunities'], 'key'));
    }
}
