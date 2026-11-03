<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\ProductSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PerformanceSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_criteria_details_load_on_demand_with_the_store_spread_on_each_signal(): void
    {
        config(['inertia.ssr.enabled' => false]);
        $store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Store']);
        $month = now()->startOfMonth();
        foreach ([['Fast', 40, 4000, 2, 10], ['Slow', 4, 600, 2, 200], ['Unsold', 0, 0, 0, 50]] as [$name, $units, $revenue, $returns, $stock]) {
            $product = $store->products()->create(['name' => $name, 'external_id' => $name, 'stock' => $stock]);
            if ($units > 0) {
                ProductSale::query()->create(['store_id' => $store->id, 'product_id' => $product->id, 'period' => $month,
                    'orders' => $units, 'units' => $units, 'revenue' => $revenue, 'returns' => $returns]);
            }
        }

        $this->actingAs($store->owner)->get('/store/performance')
            ->assertInertia(fn (Assert $page) => $page->missing('signals')
                ->reloadOnly('signals', fn (Assert $reload) => $reload
                    ->where('signals.velocity.weight', 45)
                    ->where('signals.velocity.with_sales', 2)
                    ->where('signals.velocity.products', 3)
                    ->where('signals.velocity.best.0.name', 'Fast')
                    ->where('signals.velocity.weakest.0.name', 'Slow')
                    ->where('signals.returns.lower_is_better', true)
                    ->where('signals.returns.best.0.name', 'Fast')
                    ->where('signals.returns.best.0.value', 5)
                    ->where('signals.turnover.best.0.name', 'Fast')
                    ->where('signals.add_to_cart.measured', 0)
                    ->etc()));
    }
}
