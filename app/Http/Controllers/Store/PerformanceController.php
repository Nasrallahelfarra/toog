<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Analytics\ClassificationRefresh;
use App\Services\Analytics\StorePerformance;
use App\Services\Catalog\FeatureExtraction;
use App\Services\Zid\ZidClient;
use App\Support\Activity;
use App\Support\CurrentStore;
use App\Support\RevenueVisibility;
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
        if (! $store->showsRevenue() && ($filters['sort'] ?? null) === 'revenue') {
            $filters['sort'] = 'units';
        }

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
            'summary' => fn () => RevenueVisibility::apply($store, $performance->summary($store)),
            'trend' => fn () => RevenueVisibility::apply($store, $performance->monthlyTrend($store)),
            'categories' => fn () => RevenueVisibility::apply($store, collect($performance->categories($store))
                ->when(! $store->showsRevenue(), fn ($rows) => $rows->sortByDesc('units'))->values()->all()),
            'profile' => fn () => $performance->successProfile($store),
            'quality' => fn () => $performance->dataQuality($store),
            'products' => RevenueVisibility::apply($store, $products),
            'filters' => $filters,
            'categoryNames' => fn () => $performance->categoryNames($store),
            // Loaded only when a classification criterion is opened.
            'signals' => Inertia::optional(fn () => $store->showsRevenue()
                ? $performance->signals($store)
                : array_diff_key($performance->signals($store), ['revenue' => true])),
            'sync' => [
                'status' => $store->connection_status,
                'last_synced_at' => $store->last_synced_at?->toIso8601String(),
                'can_sync' => $store->isConnected() && filled($store->zid_access_token) && ZidClient::configured(),
                'missing_features' => $extraction->pending($store->id)->count(),
            ],
            'extraction' => $extraction->latest($store),
            'classification' => fn () => app(ClassificationRefresh::class)->latest($store),
        ]);
    }

    /**
     * Refresh all performance classes, including separate at-risk inventory.
     */
    public function reclassify(Request $request, ClassificationRefresh $refresh): RedirectResponse
    {
        $store = $this->current->require();
        // The import already classifies its saved catalog. Avoid inserting a
        // second run while its store-row lock could block the foreign key check.
        if ($store->syncRuns()->where('type', 'zid_sync')->whereIn('status', ['queued', 'running'])->exists()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('store.classification_sync_running')]);

            return back();
        }
        $run = $refresh->start($store, $request->user()->id);
        Inertia::flash('toast', $run?->status === 'failed'
            ? ['type' => 'error', 'message' => __('store.classification_queue_failed')]
            : ['type' => 'info', 'message' => __('store.classification_queued')]);

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
            isset($run->stats['resumed_at']) => ['type' => 'info', 'message' => __('store.extraction_resuming', ['count' => $run->stats['resume_done'] ?? 0])],
            default => ['type' => 'info', 'message' => __('store.extraction_running')],
        });

        return back();
    }
}
