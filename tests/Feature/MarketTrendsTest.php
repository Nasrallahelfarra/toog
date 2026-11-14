<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\Trend;
use App\Models\User;
use App\Services\Market\MarketTrends;
use App\Services\Market\TrendDetector;
use App\Support\FashionVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The market trends page: the active competitors' clothing (real scans
 * only, demand-weighted) against the store's own units, sorted into
 * opportunities, focus, rising and cooling.
 */
class MarketTrendsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Competitor $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 14));
        config(['inertia.ssr.enabled' => false, 'mojo.ai.provider' => 'fake']);
        Notification::fake();
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Dar Abaya']);

        // Units per month for the three months before (May–Jul) and the last three (Aug–Oct).
        $this->product('Black abaya 1', 'black', 20, 20);
        $this->product('Black abaya 2', 'black', 10, 10);
        $this->product('Olive abaya 1', 'olive', 2, 7);
        $this->product('Olive abaya 2', 'olive', 2, 7);
        $this->product('Grey abaya 1', 'grey', 10, 2);
        $this->product('Grey abaya 2', 'grey', 10, 2);

        $this->rival = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Rival', 'platform' => 'salla', 'url' => 'https://rival.example', 'is_active' => true]);
    }

    private function product(string $name, string $color, int $before, int $now): Product
    {
        $product = $this->store->products()->create(['name' => $name, 'external_id' => uniqid(), 'category' => 'عبايات', 'price' => 300,
            'stock' => 12, 'revenue' => 99999, 'features' => ['colors' => [$color], 'pattern' => 'solid']]);

        foreach (['2026-05-01' => $before, '2026-06-01' => $before, '2026-07-01' => $before, '2026-08-01' => $now, '2026-09-01' => $now, '2026-10-01' => $now] as $period => $units) {
            ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $product->id, 'period' => $period, 'units' => $units, 'orders' => $units, 'revenue' => $units * 300]);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function listing(Competitor $c, string $name, string $category, string $color, array $extra = [], bool $real = true): CompetitorProduct
    {
        return CompetitorProduct::query()->create([
            'store_id' => $this->store->id, 'competitor_id' => $c->id, 'external_id' => uniqid(), 'name' => $name, 'category' => $category,
            'price' => 500, 'features' => ['colors' => [$color]], 'first_seen_at' => now()->subDays(20), 'last_seen_at' => now()->subDay(),
            'evidence' => $real ? ['observed_at' => now()->toIso8601String(), 'currency' => 'SAR', ...($extra['evidence'] ?? [])] : null,
            ...array_diff_key($extra, ['evidence' => true]),
        ]);
    }

    private function seedMarket(): void
    {
        foreach (range(1, 6) as $i) {
            $this->listing($this->rival, "فستان سهرة وردي {$i}", 'فساتين سهرة', 'dusty_pink',
                ['evidence' => ['published_sales' => ['text' => 'تم شراؤه 20 مرة', 'count' => 20]]]);
        }
        foreach (range(1, 5) as $i) {
            $this->listing($this->rival, "عباية زيتي {$i}", 'عبايات', 'olive', ['is_best_seller' => true]);
        }
        // Not clothing: left out of a fashion store's market.
        foreach (range(1, 4) as $i) {
            $this->listing($this->rival, "Sport sneakers {$i}", 'Sneakers', 'dusty_pink');
        }
        // Men's clothing is not a women's store's market, even as a "set".
        $this->listing($this->rival, 'Lounge Set Burgundy', 'Sets', 'burgundy', ['evidence' => ['description' => 'Relaxed men’s lounge set in soft cotton']]);
        $this->listing($this->rival, 'طقم رجالي كحلي', 'أطقم', 'navy');
        // A seeded sample row says nothing once real scans exist.
        foreach (range(1, 6) as $i) {
            $this->listing($this->rival, "عباية خردلي {$i}", 'عبايات', 'mustard', ['is_best_seller' => true], real: false);
        }
        // An inactive competitor is not watched.
        $paused = Competitor::query()->create(['store_id' => $this->store->id, 'name' => 'Paused', 'platform' => 'salla', 'url' => 'https://paused.example', 'is_active' => false]);
        foreach (range(1, 6) as $i) {
            $this->listing($paused, "عباية تركوازي {$i}", 'عبايات', 'teal', ['is_best_seller' => true]);
        }
    }

    public function test_market_and_own_sales_sort_into_opportunities_focus_rising_and_cooling(): void
    {
        $this->seedMarket();

        $board = app(MarketTrends::class)->build($this->store, 'ar');
        $keys = fn (string $section) => array_column($board[$section], 'key');

        $this->assertSame('live', $board['basis']);
        $this->assertSame(11, $board['coverage']['products']);
        $this->assertSame(6, $board['coverage']['excluded']);
        $this->assertSame(1, $board['coverage']['brands']);
        // 20 + 10 + 7 + 7 + 2 + 2 units a month over Aug–Oct.
        $this->assertSame(144, $board['coverage']['units']);

        // Strong at the competitor and absent from the catalog.
        $this->assertContains('color:dusty_pink', $keys('opportunities'));
        $this->assertContains('garment:dress_evening', $keys('opportunities'));
        $pink = collect($board['opportunities'])->firstWhere('key', 'color:dusty_pink');
        $this->assertSame(20, $pink['market']['examples'][0]['published_sales']);
        $this->assertSame(0, $pink['mine']['catalog_pct'] ?? 0);
        $this->assertSame('/store/studio?mode=designer&inspire=attr%3Acolor%3Adusty_pink', $pink['design_href']);

        // Strong at the competitor and rising in the store's own sales.
        $this->assertContains('color:olive', $keys('focus'));
        $olive = collect($board['focus'])->firstWhere('key', 'color:olive');
        $this->assertSame(42, $olive['mine']['units']);
        $this->assertGreaterThan(100, $olive['mine']['change_pct']);

        // Its share of units fell, and there is stock left.
        $this->assertContains('color:grey', $keys('cooling'));

        // Never the sample row, the inactive competitor or the shoes.
        $all = collect(['opportunities', 'focus', 'mine_rising', 'cooling', 'pulse'])->flatMap($keys);
        $this->assertNotContains('color:mustard', $all);
        $this->assertNotContains('color:teal', $all);
        $this->assertSame(6, collect($board['pulse'])->firstWhere('key', 'color:dusty_pink')['market']['items']);
        // Black sells flat: a staple, not a verdict.
        $this->assertNotContains('color:black', collect(['opportunities', 'focus', 'mine_rising', 'cooling'])->flatMap($keys));
    }

    public function test_mens_and_childrens_clothing_is_recognised(): void
    {
        $this->assertTrue(FashionVocabulary::forMenOrKids('Sets', 'Classic Tee Set', 'Oversized tee for men'));
        $this->assertTrue(FashionVocabulary::forMenOrKids('عبايات بناتي أطفال', 'عباية صغيرة'));
        $this->assertFalse(FashionVocabulary::forMenOrKids('Dresses', 'Linen dress', 'Made for women, hand-made in Riyadh'));
        $this->assertFalse(FashionVocabulary::forMenOrKids('عبايات', 'عباية كريب نسائية'));
    }

    public function test_seeded_samples_are_used_only_until_real_scans_exist(): void
    {
        foreach (range(1, 6) as $i) {
            $this->listing($this->rival, "عباية خردلي {$i}", 'عبايات', 'mustard', ['is_best_seller' => true], real: false);
        }

        $board = app(MarketTrends::class)->build($this->store, 'ar');

        $this->assertSame('sample', $board['basis']);
        $this->assertSame(6, $board['coverage']['products']);
    }

    public function test_the_page_renders_the_board_and_design_with_it_fills_the_designer(): void
    {
        $this->seedMarket();
        $owner = $this->store->owner;

        $this->actingAs($owner)->get('/store/market')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('store/market')
                ->where('board.basis', 'live')
                ->has('board.opportunities')
                ->has('reviews')
                ->missing('trends')
                ->missing('activity'));

        $this->get('/store/studio?mode=designer&inspire=attr:color:dusty_pink')
            ->assertInertia(fn (Assert $page) => $page->where('designerPrefill.colors', ['dusty_pink'])
                ->where('designerPrefill.inspiration.kind', 'gap')
                ->where('designerPrefill.inspiration.id', 'color:dusty_pink'));
        $this->get('/store/studio?mode=designer&inspire=attr:garment:dress_evening')
            ->assertInertia(fn (Assert $page) => $page->where('designerPrefill.garment', 'dress_evening'));
        $this->get('/store/studio?mode=designer&inspire=attr:color:not_a_colour')
            ->assertInertia(fn (Assert $page) => $page->where('designerPrefill', null));
    }

    public function test_trends_reset_on_the_first_real_analysis_and_cool_without_signals(): void
    {
        Trend::query()->create(['store_id' => $this->store->id, 'type' => 'color', 'value' => 'mustard', 'name_ar' => 'خردلي', 'name_en' => 'Mustard',
            'momentum' => 80, 'growth' => 40, 'saturation' => 20, 'status' => 'rising', 'detected_at' => now()->subMonth()]);
        $this->seedMarket();

        app(TrendDetector::class)->detect($this->store);

        $this->assertDatabaseMissing('trends', ['store_id' => $this->store->id, 'value' => 'mustard']);
        $this->assertSame('live', SyncRun::query()->where('store_id', $this->store->id)->where('type', 'trend_analysis')->latest('id')->first()->stats['basis']);

        // Later, a trend with no signals left cools instead of staying "rising".
        $stale = Trend::query()->create(['store_id' => $this->store->id, 'type' => 'color', 'value' => 'sage', 'name_ar' => 'مريمية', 'name_en' => 'Sage',
            'momentum' => 60, 'growth' => 25, 'saturation' => 10, 'status' => 'rising', 'detected_at' => now()->subMonth()]);

        app(TrendDetector::class)->detect($this->store);

        $stale->refresh();
        $this->assertSame(42, $stale->momentum);
        $this->assertSame('declining', $stale->status);
        $this->assertTrue(Trend::query()->whereKey($stale->id)->exists());
    }
}
