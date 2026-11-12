<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use App\Services\Reports\ReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Revenue is hidden from the store by default; only the platform admin can
 * show it, per store. While hidden, the store sees units and orders only.
 */
class RevenueVisibilityTest extends TestCase
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

        $this->product = $this->store->products()->create(['name' => 'Abaya', 'external_id' => 'a', 'category' => 'عبايات',
            'price' => 300, 'revenue' => 9000, 'units_sold' => 30, 'performance' => Product::WINNER]);
        foreach ([[1, 10], [0, 20]] as [$back, $units]) {
            ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $this->product->id,
                'period' => $latest->copy()->subMonths($back), 'orders' => $units, 'units' => $units, 'revenue' => $units * 300]);
        }
    }

    public function test_revenue_is_hidden_by_default_across_the_store_dashboard(): void
    {
        $this->assertFalse($this->store->showsRevenue());
        $owner = $this->store->owner;

        $this->actingAs($owner)->get('/store/catalog?months=3')
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.show_revenue', false)
                ->where('summary.revenue', null)
                ->where('summary.units', 30)
                ->where('trend.2.revenue', null)
                ->where('trend.2.units', 20)
                ->where('categories.0.revenue', null)
                ->where('categories.0.units', 30)
                // The category line follows units while revenue is hidden.
                ->where('categories.0.spark.11', 20)
                ->where('products.data.0.revenue', null)
                ->where('products.data.0.history.11.revenue', null)
                ->where('products.data.0.history.11.units', 20));

        $this->get('/store/performance')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.classes.winner.revenue_share', null)
                // The performance trend runs to the current month; sales end last month.
                ->where('trend.10.revenue', null)
                ->where('trend.10.units', 20)
                ->where('categories.0.revenue', null)
                ->where('products.data.0.revenue', null)
                ->where('products.data.0.units_sold', 30));

        $this->get("/store/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.revenue', null)
                ->where('product.units_sold', 30)
                ->where('history.11.revenue', null)
                ->where('history.11.units', 20));

        $this->get('/store')
            ->assertInertia(fn (Assert $page) => $page->where('kpis.revenue', null)->where('revenueTrend', []));
    }

    public function test_catalog_ranks_by_units_when_revenue_is_hidden(): void
    {
        $this->store->products()->create(['name' => 'Pricey', 'external_id' => 'p', 'category' => 'عبايات', 'price' => 5000, 'revenue' => 50000, 'units_sold' => 2]);

        $this->actingAs($this->store->owner)->get('/store/catalog?sort=revenue')
            ->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'units')->where('products.data.0.name', 'Abaya'));
    }

    public function test_the_admin_switch_shows_revenue_to_the_owner(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->put("/admin/stores/{$this->store->id}/revenue-visibility", ['show_revenue' => true])->assertRedirect();
        $this->assertTrue($this->store->fresh()->showsRevenue());
        $this->app->forgetScopedInstances();

        $this->actingAs($this->store->owner)->get('/store/catalog?months=3')
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.show_revenue', true)
                ->where('summary.revenue', 9000)
                ->where('products.data.0.revenue', 9000));

        $this->actingAs($admin)->put("/admin/stores/{$this->store->id}/revenue-visibility", ['show_revenue' => false])->assertRedirect();
        $this->assertFalse($this->store->fresh()->showsRevenue());
    }

    public function test_only_the_admin_can_switch_revenue(): void
    {
        $this->actingAs($this->store->owner)
            ->put("/admin/stores/{$this->store->id}/revenue-visibility", ['show_revenue' => true])
            ->assertForbidden();

        $this->assertFalse($this->store->fresh()->showsRevenue());
    }

    public function test_revenue_audit_is_closed_to_the_owner_while_revenue_is_hidden(): void
    {
        $this->actingAs($this->store->owner)->get('/store/settings/integration/revenue-audit')->assertNotFound();
    }

    public function test_revenue_audit_opens_once_revenue_is_shown(): void
    {
        $this->store->update(['settings' => [...$this->store->settings ?? [], 'show_revenue' => true]]);

        $this->actingAs($this->store->owner)->get('/store/settings/integration/revenue-audit')->assertOk();
    }

    public function test_performance_report_drops_revenue_while_hidden(): void
    {
        $build = fn () => app(ReportBuilder::class)->build('performance', $this->store->fresh(), CarbonImmutable::now()->subYear(), CarbonImmutable::now());

        $hidden = $build();
        $this->assertNotContains('Revenue', array_column($hidden['summary'], 'label_en'));
        foreach ($hidden['sections'] as $section) {
            $this->assertNotContains('revenue', array_column($section['columns'], 'key'));
            foreach ($section['rows'] as $row) {
                $this->assertArrayNotHasKey('revenue', $row);
            }
        }

        $this->store->update(['settings' => [...$this->store->settings ?? [], 'show_revenue' => true]]);
        $this->assertContains('Revenue', array_column($build()['summary'], 'label_en'));
    }
}
