<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Analytics\PerformanceClassifier;
use App\Services\Analytics\StorePerformance;
use App\Services\Catalog\FeatureExtraction;
use App\Services\Zid\ZidClient;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Store performance" (module 1): classification, sales trend, success
 * profile, data quality and the product table.
 */
class PerformanceController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request, StorePerformance $performance, FeatureExtraction $extraction): Response
    {
        $store = $this->current->require();
        $filters = $request->only(['class', 'category', 'search', 'sort']);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->when($filters['class'] ?? null, fn (Builder $q, $class) => $class === 'unclassified' ? $q->whereNull('performance') : $q->where('performance', $class))
            ->when($filters['category'] ?? null, fn (Builder $q, $category) => $q->where('category', $category))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('external_id', 'like', "%{$search}%")))
            ->when(
                $filters['sort'] ?? 'score',
                fn (Builder $q, $sort) => match ($sort) {
                    'revenue' => $q->orderByDesc('revenue'),
                    'units' => $q->orderByDesc('units_sold'),
                    'turnover' => $q->orderByDesc('turnover_days'),
                    'returns' => $q->orderByDesc('return_rate'),
                    default => $q->orderByRaw('performance_score IS NULL')->orderByDesc('performance_score'),
                },
            )
            ->paginate(15)
            ->withQueryString();

        $history = $performance->history($products->getCollection()->pluck('id')->all());

        $products->through(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'external_id' => $p->external_id,
            'category' => $p->category,
            'price' => $p->price !== null ? (float) $p->price : null,
            'image_url' => $p->image_url,
            'features' => $p->features ?? [],
            'performance' => $p->performance,
            'performance_score' => $p->performance_score,
            'units_sold' => $p->units_sold,
            'revenue' => (float) $p->revenue,
            'views' => $p->views,
            'add_to_cart_rate' => (float) $p->add_to_cart_rate,
            'return_rate' => (float) $p->return_rate,
            'turnover_days' => $p->turnover_days,
            'stock' => $p->stock,
            'is_novel' => $p->is_novel,
            'launched_at' => $p->launched_at?->toDateString(),
            'history' => $history[$p->id] ?? [],
        ]);

        return Inertia::render('store/performance', [
            // Closures, so the extraction progress poll does not recompute the whole catalog.
            'summary' => fn () => $performance->summary($store),
            'trend' => fn () => $performance->monthlyTrend($store),
            'categories' => fn () => $performance->categories($store),
            'profile' => fn () => $performance->successProfile($store),
            'quality' => fn () => $performance->dataQuality($store),
            'products' => $products,
            'filters' => $filters,
            'categoryNames' => fn () => $performance->categoryNames($store),
            'sync' => [
                'status' => $store->connection_status,
                'last_synced_at' => $store->last_synced_at?->toIso8601String(),
                'can_sync' => $store->isConnected() && filled($store->zid_access_token) && ZidClient::configured(),
                'missing_features' => $extraction->pending($store->id)->count(),
            ],
            'extraction' => $extraction->latest($store),
        ]);
    }

    /**
     * Re-run the winner / average / stagnant classification on current data.
     */
    public function reclassify(PerformanceClassifier $classifier): RedirectResponse
    {
        $store = $this->current->require();
        $classifier->classify($store);

        Activity::log('data.reclassified', $store);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('store.reclassified')]);

        return back();
    }

    /**
     * Queue attribute extraction (FR-1.4) over every product missing attributes.
     */
    public function extract(Request $request, FeatureExtraction $extraction): RedirectResponse
    {
        $store = $this->current->require();
        $run = $extraction->start($store, $request->user()->id);

        if (! $run) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('store.nothing_to_extract')]);

            return back();
        }

        if ($run->wasRecentlyCreated) {
            Activity::log('data.features_extraction', $store, ['products' => $run->stats['total'] ?? 0]);
        }

        Inertia::flash('toast', match (true) {
            $run->status === 'failed' => ['type' => 'error', 'message' => __('store.extraction_failed')],
            $run->wasRecentlyCreated => ['type' => 'success', 'message' => __('store.extraction_started', ['count' => $run->stats['total'] ?? 0])],
            default => ['type' => 'info', 'message' => __('store.extraction_running')],
        });

        return back();
    }
}
