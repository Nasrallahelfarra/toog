<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Competitor;
use App\Models\User;
use App\Services\Ai\Ai;
use App\Services\Ai\AiJsonResult;
use App\Services\Market\CompetitorMonitor;
use App\Services\Market\PublicWebClient;
use App\Services\Market\StorefrontParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetitorCrawlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mojo.ai.provider' => 'fake', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $this->partialMock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    }

    private function rival(): Competitor
    {
        $store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'My store']);

        return $store->competitors()->create(['name' => 'Rival', 'platform' => 'website', 'url' => 'https://shop.example/en']);
    }

    private function pages(bool $best = true): void
    {
        $item = fn ($id, $price) => ['id' => $id, 'title' => 'Black cotton shirt '.$id, 'handle' => 'shirt-'.$id,
            'variants' => [['price' => $price]], 'product_type' => 'Shirts'];
        Http::fake([
            'shop.example/robots.txt' => Http::response("User-agent: *\nDisallow: /*sort_by*"),
            'shop.example/en' => Http::response('<script>Shopify.currency = {"active":"SAR"};</script><main><a href="/en/products/shirt-1">Featured shirt</a>'
                .($best ? '<a href="/en/collections/bestsellers">Best sellers</a>' : '').'</main>'),
            'shop.example/en/products.json*' => Http::response(['products' => [$item(1, '100'), $item(2, '0')]]),
            'shop.example/en/collections/bestsellers/products.json*' => Http::response(['products' => [$item(2, '0')]]),
            'shop.example/en/collections/bestsellers' => Http::response('<main><a href="/en/products/shirt-2">Shirt</a></main>'),
        ]);
    }

    public function test_crawl_distinguishes_best_seller_claims_promotion_and_unknown_sales(): void
    {
        $this->withoutExceptionHandling();
        $this->pages();
        $rival = $this->rival();
        $result = app(CompetitorMonitor::class)->scan($rival);
        $this->assertSame('ok', $result->status);
        $this->assertSame(2, $result->added);
        $featured = $rival->products()->where('external_id', '1')->sole();
        $best = $rival->products()->where('external_id', '2')->sole();
        $this->assertFalse($featured->is_best_seller);
        $this->assertNotNull($featured->evidence['promotion']);
        $this->assertNull($featured->evidence['published_sales']);
        $this->assertTrue($best->is_best_seller);
        $this->assertNull($best->evidence['promotion']);
        $this->assertSame('0.00', $best->price);
        $this->assertSame('https://shop.example/en/collections/bestsellers', $best->evidence['best_seller']['url']);
        $analysis = $rival->fresh()->analysis;
        $this->assertSame(0, $analysis['facts']['published_sales']);
        $this->assertSame(1, $analysis['facts']['best_sellers']);
        $this->assertSame('not_configured', $analysis['ai']['status']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sort_by'));
        $this->actingAs($rival->store->owner)->get('/store/competitors?competitor='.$rival->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('selected.analysis.facts.products', 2)
            ->where('selected.analysis.ai.summary', null)->has('selected.products', 2));
    }

    public function test_catalog_order_does_not_create_best_sellers_and_rescans_do_not_duplicate_products(): void
    {
        $this->pages(false);
        $rival = $this->rival();
        app(CompetitorMonitor::class)->scan($rival);
        $result = app(CompetitorMonitor::class)->scan($rival);
        $this->assertSame(0, $result->added);
        $this->assertSame(2, $result->updated);
        $this->assertSame(0, $rival->products()->where('is_best_seller', true)->count());
    }

    public function test_gemini_failure_preserves_facts_without_fabricating_analysis(): void
    {
        $this->pages();
        $this->mock(Ai::class, function ($mock) {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('json')->once()->andReturn(new AiJsonResult([], false, 0));
        });
        $rival = $this->rival();
        $this->assertSame('ok', app(CompetitorMonitor::class)->scan($rival)->status);
        $analysis = $rival->fresh()->analysis;
        $this->assertSame(2, $analysis['facts']['products']);
        $this->assertSame('unavailable', $analysis['ai']['status']);
        $this->assertNull($analysis['ai']['summary']);
        $this->assertSame([], $analysis['ai']['insights']);
    }

    public function test_url_only_creation_and_tenant_isolation(): void
    {
        $rival = $this->rival();
        $this->actingAs($rival->store->owner)->post('/store/competitors', ['platform' => 'website', 'url' => 'https://clothes.example'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('competitors', ['store_id' => $rival->store_id, 'name' => 'clothes.example']);
        $other = $this->rival();
        $this->actingAs($rival->store->owner)->post('/store/competitors/'.$other->id.'/scan')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_robots_disallow_stops_crawl_before_catalog_request(): void
    {
        Http::fake(['shop.example/robots.txt' => Http::response("User-agent: *\nDisallow: /")]);
        $result = app(CompetitorMonitor::class)->scan($this->rival());
        $this->assertSame('failed', $result->status);
        Http::assertSentCount(1);
    }

    public function test_private_dns_and_redirect_targets_are_rejected(): void
    {
        $this->partialMock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['127.0.0.1']));
        $result = app(CompetitorMonitor::class)->scan($this->rival());
        $this->assertSame('failed', $result->status);
        Http::assertNothingSent();
        $this->partialMock(PublicWebClient::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
        Http::fake(['shop.example/robots.txt' => Http::response('', 302, ['Location' => 'http://localhost/private'])]);
        $result = app(CompetitorMonitor::class)->scan($this->rival());
        $this->assertSame('failed', $result->status);
        Http::assertSentCount(1);
    }

    public function test_structured_sales_property_is_distinct_from_review_count(): void
    {
        $product = ['@type' => 'Product', 'name' => 'Shirt', 'url' => 'https://shop.example/products/one',
            'offers' => ['price' => '199', 'priceCurrency' => 'SAR'], 'aggregateRating' => ['reviewCount' => 20],
            'additionalProperty' => [['name' => 'unitsSold', 'value' => 35]]];
        $html = '<script type="application/ld+json">'.json_encode($product).'</script>';
        $parsed = app(StorefrontParser::class)->parse($html, 'https://shop.example/products/one');
        $this->assertSame('unitsSold: 35', $parsed['products'][0]['published_sales']['text']);
        unset($product['additionalProperty']);
        $parsed = app(StorefrontParser::class)->parse('<script type="application/ld+json">'.json_encode($product).'</script>', 'https://shop.example/products/one');
        $this->assertNull($parsed['products'][0]['published_sales']);
    }

    public function test_rate_limit_preserves_partial_report_and_stops_further_requests(): void
    {
        Http::fake([
            'shop.example/robots.txt' => Http::response('User-agent: *'),
            'shop.example/en' => Http::response('<script>Shopify.currency = {"active":"SAR"};</script><main><a href="/en/collections/women">Best sellers women</a><a href="/en/collections/men">Best sellers men</a></main>'),
            'shop.example/en/products.json*' => Http::response(['products' => [['id' => 1, 'title' => 'Shirt', 'handle' => 'shirt', 'variants' => [['price' => 50]]]]]),
            'shop.example/en/collections/women' => Http::response('Rate limited', 429),
        ]);
        $rival = $this->rival();
        $this->assertSame('ok', app(CompetitorMonitor::class)->scan($rival)->status);
        $this->assertSame(1, $rival->fresh()->analysis['facts']['products']);
        $this->assertNotEmpty($rival->fresh()->analysis['crawl']['warnings']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/collections/men'));
    }

    public function test_generic_public_product_data_can_be_scanned_without_shopify(): void
    {
        $data = ['@type' => 'Product', 'name' => 'Linen shirt', 'url' => 'https://shop.example/products/linen',
            'offers' => ['price' => '150', 'priceCurrency' => 'SAR'], 'additionalProperty' => [['name' => 'unitsSold', 'value' => 12]]];
        Http::fake([
            'shop.example/robots.txt' => Http::response('User-agent: *'),
            'shop.example/en' => Http::response('<script type="application/ld+json">'.json_encode($data).'</script>'),
        ]);
        $rival = $this->rival();
        $this->assertSame('ok', app(CompetitorMonitor::class)->scan($rival)->status);
        $this->assertSame(1, $rival->fresh()->analysis['facts']['published_sales']);
        $this->assertSame('linen', $rival->products()->sole()->features['material']);
    }
}
