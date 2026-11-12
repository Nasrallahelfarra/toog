<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\CompetitorReading;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\SocialPostObservation;
use App\Models\Store;
use App\Models\User;
use App\Services\Market\CompetitorMonitor;
use App\Services\Market\CompetitorRadar;
use App\Services\Market\CompetitorReader;
use App\Services\Market\PostTagger;
use App\Services\Market\PublicWebClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The competitor radar: engagement against each account's own usual,
 * posts linked to the exact product they name, the designs worth adding,
 * what drives engagement, the Salla catalog reader and the AI reading.
 */
class CompetitorRadarTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Competitor $instagram;

    private Competitor $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'fake']);
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Dar Abaya']);
        // The merchant sells abayas only, between 200 and 400 SAR.
        foreach ([[200, 'black'], [250, 'beige'], [300, 'navy'], [400, 'grey']] as $i => [$price, $color]) {
            $this->store->products()->create(['name' => "Abaya {$i}", 'external_id' => "a{$i}", 'category' => 'عبايات', 'price' => $price,
                'stock' => 10, 'units_sold' => 10 - $i, 'revenue' => 99999, 'features' => ['colors' => [$color], 'pattern' => 'solid']]);
        }

        $this->instagram = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Sadeim', 'platform' => 'instagram', 'handle' => 'sadeim', 'is_active' => true]);
        $this->shop = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Sadeim', 'platform' => 'salla', 'url' => 'https://sadeim.example/ar', 'is_active' => true]);
    }

    private function socialPost(Competitor $c, int $likes, string $caption, int $daysAgo = 5, array $evidence = []): SocialPost
    {
        return SocialPost::query()->create(['store_id' => $this->store->id, 'competitor_id' => $c->id, 'external_id' => uniqid(), 'platform' => $c->platform,
            'caption' => $caption, 'likes' => $likes, 'comments' => 1, 'shares' => 0, 'views' => 0, 'engagement_rate' => 0,
            'posted_at' => now()->subDays($daysAgo), 'evidence' => ['type' => 'video', 'engagement_basis' => null, 'observed_at' => now()->toIso8601String(), ...$evidence]]);
    }

    public function test_posts_are_ranked_against_their_own_account_and_linked_to_the_product_they_name(): void
    {
        $dress = CompetitorProduct::query()->create(['store_id' => $this->store->id, 'competitor_id' => $this->shop->id, 'external_id' => '555',
            'name' => 'فستان سهرة وردي بأكمام دانتيل', 'category' => 'فساتين سهرة', 'price' => 739, 'image_url' => 'https://cdn.example/dress.webp',
            'is_best_seller' => true, 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now(),
            'evidence' => ['sku' => '3518104', 'currency' => 'SAR', 'published_sales' => ['text' => 'تم شراؤه 44 مرة', 'url' => '#', 'count' => 44]]]);
        foreach ([100, 110, 90, 100, 95] as $likes) {
            $this->socialPost($this->instagram, $likes, 'لوك اليوم');
        }
        $hit = $this->socialPost($this->instagram, 1000, 'احد بحياتكم زيها؟ رقم المنتج:3518104');
        $giveaway = $this->socialPost($this->instagram, 1200, 'مسابقة! منشن صديقتك للسحب');

        $radar = app(CompetitorRadar::class)->build($this->store, 30, 'ar');
        $top = collect($radar['posts'])->firstWhere('id', $hit->id);

        $this->assertSame(9.91, $top['lift'], 'the account median is 101 interactions (likes plus a comment)');
        $this->assertSame('exceptional', $top['tier']);
        $this->assertSame($dress->id, $top['product']['id']);
        $this->assertSame('تم شراؤه 44 مرة', $top['product']['published_sales']);
        $this->assertSame('dress_evening', $top['tags']['garment'], 'the product name is more precise than the caption');
        $this->assertSame(['3518104'], $top['tags']['codes']);
        // A giveaway inflates engagement, so it ranks below the product post.
        $this->assertSame($hit->id, $radar['posts'][0]['id']);
        $this->assertContains('giveaway', collect($radar['posts'])->firstWhere('id', $giveaway->id)['tags']['flags']);

        // The product named by a post at 10× its usual, a best seller with
        // published sales, and a garment the merchant does not sell.
        $rec = collect($radar['recommendations'])->firstWhere('id', $dress->id);
        $this->assertNotNull($rec);
        $this->assertContains('engagement', $rec['signals']);
        $this->assertSame('none', $rec['novelty']);
        $this->assertTrue($rec['new_garment']);
        $this->assertSame('price_fit', $rec['verb'], '739 SAR is far above the 250–300 band');
        $this->assertSame('above', $rec['position']['status']);
        $this->assertStringContainsString('تم شراؤه 44 مرة', $rec['text']);

        $this->assertStringNotContainsString('99999', json_encode($radar), 'the merchant\'s revenue is never used');
    }

    public function test_a_design_the_merchant_already_has_is_not_recommended(): void
    {
        // Same garment, colour and pattern as the merchant's black abaya.
        $rival = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Other brand', 'platform' => 'zid', 'url' => 'https://other.example', 'is_active' => true]);
        foreach ([$this->shop, $rival] as $c) {
            CompetitorProduct::query()->create(['store_id' => $this->store->id, 'competitor_id' => $c->id, 'external_id' => 'b'.$c->id,
                'name' => 'عباية سوداء سادة', 'category' => 'عبايات', 'price' => 250, 'is_best_seller' => true,
                'features' => ['colors' => ['black'], 'pattern' => 'solid'], 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()]);
        }

        $radar = app(CompetitorRadar::class)->build($this->store, 30, 'ar');

        $this->assertSame([], $radar['recommendations']);
    }

    public function test_drivers_compare_posts_within_each_account(): void
    {
        $other = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Lamsat', 'platform' => 'tiktok', 'handle' => 'lamsat', 'is_active' => true]);
        foreach ([$this->instagram, $other] as $c) {
            foreach ([300, 320, 310] as $likes) {
                $this->socialPost($c, $likes, 'تنسيقة اليوم لوك جديد');
            }
            foreach ([100, 110, 90, 105] as $likes) {
                $this->socialPost($c, $likes, 'متوفر الآن');
            }
        }

        $drivers = collect(app(CompetitorRadar::class)->build($this->store, 30, 'ar')['drivers']);
        $styling = $drivers->first(fn ($d) => $d['dimension'] === 'hook' && $d['key'] === 'styling');

        $this->assertNotNull($styling);
        $this->assertGreaterThan(2.5, $styling['effect']);
        $this->assertSame(2, $styling['brands']);
        $this->assertSame(2, $styling['agree']);
        $this->assertFalse($styling['conflicting']);
    }

    public function test_tagger_reads_codes_hooks_and_stretched_words(): void
    {
        $tagger = app(PostTagger::class);
        $tag = fn (string $caption) => $tagger->tag(new SocialPost(['caption' => $caption, 'evidence' => ['type' => 'image'], 'views' => 0]));

        $this->assertSame(['11134'], $tag('الفستان انيق كود المنتج:11134')['codes']);
        $this->assertNotContains('offer', $tag('الفستان انيق كود المنتج:11134')['hooks'], 'a product code is not a discount code');
        $this->assertContains('offer', $tag('خصم 20% لفترة محدودة')['hooks']);
        $this->assertSame('dress_daily', $tag('اموووت بالفسااتييين')['garment']);
        $this->assertSame('61586215060091', Competitor::normalizeHandle('https://www.facebook.com/profile.php?id=61586215060091'));
    }

    public function test_salla_stores_are_read_from_their_public_catalog(): void
    {
        $this->partialMock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
        $site = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Sadeim', 'platform' => 'website', 'url' => 'https://sadeim.example/ar', 'is_active' => true]);
        $item = fn (string $id, string $name, array $extra = []) => ['id' => $id, 'name' => $name, 'url' => "https://sadeim.example/ar/x/p{$id}", 'price' => 300,
            'sale_price' => null, 'regular_price' => 300, 'is_on_sale' => false, 'sold_quantity' => null, 'is_hidded_quantity' => false, 'currency' => 'SAR',
            'image' => ['url' => "https://cdn.salla.example/{$id}.webp"], 'category' => ['name' => 'فساتين سهرة'], 'sku' => "S{$id}", ...$extra];
        Http::fake([
            'sadeim.example/robots.txt' => Http::response("User-agent: *\nAllow: /"),
            'sadeim.example/ar' => Http::response('<script src="https://cdn.salla.network/x.js"></script><script>window.x={"store":{"id":270150020}}</script>'
                .'<a href="https://sadeim.example/ar/'.rawurlencode('الأكثر-مبيعا').'/c872795716">الأكثر مبيعا</a>'),
            'api.salla.dev/store/v1/products?per_page=15&source=*' => Http::response(['data' => [$item('2', 'فستان سهرة وردي', ['sold_quantity' => 44])], 'cursor' => ['next' => null]]),
            'api.salla.dev/store/v1/products*' => Http::response(['data' => [
                $item('1', 'فستان سهرة أسود', ['is_on_sale' => true, 'sale_price' => 240, 'regular_price' => 300]),
                $item('2', 'فستان سهرة وردي', ['sold_quantity' => 44]),
                $item('3', 'شنطة', ['sold_quantity' => 5, 'is_hidded_quantity' => true]),
            ], 'cursor' => ['next' => null]]),
        ]);

        $result = app(CompetitorMonitor::class)->scan($site);

        $this->assertSame('ok', $result->status);
        $this->assertSame('salla', $site->fresh()->platform, 'a website on Salla is remembered as Salla');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.salla.dev') && $request->header('Store-Identifier') === ['270150020']);
        $products = CompetitorProduct::query()->where('competitor_id', $site->id)->get()->keyBy('external_id');
        $this->assertCount(3, $products);
        $this->assertTrue($products['2']->is_best_seller);
        $this->assertSame('تم شراؤه 44 مرة', $products['2']->evidence['published_sales']['text']);
        $this->assertSame('S2', $products['2']->evidence['sku']);
        $this->assertSame(240.0, (float) $products['1']->price);
        $this->assertSame(300.0, (float) $products['1']->previous_price, 'the store\'s own "before" price is a cut it shows');
        $this->assertNull($products['3']->evidence['published_sales'], 'a hidden count stays hidden');
    }

    public function test_scans_keep_a_dated_copy_of_post_numbers(): void
    {
        $post = $this->socialPost($this->instagram, 10, 'x');
        SocialPostObservation::query()->create(['social_post_id' => $post->id, 'observed_at' => now(), 'likes' => 10, 'comments' => 1, 'shares' => 0, 'views' => 0]);

        $this->assertSame(1, SocialPostObservation::query()->where('social_post_id', $post->id)->count());
    }

    public function test_the_ai_reading_keeps_only_grounded_answers(): void
    {
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.text_model' => 'test-text']);
        foreach ([100, 110, 90, 100, 95] as $likes) {
            $this->socialPost($this->instagram, $likes, 'عادي');
        }
        $hit = $this->socialPost($this->instagram, 1000, 'فستان سهرة لامع');
        $answer = ['summary' => 'الفساتين اللامعة تجذب التفاعل عند هذا الحساب.', 'insights' => [
            ['title' => 'اللمعة تجذب', 'body' => 'المنشور حقق 10× معتاده.', 'kind' => 'product', 'post_ids' => [$hit->id]],
            ['title' => 'رقم مخترع', 'body' => 'حقق 37× معتاده.', 'kind' => 'product', 'post_ids' => [$hit->id]],
            ['title' => 'مبيعات', 'body' => 'رفع المبيعات بوضوح.', 'kind' => 'product', 'post_ids' => [$hit->id]],
            ['title' => 'منشور غير موجود', 'body' => 'وصف.', 'kind' => 'content', 'post_ids' => [999999]],
        ], 'posts' => [
            ['post_id' => $hit->id, 'product_visible' => true, 'product' => 'فستان سهرة لامع', 'why' => 'اللمعة تظهر في الضوء', 'design_takeaway' => 'أضف لمعة على الأكمام',
                'content_takeaway' => 'صوّره في ضوء دافئ', 'garment' => 'dress_evening', 'pattern' => 'not-a-pattern', 'silhouette' => 'mermaid', 'presentation' => 'on_model',
                'confidence' => 'sure', 'colors' => ['black', 'neon']],
        ]];
        Http::swap(new Factory);
        Http::fake(function ($request) use ($answer) {
            $this->assertStringNotContainsString('"enum"', json_encode($request['generationConfig']['responseJsonSchema']));

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]]);
        });

        $reading = app(CompetitorReader::class)->read($this->store, 30, $this->store->owner_id);

        $this->assertTrue($reading->from_model);
        $this->assertSame(['اللمعة تجذب'], array_column($reading->report['insights'], 'title'), 'invented numbers, sales claims and unknown posts are dropped');
        $this->assertTrue($reading->report['insights'][0]['single'], 'one account is a single finding, not a pattern');
        $post = $reading->report['posts'][$hit->id];
        $this->assertSame(['black'], $post['colors']);
        $this->assertNull($post['pattern'] ?? null);
        $this->assertSame('mermaid', $post['silhouette']);
        $this->assertSame('low', $post['confidence']);

        // Asking again with the same leading posts reuses the reading.
        $this->assertSame($reading->id, app(CompetitorReader::class)->read($this->store, 30)->id);
        $this->assertSame(1, CompetitorReading::query()->count());
    }

    public function test_each_image_is_read_once_and_the_analysis_refreshes_only_when_engagement_moves(): void
    {
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.text_model' => 'test-text']);
        Storage::fake('public');
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('social-posts/hit.png', (string) ob_get_clean());
        foreach ([100, 110, 90, 100, 95] as $likes) {
            $this->socialPost($this->instagram, $likes, 'عادي');
        }
        $hit = $this->socialPost($this->instagram, 400, 'فستان سهرة لامع', evidence: ['image_path' => 'social-posts/hit.png']);

        $calls = [];
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$calls, $hit) {
            $parts = $request['contents'][0]['parts'];
            $facts = json_decode(end($parts)['text'], true);
            $calls[] = ['images' => count($parts) - 1, 'fill' => $facts['fill_posts']];

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['summary' => 'قراءة', 'insights' => [],
                'posts' => array_map(fn ($id) => ['post_id' => $id, 'product_visible' => true, 'product' => $id === $hit->id ? 'فستان سهرة لامع' : 'منتج',
                    'why' => 'لمعة', 'design_takeaway' => '', 'content_takeaway' => '', 'garment' => 'dress_evening', 'pattern' => 'solid',
                    'silhouette' => 'mermaid', 'presentation' => 'on_model', 'confidence' => 'high', 'colors' => ['black']], $facts['fill_posts'])])]]]]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]]);
        });
        $reader = app(CompetitorReader::class);

        // First reading: the hit's image is sent and its reading kept on the post.
        $reader->read($this->store, 30);
        $this->assertCount(1, $calls);
        $this->assertSame(1, $calls[0]['images']);
        $this->assertSame('فستان سهرة لامع', $hit->fresh()->ai_reading['ar']['product']);

        // Same posts, same numbers: nothing is sent, nothing is paid.
        $reader->read($this->store, 30);
        $this->assertCount(1, $calls);

        // Likes on the hit doubled: the analysis is refreshed from text only.
        $hit->update(['likes' => 900]);
        $reader->read($this->store, 30);
        $this->assertCount(2, $calls);
        $this->assertSame(0, $calls[1]['images']);
        $this->assertContains($hit->id, $calls[1]['fill']);
        $this->assertSame('فستان سهرة لامع', $hit->fresh()->ai_reading['ar']['product'], 'what the image shows is kept');
    }

    public function test_the_page_opens_on_the_radar_and_design_like_this_fills_the_designer(): void
    {
        $product = CompetitorProduct::query()->create(['store_id' => $this->store->id, 'competitor_id' => $this->shop->id, 'external_id' => '9',
            'name' => 'عباية كحلي مطرزة', 'category' => 'عبايات', 'price' => 300, 'image_url' => 'https://cdn.example/a.webp',
            'features' => ['colors' => ['navy'], 'pattern' => 'embroidery'], 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $owner = $this->store->owner;

        $this->actingAs($owner)->get('/store/competitors')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('store/competitors')->where('tab', 'radar')->where('days', 30)->missing('radar'));

        $this->post("/store/competitors/products/{$product->id}/watch")->assertRedirect();
        $this->assertNotNull($product->fresh()->watched_at);

        $this->get("/store/studio?mode=designer&inspire=product:{$product->id}")
            ->assertInertia(fn (Assert $page) => $page->where('designerPrefill.garment', 'abaya')
                ->where('designerPrefill.colors', ['navy'])
                ->where('designerPrefill.inspiration.kind', 'competitor')
                ->where('designerPrefill.inspiration.id', $product->id));
    }

    public function test_competitor_products_of_other_stores_cannot_be_watched(): void
    {
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $product = CompetitorProduct::query()->create(['store_id' => $this->store->id, 'competitor_id' => $this->shop->id, 'external_id' => '1', 'name' => 'x']);

        $this->actingAs($other->owner)->post("/store/competitors/products/{$product->id}/watch")->assertNotFound();
        $this->assertNull($product->fresh()->watched_at);
        $this->assertNull(Product::query()->where('store_id', $other->id)->first());
    }
}
