<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogBrowser;
use App\Support\CurrentStore;
use App\Support\RevenueVisibility;
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
        $showRevenue = $store->showsRevenue();
        // Without revenue the catalog ranks by units sold, and never by money.
        if (! $showRevenue && in_array($filters['sort'] ?? 'revenue', ['revenue', ''], true)) {
            $filters['sort'] = 'units';
        }
        $available = $catalog->availableMonths($store);
        // A chosen month replaces the 3/6/12-month range; only months with sales can be picked.
        $month = in_array($request->string('month')->toString(), $available, true) ? $request->string('month')->toString() : null;

        return Inertia::render('store/catalog', [
            'months' => $months,
            'month' => $month,
            'availableMonths' => $available,
            'category' => $category,
            'filters' => $filters,
            'summary' => fn () => RevenueVisibility::apply($store, $catalog->summary($store, $category, $months, $month)),
            'trend' => fn () => RevenueVisibility::apply($store, $catalog->trend($store, $category, $months, $month)),
            'categories' => fn () => RevenueVisibility::apply($store, $catalog->categories($store, $months, $month, byUnits: ! $showRevenue)),
            'products' => fn () => RevenueVisibility::apply($store, $catalog->products($store, $category, $filters, $month)),
        ]);
    }
}
