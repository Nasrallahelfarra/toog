<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\SyncStoreData;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use App\Services\Analytics\PerformanceClassifier;
use App\Services\Catalog\DemoCatalogCleanup;
use App\Support\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DemoCatalogDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Product $demo;

    private Product $real;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mojo.demo_catalog_cleanup_enabled' => true]);
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['email' => 'lama@demo.sa']);
        $this->store = app(CreateStore::class)->handle($this->owner, ['name' => 'Dar Lama']);
        $this->store->update([
            'settings' => ['demo_seed' => 'lama@demo.sa'], 'platform_store_id' => '41960',
            'zid_authorization' => 'private-app-token', 'zid_access_token' => 'private-manager-token',
            'connection_status' => 'connected',
        ]);
        $this->demo = $this->store->products()->create([
            'name' => 'Demo abaya', 'external_id' => 'Z11001', 'sku' => 'DAR-1001', 'price' => 100,
        ]);
        $this->real = $this->store->products()->create([
            'name' => 'Imported Zid product', 'external_id' => 'real-zid-product', 'sku' => 'DAR-1001', 'price' => 250,
        ]);
        foreach ([$this->demo, $this->real] as $product) {
            ProductSale::query()->create([
                'store_id' => $this->store->id, 'product_id' => $product->id,
                'period' => now()->startOfMonth(), 'orders' => 2, 'units' => 2, 'revenue' => (float) $product->price * 2,
            ]);
        }
        $this->actingAs($this->owner);
    }

    private function token(): string
    {
        return app(DemoCatalogCleanup::class)->preview($this->store->fresh())['preview_token'];
    }

    public function test_owner_can_preview_confirm_and_repeat_without_losing_real_products_or_credentials(): void
    {
        $before = $this->store->fresh()->getRawOriginal();
        $realSales = $this->real->sales()->firstOrFail()->getRawOriginal();
        $token = $this->token();
        $this->get('/store')->assertOk()->assertInertia(fn ($page) => $page
            ->where('demoCatalog.products_count', 1)->where('demoCatalog.sales_count', 1)
            ->where('demoCatalog.remaining_count', 1)->where('demoCatalog.preview_token', $token)
            ->where('demoCatalog.items.0.id', $this->demo->id))
            ->assertDontSee('private-manager-token');
        $this->assertDatabaseHas('products', ['id' => $this->demo->id]);

        $this->post('/store/catalog/clear-demo', ['preview_token' => $token])
            ->assertRedirect('/store')->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('products', ['id' => $this->demo->id]);
        $this->assertDatabaseMissing('product_sales', ['product_id' => $this->demo->id]);
        $this->assertSame($before, $this->store->fresh()->getRawOriginal());
        $this->assertSame($realSales, $this->real->sales()->firstOrFail()->getRawOriginal());
        $this->assertSame(2, $this->real->fresh()->units_sold);
        $this->assertSame('500.00', $this->real->fresh()->revenue);
        $this->assertDatabaseHas('activity_logs', ['action' => 'catalog.demo_cleared', 'user_id' => $this->owner->id, 'acting_as_admin' => false]);
        $this->get('/store')->assertInertia(fn ($page) => $page
            ->where('demoCatalog.products_count', 0)->where('demoCatalog.remaining_count', 1)
            ->where('kpis.revenue', 500));
        $this->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame(1, ActivityLog::query()->where('action', 'catalog.demo_cleared')->count());
        Http::assertNothingSent();
    }

    public function test_missing_or_forged_confirmation_cannot_delete(): void
    {
        foreach ([[], ['preview_token' => str_repeat('0', 64)]] as $payload) {
            $this->from('/store')->post('/store/catalog/clear-demo', $payload)->assertSessionHasErrors('preview_token');
        }
        $this->assertDatabaseHas('products', ['id' => $this->demo->id]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'catalog.demo_cleared']);
    }

    public function test_changed_sales_or_store_identity_requires_a_fresh_preview(): void
    {
        $token = $this->token();
        $this->demo->sales()->update(['revenue' => 900]);
        $this->from('/store')->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertSessionHasErrors('preview_token');
        $token = $this->token();
        $this->store->update(['platform_store_id' => '12345']);
        $this->from('/store')->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertSessionHasErrors('preview_token');
        $this->assertDatabaseHas('products', ['id' => $this->demo->id]);
        // A fresh browser request resolves the changed store and consumes flashed errors.
        $this->app->forgetInstance(CurrentStore::class);
        $this->get('/store')->assertOk();
        $this->post('/store/catalog/clear-demo', ['preview_token' => $this->token()])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('products', ['id' => $this->demo->id]);
    }

    public function test_another_store_owner_cannot_target_lamas_catalog_even_with_its_preview_token(): void
    {
        $token = $this->token();
        $other = User::factory()->create();
        $otherStore = app(CreateStore::class)->handle($other, ['name' => 'Another store']);
        $this->actingAs($other)->withSession([CurrentStore::SESSION_KEY => $otherStore->id]);
        $this->get('/store')->assertInertia(fn ($page) => $page->where('demoCatalog', null));
        $this->post('/store/catalog/clear-demo', [
            'preview_token' => $token, 'store_id' => $this->store->id, 'email' => 'lama@demo.sa',
        ])->assertSessionHasErrors('preview_token');
        $this->assertDatabaseHas('products', ['id' => $this->demo->id]);
    }

    public function test_store_members_cannot_use_the_owner_only_action_but_admins_can_and_are_logged(): void
    {
        $token = $this->token();
        $member = User::factory()->create();
        $this->store->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member)->withSession([CurrentStore::SESSION_KEY => $this->store->id]);
        $this->get('/store')->assertInertia(fn ($page) => $page->where('demoCatalog', null));
        $this->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'catalog.demo_cleared', 'user_id' => $admin->id, 'acting_as_admin' => true]);
        $this->assertDatabaseMissing('products', ['id' => $this->demo->id]);
    }

    public function test_disabling_the_temporary_action_hides_it_and_blocks_the_endpoint(): void
    {
        $token = $this->token();
        config(['mojo.demo_catalog_cleanup_enabled' => false]);
        $this->get('/store')->assertInertia(fn ($page) => $page->where('demoCatalog', null));
        $this->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $this->demo->id]);
    }

    public function test_queued_or_running_sync_is_reported_without_deleting_anything(): void
    {
        $token = $this->token();
        $lock = Cache::lock(SyncStoreData::lockKey($this->store), 300);
        $this->assertTrue($lock->get());
        $this->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertSessionHasErrors('preview_token');
        $lock->release();
        $this->store->syncRuns()->create(['type' => 'zid_sync', 'status' => 'running', 'started_at' => now()]);
        $this->get('/store')->assertInertia(fn ($page) => $page->where('demoCatalog.sync_running', true));
        $this->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertSessionHasErrors('preview_token');
        $this->assertDatabaseHas('products', ['id' => $this->demo->id]);
    }

    public function test_a_failure_rolls_back_the_web_operation_and_returns_a_form_error(): void
    {
        $token = $this->token();
        $this->mock(PerformanceClassifier::class)->shouldReceive('classify')->once()->andThrow(new RuntimeException('test failure'));
        $this->post('/store/catalog/clear-demo', ['preview_token' => $token])->assertSessionHasErrors('preview_token');
        $this->assertDatabaseHas('products', ['id' => $this->demo->id]);
        $this->assertDatabaseHas('product_sales', ['product_id' => $this->demo->id]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'catalog.demo_cleared']);
    }
}
