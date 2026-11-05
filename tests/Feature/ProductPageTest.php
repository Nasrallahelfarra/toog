<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Product;
use App\Models\ProductRecommendation;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductPageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        $this->store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Store']);
        $latest = now()->startOfMonth()->subMonth();

        foreach ([['Abaya', 300, 900, 40, 5.0], ['Best abaya', 250, 4000, 120, 2.0], ['Cheap abaya', 150, 300, 10, 9.0]] as [$name, $price, $revenue, $units, $returns]) {
            $product = $this->store->products()->create(['name' => $name, 'external_id' => $name, 'category' => 'Abayas', 'price' => $price,
                'revenue' => $revenue, 'units_sold' => $units, 'return_rate' => $returns, 'performance' => Product::AVERAGE, 'features' => ['colors' => ['black']]]);
            ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $product->id, 'period' => $latest,
                'orders' => $units, 'units' => $units, 'revenue' => $revenue]);
        }
        $this->product = Product::query()->where('name', 'Abaya')->firstOrFail();
    }

    public function test_the_page_shows_the_product_its_sales_and_its_place_in_the_category(): void
    {
        $this->actingAs($this->store->owner)->get("/store/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page->component('store/products/show')
                ->where('product.name', 'Abaya')
                ->where('product.price', 300)
                ->where('product.features.colors', ['black'])
                ->has('history', 12)
                ->where('history.11.units', 40)
                ->where('category.name', 'Abayas')
                ->where('category.products', 3)
                ->where('category.rank', 2)
                ->where('category.median.price', 250)
                ->where('category.median.units_sold', 40)
                ->has('similar', 2)
                ->where('similar.0.name', 'Best abaya')
                ->where('recovery', null));
    }

    public function test_a_saved_recommendation_is_shown_on_the_page(): void
    {
        ProductRecommendation::query()->create(['store_id' => $this->store->id, 'product_id' => $this->product->id, 'status' => 'pending', 'snapshot' => ['name' => 'Abaya']]);

        $this->actingAs($this->store->owner)->get("/store/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page->where('recovery.status', 'pending'));
    }

    public function test_other_stores_cannot_open_the_page(): void
    {
        $other = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Other']);

        $this->actingAs($other->owner)->get("/store/products/{$this->product->id}")->assertNotFound();
    }
}
