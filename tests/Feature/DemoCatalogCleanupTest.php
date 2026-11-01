<?php

namespace Tests\Feature;

use App\Jobs\SyncStoreData;
use App\Models\ActivityLog;
use App\Models\ProductSale;
use App\Models\Store;
use App\Services\Analytics\PerformanceClassifier;
use Database\Seeders\DemoStoresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoCatalogCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function seededStore(): Store
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->seed(DemoStoresSeeder::class);
        $store = Store::query()->where('settings->demo_seed', 'lama@demo.sa')->firstOrFail();
        $store->update([
            'platform_store_id' => '41960', 'zid_authorization' => 'test-app-token',
            'zid_access_token' => 'test-manager-token', 'zid_refresh_token' => 'test-refresh-token',
        ]);

        return $store;
    }

    public function test_preview_keeps_the_complete_catalog_and_connection_unchanged(): void
    {
        $store = $this->seededStore();
        $before = $this->snapshot(['products', 'product_sales', 'stores', 'users', 'activity_logs']);

        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960'])
            ->expectsOutputToContain('Matched demo products: 43')
            ->expectsOutputToContain('Preview only. Nothing changed.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot(array_keys($before)));
        $this->assertNull(Cache::get(SyncStoreData::lockKey($store)));
        Http::assertNothingSent();
    }

    public function test_apply_removes_only_seeded_products_and_sales_archives_them_and_can_be_repeated(): void
    {
        $store = $this->seededStore();
        $demoProducts = $store->products()->orderBy('id')->get()->map->getRawOriginal()->all();
        $demoSales = $store->sales()->orderBy('id')->get()->map->getRawOriginal()->all();

        // Protect imported rows even if the SKU or an ID prefix resembles a fixture.
        $real = $store->products()->create([
            'name' => 'Real Zid product', 'external_id' => '35d08b44-bc34-4ce4-9daf-c8a06c876662',
            'sku' => $demoProducts[0]['sku'], 'price' => 90,
        ]);
        $fileProduct = $store->products()->create(['name' => 'Uploaded product', 'external_id' => null]);
        $similar = $store->products()->create(['name' => 'Real Z ID', 'external_id' => 'Z99999999', 'sku' => 'REAL-1']);
        ProductSale::query()->create([
            'store_id' => $store->id, 'product_id' => $real->id, 'period' => now()->startOfMonth(),
            'orders' => 2, 'units' => 3, 'revenue' => 270,
        ]);
        $realSale = $real->sales()->firstOrFail()->getRawOriginal();
        $otherStores = DB::table('products')->where('store_id', '!=', $store->id)->orderBy('id')->get()->toJson();
        $otherSales = DB::table('product_sales')->where('store_id', '!=', $store->id)->orderBy('id')->get()->toJson();
        $untouched = $this->snapshot(['stores', 'users', 'store_user', 'evaluations', 'designs', 'competitors', 'trends', 'customer_reviews']);

        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960', '--apply' => true])
            ->expectsOutputToContain('Demo catalog removed')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$real->id, $fileProduct->id, $similar->id], $store->products()->pluck('id')->all());
        $this->assertSame($realSale, $real->sales()->firstOrFail()->getRawOriginal());
        $this->assertSame(3, $real->fresh()->units_sold);
        $this->assertSame('270.00', $real->fresh()->revenue);
        $this->assertSame($otherStores, DB::table('products')->where('store_id', '!=', $store->id)->orderBy('id')->get()->toJson());
        $this->assertSame($otherSales, DB::table('product_sales')->where('store_id', '!=', $store->id)->orderBy('id')->get()->toJson());
        $this->assertSame($untouched, $this->snapshot(array_keys($untouched)));
        foreach ($demoProducts as $product) {
            $this->assertDatabaseMissing('products', ['id' => $product['id']]);
            $this->assertDatabaseMissing('product_sales', ['product_id' => $product['id']]);
        }
        $archive = ActivityLog::query()->where('action', 'catalog.demo_cleared')->sole();
        $this->assertEquals($demoProducts, $archive->properties['archive']['products']);
        $this->assertEquals($demoSales, $archive->properties['archive']['product_sales']);
        $this->assertStringNotContainsString('test-manager-token', json_encode($archive->properties));

        // The retained demo marker stops later db:seed runs from restoring fake data.
        $after = $this->snapshot(['products', 'product_sales', 'activity_logs']);
        $this->seed(DemoStoresSeeder::class);
        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960', '--apply' => true])
            ->expectsOutputToContain('No matching demo catalog remains')
            ->assertSuccessful();
        $this->assertSame($after, $this->snapshot(array_keys($after)));
        Http::assertNothingSent();
    }

    public function test_wrong_owner_store_marker_or_missing_credentials_never_delete_data(): void
    {
        $store = $this->seededStore();
        $before = $this->snapshot(['products', 'product_sales']);
        foreach ([
            ['email' => 'lama@demo.sa', '--zid-store' => '3278264'],
            ['email' => 'hadaya@demo.sa', '--zid-store' => '41960'],
            ['email' => 'unrelated@example.com', '--zid-store' => '41960'],
            ['email' => 'lama@demo.sa'],
        ] as $options) {
            $this->artisan('demo:clear-catalog', [...$options, '--apply' => true])->assertFailed();
        }
        $store->update(['settings' => []]);
        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960', '--apply' => true])->assertFailed();
        $store->update(['settings' => ['demo_seed' => 'lama@demo.sa'], 'zid_access_token' => null]);
        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960', '--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot(array_keys($before)));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'catalog.demo_cleared']);
    }

    public function test_queued_and_running_syncs_block_cleanup_without_releasing_their_lock(): void
    {
        $store = $this->seededStore();
        $before = $this->snapshot(['products', 'product_sales']);
        $key = SyncStoreData::lockKey($store);
        $queueLock = Cache::lock($key, 300);
        $this->assertTrue($queueLock->get());
        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960', '--apply' => true])
            ->expectsOutputToContain('already queued/running')->assertFailed();
        $this->assertFalse(Cache::lock($key, 300)->get());
        $queueLock->release();
        $store->syncRuns()->create(['type' => 'zid_sync', 'status' => 'running', 'started_at' => now()]);
        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960', '--apply' => true])
            ->expectsOutputToContain('A Zid sync is running')->assertFailed();
        $this->assertSame($before, $this->snapshot(array_keys($before)));
    }

    public function test_changed_fixture_identifiers_and_images_are_preserved(): void
    {
        $store = $this->seededStore();
        $products = $store->products()->orderBy('id')->take(2)->get();
        $products[0]->update(['sku' => 'customized-sku']);
        $products[1]->update(['image_url' => 'https://example.com/real-product.jpg']);
        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960', '--apply' => true])->assertSuccessful();
        $this->assertEqualsCanonicalizing($products->modelKeys(), $store->products()->pluck('id')->all());
    }

    public function test_classification_failure_rolls_back_deletion_and_archive(): void
    {
        $store = $this->seededStore();
        $before = $this->snapshot(['products', 'product_sales', 'activity_logs']);
        $this->mock(PerformanceClassifier::class)
            ->shouldReceive('classify')->once()->andThrow(new RuntimeException('Classification failed'));
        $this->artisan('demo:clear-catalog', ['email' => 'lama@demo.sa', '--zid-store' => '41960', '--apply' => true])
            ->expectsOutputToContain('Classification failed')->assertFailed();
        $this->assertSame($before, $this->snapshot(array_keys($before)));
        $this->assertTrue(Cache::lock(SyncStoreData::lockKey($store), 1)->get());
    }

    private function snapshot(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
