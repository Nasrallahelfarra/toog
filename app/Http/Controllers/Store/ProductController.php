<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\CatalogBrowser;
use App\Support\CurrentStore;
use App\Support\RevenueVisibility;
use App\Support\StoreAccess;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One product's page: its details, sales, place in its category and the
 * expert recommendation for it.
 */
class ProductController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function show(Product $product, CatalogBrowser $catalog, StoreAccess $access): Response
    {
        $store = $this->current->require();
        abort_unless($product->store_id === $store->id, 404);
        // The product page is its sales and performance. A staff member
        // reaches it through the pages that show those; holding a product
        // does not open it, because how it sells is the manager's to see.
        abort_unless($access->can('catalog', 'performance', 'stagnant'), 403);

        $detail = $catalog->productDetail($store, $product);

        return Inertia::render('store/products/show', RevenueVisibility::apply($store, [
            'product' => [
                ...$product->only(['id', 'name', 'sku', 'external_id', 'category', 'image_url', 'performance', 'performance_score',
                    'units_sold', 'views', 'no_sales_days', 'is_novel']),
                'price' => $product->price !== null ? (float) $product->price : null,
                'revenue' => (float) $product->revenue,
                'add_to_cart_rate' => (float) $product->add_to_cart_rate,
                'return_rate' => (float) $product->return_rate,
                'features' => $product->features ?? [],
                'launched_at' => $product->launched_at?->toDateString(),
                'classification_reason' => $product->performanceReason(),
            ],
            ...$detail,
        ]) + [
            // Polled while the expert works on a recommendation.
            'recovery' => fn () => $access->can('stagnant') ? $product->recovery?->presentation() : null,
            'assignee' => $product->assignee?->only(['id', 'name']),
        ]);
    }
}
