<?php

namespace App\Services\Analytics;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Support\Vocabulary;
use Illuminate\Support\Collection;

/**
 * Read-side analytics for module 1: sales trend, category breakdown, the
 * success-attribute profile (FR-1.5), data-quality checks (FR-1.7) and
 * products at risk of becoming slow movers.
 */
class StorePerformance
{
    public const PROFILE_GROUPS = ['colors', 'patterns', 'materials', 'styles', 'price_tiers'];

    public function __construct(private readonly PerformanceClassifier $classifier) {}

    /**
     * Products per class and the criteria used to classify them (FR-1.3).
     *
     * @return array<string, mixed>
     */
    public function summary(Store $store): array
    {
        $products = Product::query()->where('store_id', $store->id)->get(['id', 'performance', 'revenue']);
        $totalRevenue = max(1, (float) $products->sum('revenue'));

        $classes = collect([Product::WINNER, Product::AVERAGE, Product::AT_RISK, Product::STAGNANT])->mapWithKeys(fn ($class) => [
            $class => [
                'count' => $products->where('performance', $class)->count(),
                'revenue_share' => (int) round($products->where('performance', $class)->sum('revenue') / $totalRevenue * 100),
            ],
        ]);

        return [
            'classes' => $classes,
            'unclassified' => $products->whereNull('performance')->count(),
            'total' => $products->count(),
            'weights' => PerformanceClassifier::WEIGHTS,
            'thresholds' => ['winner' => PerformanceClassifier::WINNER_FROM, 'stagnant' => PerformanceClassifier::STAGNANT_UNTIL],
            'needs_reclassification' => ($store->settings['performance_classification_version'] ?? 0) < PerformanceClassifier::VERSION && $products->whereNotNull('performance')->isNotEmpty(),
        ];
    }

    /**
     * How the store's products spread on each classification signal, for
     * the criteria details: coverage, median, and the best and weakest
     * products. Add-to-cart only counts products with recorded views.
     *
     * @return array<string, array<string, mixed>>
     */
    public function signals(Store $store): array
    {
        $products = Product::query()->where('store_id', $store->id)->get(['id', 'name', 'stock', 'external_id', 'launched_at', 'created_at']);
        $names = $products->pluck('name', 'id');
        $metrics = $this->classifier->metrics($store, $products)->filter(fn (array $m) => $m['has_data']);

        return collect(PerformanceClassifier::WEIGHTS)->map(function (int $weight, string $signal) use ($metrics, $names, $products) {
            $measured = $signal === 'add_to_cart' ? $metrics->filter(fn (array $m) => $m['views'] > 0) : $metrics;
            $values = $measured->map(fn (array $m) => (float) $m[$signal]);
            $ranked = PerformanceClassifier::lowerIsBetter($signal) ? $values->sort() : $values->sortDesc();
            $row = fn (float|int $value, int $id) => ['name' => $names[$id] ?? '—', 'value' => round($value, 2)];

            return [
                'weight' => $weight,
                'lower_is_better' => PerformanceClassifier::lowerIsBetter($signal),
                'products' => $products->count(),
                'with_sales' => $metrics->count(),
                'measured' => $measured->count(),
                'median' => $values->isEmpty() ? null : round((float) $values->median(), 2),
                'best' => $ranked->take(3)->map($row)->values()->all(),
                'weakest' => $ranked->reverse()->take(3)->map($row)->values()->all(),
            ];
        })->all();
    }

    /**
     * Monthly totals for the last N months, oldest first, gaps filled.
     *
     * @return list<array{period: string, revenue: float, units: int, orders: int, returns: int}>
     */
    public function monthlyTrend(Store $store, int $months = 12): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $rows = ProductSale::query()
            ->where('store_id', $store->id)
            ->where('period', '>=', $start->toDateString())
            ->get(['period', 'revenue', 'units', 'orders', 'returns'])
            ->groupBy(fn (ProductSale $s) => $s->period->format('Y-m'));

        return collect(range(0, $months - 1))->map(function ($i) use ($start, $rows) {
            $key = $start->addMonths($i)->format('Y-m');
            $group = $rows->get($key, collect());

            return [
                'period' => $key.'-01',
                'revenue' => round((float) $group->sum('revenue'), 2),
                'units' => (int) $group->sum('units'),
                'orders' => (int) $group->sum('orders'),
                'returns' => (int) $group->sum('returns'),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(Store $store): array
    {
        return Product::query()->where('store_id', $store->id)->get()
            ->groupBy(fn (Product $p) => $p->category ?? '')
            ->map(fn (Collection $items, string $category) => [
                'category' => $category ?: null,
                'products' => $items->count(),
                'revenue' => round((float) $items->sum('revenue'), 2),
                'units' => (int) $items->sum('units_sold'),
                'winners' => $items->where('performance', Product::WINNER)->count(),
                'average' => $items->where('performance', Product::AVERAGE)->count(),
                'at_risk' => $items->where('performance', Product::AT_RISK)->count(),
                'stagnant' => $items->where('performance', Product::STAGNANT)->count(),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Success profile (FR-1.5): for each attribute value, its share among
     * winners vs among slow movers. Lift > 0 means it goes with success.
     *
     * @return array<string, list<array{key: string, winners: int, stagnant: int, lift: int, products: int}>>
     */
    public function successProfile(Store $store): array
    {
        $products = Product::query()->where('store_id', $store->id)->whereNotNull('performance')->get();
        $tiers = $this->priceTiers($products);

        $values = [
            'colors' => fn (Product $p) => $p->features['colors'] ?? [],
            'patterns' => fn (Product $p) => array_filter([$p->features['pattern'] ?? null]),
            'materials' => fn (Product $p) => array_filter([$p->features['material'] ?? null]),
            'styles' => fn (Product $p) => array_filter([$p->features['style'] ?? null]),
            'price_tiers' => fn (Product $p) => array_filter([$tiers[$p->id] ?? null]),
        ];

        $winners = $products->where('performance', Product::WINNER);
        $stagnant = $products->where('performance', Product::STAGNANT);

        return collect($values)->map(function (callable $extract) use ($products, $winners, $stagnant) {
            $share = fn (Collection $set, string $key) => $set->isEmpty()
                ? 0
                : $set->filter(fn (Product $p) => in_array($key, $extract($p), true))->count() / $set->count() * 100;

            return $products->flatMap(fn (Product $p) => $extract($p))->unique()
                ->map(fn (string $key) => [
                    'key' => $key,
                    'winners' => (int) round($share($winners, $key)),
                    'stagnant' => (int) round($share($stagnant, $key)),
                    'lift' => (int) round($share($winners, $key) - $share($stagnant, $key)),
                    'products' => $products->filter(fn (Product $p) => in_array($key, $extract($p), true))->count(),
                ])
                ->sortByDesc('lift')
                ->values()
                ->all();
        })->all();
    }

    /**
     * Data-quality report (FR-1.7). Each issue carries its count, a few
     * example product names, and a severity; guidance text lives in the UI.
     *
     * @return list<array{key: string, severity: string, count: int, samples: list<string>}>
     */
    public function dataQuality(Store $store): array
    {
        $products = Product::query()->where('store_id', $store->id)->get();
        $withSales = $this->classifier->metrics($store, $products)->filter(fn (array $m) => $m['has_data'])->keys()->flip();
        $badReturns = ProductSale::query()->where('store_id', $store->id)->whereColumn('returns', '>', 'units')->pluck('product_id')->unique()->flip();

        $issue = fn (string $key, string $severity, Collection $items) => [
            'key' => $key,
            'severity' => $severity,
            'count' => $items->count(),
            'samples' => $items->take(3)->pluck('name')->values()->all(),
        ];

        $dupNames = $products->groupBy(fn (Product $p) => mb_strtolower(trim($p->name)))->filter(fn ($g) => $g->count() > 1)->flatten();
        $dupIds = $products->whereNotNull('external_id')->groupBy('external_id')->filter(fn ($g) => $g->count() > 1)->flatten();

        return collect([
            $issue('missing_category', 'warning', $products->filter(fn (Product $p) => blank($p->category))),
            $issue('invalid_price', 'danger', $products->filter(fn (Product $p) => $p->price === null || (float) $p->price <= 0)),
            $issue('missing_features', 'warning', $products->filter(fn (Product $p) => empty($p->features['colors']) || empty($p->features['pattern']))),
            $issue('missing_image', 'info', $products->filter(fn (Product $p) => blank($p->image_url))),
            $issue('duplicate_names', 'warning', $dupNames),
            $issue('duplicate_ids', 'danger', $dupIds),
            $issue('returns_exceed_units', 'danger', $products->filter(fn (Product $p) => $badReturns->has($p->id))),
            $issue('negative_stock', 'warning', $products->filter(fn (Product $p) => $p->stock < 0)),
            $issue('no_sales_history', 'info', $products->filter(fn (Product $p) => ! $withSales->has($p->id))),
        ])->filter(fn ($i) => $i['count'] > 0)->values()->all();
    }

    /**
     * Use the saved classification everywhere; a separate warning algorithm
     * would disagree with the catalog filters, counts and recommendation cards.
     *
     * @return list<array<string, mixed>>
     */
    public function atRisk(Store $store, int $limit = 6): array
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->where('performance', Product::AT_RISK)
            ->orderByDesc('stock')->orderBy('performance_score')->orderBy('id')->limit($limit)->get()
            ->map(fn (Product $p) => [
                'reason' => $p->performanceReason(),
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category,
                'performance' => $p->performance,
                'stock' => $p->stock,
                'months_of_stock' => $p->units_sold > 0 && $p->turnover_days !== null ? round($p->turnover_days / 30, 1) : null,
                'colors' => $p->features['colors'] ?? [],
            ])
            ->all();
    }

    public function atRiskCount(Store $store): int
    {
        return Product::query()->where('store_id', $store->id)->where('performance', Product::AT_RISK)->count();
    }

    /**
     * Monthly sales for a set of products, keyed by product id.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array{period: string, units: int, revenue: float}>>
     */
    public function history(array $productIds, int $months = 12): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        return ProductSale::query()
            ->whereIn('product_id', $productIds)
            ->where('period', '>=', $start->toDateString())
            ->orderBy('period')
            ->get(['product_id', 'period', 'units', 'revenue'])
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => $rows->map(fn (ProductSale $s) => [
                'period' => $s->period->toDateString(),
                'units' => $s->units,
                'revenue' => (float) $s->revenue,
            ])->values()->all())
            ->all();
    }

    /**
     * Low / mid / high price tier of each product within its category
     * (thirds of the category's price range).
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, string>
     */
    public function priceTiers(Collection $products): array
    {
        $tiers = [];

        foreach ($products->groupBy(fn (Product $p) => $p->category ?? '') as $items) {
            $prices = $items->pluck('price')->filter(fn ($v) => $v !== null && (float) $v > 0)->map(fn ($v) => (float) $v)->sort()->values();

            if ($prices->count() < 3) {
                continue;
            }

            $low = $prices[(int) floor(($prices->count() - 1) / 3)];
            $high = $prices[(int) floor(($prices->count() - 1) * 2 / 3)];

            foreach ($items as $p) {
                if ($p->price === null || (float) $p->price <= 0) {
                    continue;
                }

                $tiers[$p->id] = (float) $p->price <= $low ? 'low' : ((float) $p->price >= $high ? 'high' : 'mid');
            }
        }

        return $tiers;
    }

    /**
     * Store categories for pickers.
     *
     * @return list<string>
     */
    public function categoryNames(Store $store): array
    {
        return Product::query()->where('store_id', $store->id)->whereNotNull('category')
            ->distinct()->orderBy('category')->pluck('category')->all();
    }

    /**
     * Map a free-text label (Arabic or English) or key to a vocabulary key.
     */
    public static function vocabularyKey(string $group, ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $list = match ($group) {
            'colors' => Vocabulary::colors(),
            'patterns' => Vocabulary::patterns(),
            'materials' => Vocabulary::materials(),
            'styles' => Vocabulary::styles(),
            'seasons' => Vocabulary::seasons(),
            default => [],
        };

        $needle = mb_strtolower(str_replace([' ', '-'], '_', $value));

        if (isset($list[$needle])) {
            return $needle;
        }

        foreach ($list as $key => $labels) {
            if (mb_strtolower($labels['en']) === mb_strtolower($value) || $labels['ar'] === $value) {
                return $key;
            }
        }

        return null;
    }
}
