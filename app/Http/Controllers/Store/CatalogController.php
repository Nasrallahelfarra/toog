<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogBrowser;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catalog browser: the store's categories, the products in each, and their
 * sales over the selected months.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request, CatalogBrowser $catalog): Response
    {
        $store = $this->current->require();
        $months = in_array($request->integer('months'), CatalogBrowser::RANGES, true) ? $request->integer('months') : 12;
        $category = $request->filled('category') ? (string) $request->input('category') : null;
        $filters = $request->only(['search', 'class', 'sort']);

        return Inertia::render('store/catalog', [
            'months' => $months,
            'category' => $category,
            'filters' => $filters,
            'summary' => fn () => $catalog->summary($store, $category, $months),
            'trend' => fn () => $catalog->trend($store, $category, $months),
            'categories' => fn () => $catalog->categories($store, $months),
            'products' => fn () => $catalog->products($store, $category, $filters),
        ]);
    }
}
