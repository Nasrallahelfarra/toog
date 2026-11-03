<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\ReclassifyProducts;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Analytics\PerformanceClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PerformanceClassificationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.ssr.enabled' => false]);
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00'));
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Classification']);
        $this->store->update(['platform_store_id' => '123']);
    }

    private function product(array $attributes = []): Product
    {
        return $this->store->products()->create(['name' => 'Product', 'stock' => 10,
            'external_id' => uniqid(), 'launched_at' => now()->subYear(), ...$attributes]);
    }

    private function sale(Product $product, int $units, array $attributes = []): void
    {
        ProductSale::query()->create(['store_id' => $product->store_id, 'product_id' => $product->id,
            'period' => now()->startOfMonth(), 'units' => $units, 'revenue' => $units * 10, ...$attributes]);
    }

    private function sync(array $attributes = [], array $stats = []): SyncRun
    {
        return $this->store->syncRuns()->create(['type' => 'zid_sync', 'status' => 'completed',
            'started_at' => now()->subMinute(), 'finished_at' => now(),
            'stats' => ['scope' => 'full', 'zid_store_id' => '123', 'orders_stored' => true,
                'orders_since' => now()->startOfMonth()->subMonths(11)->toIso8601String(), ...$stats], ...$attributes]);
    }

    public function test_products_without_sales_data_do_not_inflate_the_scores_of_weak_sellers(): void
    {
        $slow = $this->product(['name' => 'Slow seller', 'stock' => 1]);
        $fast = $this->product(['name' => 'Fast seller', 'stock' => 1]);
        $this->sale($slow, 1);
        $this->sale($fast, 100);
        app(PerformanceClassifier::class)->classify($this->store);
        $score = $slow->fresh()->performance_score;
        foreach (range(1, 25) as $i) {
            $this->product();
        }
        app(PerformanceClassifier::class)->classify($this->store);

        $this->assertSame(Product::STAGNANT, $slow->fresh()->performance);
        $this->assertSame($score, $slow->fresh()->performance_score);
        $this->assertSame(Product::WINNER, $fast->fresh()->performance);
        $this->assertSame(25, $this->store->products()->whereNull('performance')->count());
    }

    public function test_slow_stock_turnover_takes_priority_over_a_high_sales_score(): void
    {
        $overstocked = $this->product(['stock' => 1000]);
        $other = $this->product(['stock' => 1]);
        $this->sale($overstocked, 100);
        $this->sale($other, 1);
        app(PerformanceClassifier::class)->classify($this->store);

        $this->assertGreaterThanOrEqual(67, $overstocked->fresh()->performance_score);
        $this->assertSame(300, $overstocked->fresh()->turnover_days);
        $this->assertSame(Product::STAGNANT, $overstocked->fresh()->performance);
    }

    public function test_equal_signals_are_neutral_instead_of_rewarding_or_penalizing_ties(): void
    {
        foreach (range(1, 3) as $i) {
            $this->sale($this->product(['stock' => 1]), 10, ['views' => 100, 'add_to_cart' => 10]);
        }
        app(PerformanceClassifier::class)->classify($this->store);
        $this->assertSame([50], $this->store->products()->pluck('performance_score')->unique()->values()->all());
        $this->assertSame([Product::AVERAGE], $this->store->products()->pluck('performance')->unique()->values()->all());
    }

    public function test_completed_sales_coverage_includes_old_unsold_stock_but_not_new_or_unknown_products(): void
    {
        $old = $this->product();
        $new = $this->product(['launched_at' => now()->subDays(20)]);
        $unknown = $this->product(['launched_at' => null]);
        $local = $this->product(['external_id' => null]);
        $empty = $this->product(['stock' => 0]);
        $this->sync();
        app(PerformanceClassifier::class)->classify($this->store);

        $this->assertSame(Product::STAGNANT, $old->fresh()->performance);
        $this->assertSame(0, $old->fresh()->units_sold);
        $this->assertSame(365, $old->fresh()->turnover_days);
        foreach ([$new, $unknown, $local, $empty] as $unclassified) {
            $this->assertNull($unclassified->fresh()->performance);
        }
        $this->assertDatabaseCount('product_sales', 0);
    }

    public function test_missing_rows_are_not_zero_sales_without_completed_matching_full_coverage(): void
    {
        $product = $this->product();
        $this->sync(['status' => 'failed']);
        $this->sync(['status' => 'running']);
        $this->sync(stats: ['zid_store_id' => 'another-store']);
        $this->sync(stats: ['scope' => 'changes']);
        app(PerformanceClassifier::class)->classify($this->store);
        $this->assertNull($product->fresh()->performance);
    }

    public function test_short_sales_coverage_does_not_label_unsold_products_as_stagnant(): void
    {
        $product = $this->product();
        $this->sync(stats: ['orders_since' => now()->subDays(30)->toIso8601String()]);
        app(PerformanceClassifier::class)->classify($this->store);
        $this->assertNull($product->fresh()->performance);
    }

    public function test_days_without_orders_count_when_a_complete_import_covers_them(): void
    {
        $product = $this->product(['stock' => 100, 'launched_at' => now()->subDays(180)]);
        $this->sale($product, 30);
        $this->sync();
        $metrics = app(PerformanceClassifier::class)->metrics($this->store, collect([$product]));
        $this->assertEqualsWithDelta(5, $metrics[$product->id]['velocity'], 0.05);
        $this->assertSame(365, $metrics[$product->id]['turnover']);
        app(PerformanceClassifier::class)->classify($this->store);
        $this->assertSame(Product::STAGNANT, $product->fresh()->performance);
    }

    public function test_explicit_zero_sales_months_count_and_old_or_future_rows_are_excluded(): void
    {
        $product = $this->product(['stock' => 1]);
        $this->sale($product, 10);
        $this->sale($product, 0, ['period' => now()->startOfMonth()->subMonth()]);
        $this->sale($product, 1000, ['period' => now()->startOfMonth()->subMonths(12)]);
        $this->sale($product, 1000, ['period' => now()->startOfMonth()->addMonth()]);
        $metric = app(PerformanceClassifier::class)->metrics($this->store, collect([$product]))[$product->id];
        $this->assertSame(10, $metric['units']);
        $this->assertEquals(5, $metric['velocity']);
    }

    public function test_reclassifying_from_stagnant_page_updates_dashboard_and_list_without_extracting_images(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $product = $this->product(['name' => 'Unsold stock']);
        $unknown = $this->product(['launched_at' => now()->subDay()]);
        $this->sync();
        $otherStore = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $other = $otherStore->products()->create(['name' => 'Other product', 'performance' => Product::WINNER]);

        $this->actingAs($this->store->owner)->from('/store/stagnant')
            ->post('/store/performance/reclassify')->assertRedirect('/store/stagnant');
        $queued = Queue::pushed(ReclassifyProducts::class)->sole();
        app()->call([$queued, 'handle']);
        $this->get('/store/stagnant')->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)->where('summary.unclassified', 1)
            ->has('products.data', 1)->where('products.data.0.id', $product->id));
        $this->get('/store')->assertInertia(fn (Assert $page) => $page
            ->where('stagnant.total', 1)->where('stagnant.products.0.id', $product->id));
        $this->assertNull($product->fresh()->features);
        $this->assertNull($unknown->fresh()->performance);
        $this->assertSame(Product::WINNER, $other->fresh()->performance);
        $this->get('/store/stagnant?page=5&search=Unsold')->assertRedirect(route('store.stagnant.index', ['search' => 'Unsold', 'page' => 1]));
    }
}
