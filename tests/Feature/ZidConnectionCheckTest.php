<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Zid\ConnectionCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZidConnectionCheckTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mojo.zid.client_id' => 'client', 'mojo.zid.client_secret' => 'secret']);

        $this->owner = User::factory()->create();
        $this->store = app(CreateStore::class)->handle($this->owner, ['name' => 'Test store']);
        $this->store->update([
            'connection_status' => 'connected',
            'zid_authorization' => 'jwt',
            'zid_access_token' => 'manager-token',
            'zid_refresh_token' => 'refresh',
            'zid_token_expires_at' => now()->addYears(2),
        ]);
    }

    private function fakeZid(array $orders, int $productsStatus = 200): void
    {
        Http::fake([
            'api.zid.sa/v1/managers/account/profile*' => Http::response(['user' => ['store' => ['id' => 777, 'title' => 'متجري']]]),
            'api.zid.sa/v1/products*' => Http::response(['results' => [['id' => 1, 'name' => 'عباية']]], $productsStatus),
            'api.zid.sa/v1/managers/store/orders*' => Http::response(['orders' => $orders]),
        ]);
    }

    private function statuses(array $result): array
    {
        return collect($result['checks'])->pluck('status', 'key')->all();
    }

    public function test_a_healthy_connection_passes_every_check()
    {
        $this->fakeZid([['id' => 10, 'order_status' => ['code' => 'delivered'], 'products' => [['id' => 1, 'quantity' => 2]]]]);
        $product = Product::query()->create(['store_id' => $this->store->id, 'name' => 'عباية', 'price' => 300, 'performance' => 'winner']);
        ProductSale::query()->create(['store_id' => $this->store->id, 'product_id' => $product->id, 'period' => now()->startOfMonth(), 'units' => 2]);
        SyncRun::query()->create(['store_id' => $this->store->id, 'type' => 'zid_sync', 'status' => 'completed']);

        $result = app(ConnectionCheck::class)->run($this->store);

        $this->assertTrue($result['ok']);
        $this->assertSame([
            'app' => 'pass', 'authorized' => 'pass', 'token' => 'pass', 'profile' => 'pass', 'products' => 'pass',
            'orders' => 'pass', 'order_lines' => 'pass', 'imported' => 'pass', 'analysis' => 'pass',
        ], $this->statuses($result));

        // The orders request asks for the format that includes products.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'managers/store/orders')
            && $request['payload_type'] === 'default'
            && $request->hasHeader('X-Manager-Token', 'manager-token')
            && $request->hasHeader('Authorization', 'Bearer jwt'));
    }

    public function test_a_missing_permission_and_orders_without_products_are_reported()
    {
        $this->fakeZid([['id' => 10, 'order_status' => ['code' => 'delivered']]], productsStatus: 403);

        $result = app(ConnectionCheck::class)->run($this->store);
        $statuses = $this->statuses($result);

        $this->assertFalse($result['ok']);
        $this->assertSame('fail', $statuses['products']);
        $this->assertSame(403, collect($result['checks'])->firstWhere('key', 'products')['params']['http']);
        $this->assertSame('fail', $statuses['order_lines']);
    }

    public function test_an_unconnected_store_fails_early_and_skips_the_api_steps()
    {
        $this->store->update(['zid_access_token' => null, 'zid_authorization' => null, 'connection_status' => 'disconnected']);
        Http::fake();

        $statuses = $this->statuses(app(ConnectionCheck::class)->run($this->store));

        $this->assertSame('fail', $statuses['authorized']);
        $this->assertSame('skip', $statuses['profile']);
        $this->assertSame('skip', $statuses['orders']);
        Http::assertNothingSent();
    }

    public function test_the_owner_can_run_the_check_from_the_settings_page()
    {
        $this->fakeZid([]);

        $this->actingAs($this->owner)
            ->post(route('store.integrations.zid.check'))
            ->assertRedirect();

        $this->actingAs($this->owner)->get(route('store.settings.integration'))
            ->assertInertia(fn ($page) => $page->has('check.checks', 9));
    }
}
