<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\ProductSale;
use App\Models\User;
use App\Services\Imports\CatalogImporter;
use App\Services\Zid\ZidMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Stock is not real: pieces are made to order, so the quantity a store's
 * platform reports proves nothing. It is not imported, read, shown or sent
 * to the AI anywhere; products are judged by their sales. (The performance
 * rules themselves are covered in PerformanceClassificationTest.)
 */
class StockIsNotASignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_is_not_imported_from_zid_or_from_a_file(): void
    {
        $mapped = app(ZidMapper::class)->product(['id' => 'p-1', 'name' => 'Abaya', 'price' => 300, 'quantity' => 55, 'stock' => 55]);

        $this->assertNotNull($mapped);
        $this->assertSame('Abaya', $mapped['name']);
        $this->assertArrayNotHasKey('stock', $mapped);
        $this->assertNotContains('stock', CatalogImporter::PRODUCT_COLUMNS);
    }

    public function test_the_store_pages_send_no_stock_and_cannot_sort_by_it(): void
    {
        config(['inertia.ssr.enabled' => false]);
        $store = app(CreateStore::class)->handle(User::factory()->create(), ['name' => 'Store']);
        $product = $store->products()->create(['name' => 'Abaya', 'external_id' => 'a', 'category' => 'عبايات', 'price' => 300, 'stock' => 500]);
        ProductSale::query()->create(['store_id' => $store->id, 'product_id' => $product->id, 'period' => now()->startOfMonth(), 'orders' => 3, 'units' => 3, 'revenue' => 900]);

        // An old link sorting by stock simply falls back to the default order.
        $this->actingAs($store->owner)->get('/store/catalog?sort=stock')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->missing('products.data.0.stock')->missing('products.data.0.turnover_days'));
        $this->get('/store/performance')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->missing('products.data.0.stock')->missing('products.data.0.turnover_days'));
        $this->get("/store/products/{$product->id}")
            ->assertInertia(fn (Assert $page) => $page->where('product.name', 'Abaya')->missing('product.stock')->missing('product.turnover_days'));
    }

    public function test_no_application_code_reads_the_stock_column(): void
    {
        // The column stays so existing rows are not lost, but nothing may
        // read it again: this is the guard against it creeping back in as a
        // signal, a sort order or a line in an AI prompt.
        $reads = ['->stock', 'products.stock', "'stock' =>", '"stock"', 'months_of_stock', 'in_stock', 'low_stock', 'turnover_days >=', "'restock'"];
        // Competitors' own posts saying "back in stock" are their marketing, not our stock.
        $allowed = [
            'Models'.DIRECTORY_SEPARATOR.'Product.php',
            'Services'.DIRECTORY_SEPARATOR.'Market'.DIRECTORY_SEPARATOR.'CompetitorRadar.php',
            'Services'.DIRECTORY_SEPARATOR.'Market'.DIRECTORY_SEPARATOR.'PostTagger.php',
            'Services'.DIRECTORY_SEPARATOR.'Market'.DIRECTORY_SEPARATOR.'Fetchers'.DIRECTORY_SEPARATOR.'SallaFetcher.php',
        ];
        $found = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getRelativePathname(), $allowed, true)) {
                continue;
            }

            foreach ($reads as $needle) {
                if (str_contains($file->getContents(), $needle)) {
                    $found[] = $file->getRelativePathname().' uses '.$needle;
                }
            }
        }

        $this->assertSame([], $found, 'Stock must not be read anywhere: judge products by their sales.');
    }
}
