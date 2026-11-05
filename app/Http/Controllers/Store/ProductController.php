<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\CatalogBrowser;
use App\Support\CurrentStore;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One product's page: its details, sales, place in its category and the
 * expert recommendation for it.
 */
class ProductController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function show(Product $product, CatalogBrowser $catalog): Response
    {
        $store = $this->current->require();
        abort_unless($product->store_id === $store->id, 404);

        return Inertia::render('store/products/show', [
            'product' => [
                ...$product->only(['id', 'name', 'sku', 'external_id', 'category', 'image_url', 'performance', 'performance_score',
                    'stock', 'units_sold', 'views', 'turnover_days', 'is_novel']),
                'price' => $product->price !== null ? (float) $product->price : null,
                'revenue' => (float) $product->revenue,
                'add_to_cart_rate' => (float) $product->add_to_cart_rate,
                'return_rate' => (float) $product->return_rate,
                'features' => $product->features ?? [],
                'launched_at' => $product->launched_at?->toDateString(),
                'classification_reason' => $product->performanceReason(),
            ],
            ...$catalog->productDetail($store, $product),
            // Polled while the expert works on a recommendation.
            'recovery' => fn () => $product->recovery?->presentation(),
        ]);
    }
}
