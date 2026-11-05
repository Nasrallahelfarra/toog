<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogBrowserTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Store']);
        $latest = now()->startOfMonth()->subMonth();

        foreach ([['Abaya', 'عبايات', 300, [10, 20]], ['Scarf', 'طرح', 80, [5, 5]], ['Loose', null, 50, [0, 2]]] as [$name, $category, $price, $units]) {
            $product = $this->store->products()->create(['name' => $name, 'external_id' => $name, 'category' => $category, 'price' => $price, 'revenue' => $price * array_sum($units)]);
            foreach ($units as $i => $n) {
                if ($n > 0) {
                    ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $product->id,
                        'period' => $latest->copy()->subMonths(1 - $i), 'orders' => $n, 'units' => $n, 'revenue' => $n * $price]);
                }
            }
        }
    }

    public function test_catalog_lists_categories_and_ends_the_series_at_the_latest_month_with_sales(): void
    {
        $this->actingAs($this->store->owner)->get('/store/catalog?months=3')
            ->assertInertia(fn (Assert $page) => $page->component('store/catalog')
                ->where('months', 3)
                ->where('category', null)
                ->has('trend', 3)
                ->where('trend.2.period', now()->startOfMonth()->subMonth()->toDateString())
                ->where('trend.2.units', 27)
                ->where('summary.units', 42)
                ->where('summary.products', 3)
                ->has('categories', 3)
                ->where('categories.0.name', 'عبايات')
                ->where('categories.0.revenue', 9000)
                ->where('categories.2.key', '__none__')
                ->has('categories.0.spark', 12)
                ->has('products.data', 3));
    }

    public function test_opening_a_category_scopes_sales_and_products_with_filters(): void
    {
        $this->actingAs($this->store->owner)->get('/store/catalog?category=طرح')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.units', 10)
                ->where('summary.products', 1)
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Scarf')
                ->has('products.data.0.history', 12));

        $this->get('/store/catalog?category=__none__')
            ->assertInertia(fn (Assert $page) => $page->where('products.data.0.name', 'Loose')->where('summary.revenue', 100));

        $this->get('/store/catalog?search=Aba&sort=price_low')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.name', 'Abaya'));
    }

    public function test_catalog_is_scoped_to_the_current_store(): void
    {
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);
        $other->products()->create(['name' => 'Hidden', 'external_id' => 'h', 'category' => 'Secret']);

        $this->actingAs($this->store->owner)->get('/store/catalog?category=Secret')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 0)->where('summary.products', 0));
    }

    public function test_a_chosen_month_scopes_totals_chart_categories_and_product_ranking(): void
    {
        $latest = now()->startOfMonth()->subMonth();
        $earlier = $latest->copy()->subMonth()->format('Y-m');

        $this->actingAs($this->store->owner)->get("/store/catalog?month={$earlier}&sort=units")
            ->assertInertia(fn (Assert $page) => $page
                ->where('month', $earlier)
                ->where('availableMonths', [$latest->format('Y-m'), $earlier])
                ->where('summary.units', 15)
                ->where('summary.delta.units', null)
                ->has('trend', 12)
                ->where('trend.11.period', $earlier.'-01')
                ->where('trend.11.selected', true)
                ->where('categories.0.name', 'عبايات')
                ->where('categories.0.revenue', 3000)
                ->where('products.data.0.name', 'Abaya')
                ->where('products.data.0.month_units', 10)
                ->where('products.data.1.month_revenue', 400)
                ->where('products.data.2.month_units', 0));

        $this->get('/store/catalog?month='.$latest->format('Y-m'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.units', 27)->where('summary.delta.units', 80));

        // Months without sales cannot be picked; the page falls back to the range.
        $this->get('/store/catalog?month=2001-01')
            ->assertInertia(fn (Assert $page) => $page->where('month', null)->where('products.data.0.month_units', null));
    }
}
