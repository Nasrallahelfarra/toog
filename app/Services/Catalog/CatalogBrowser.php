<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read side of the catalog browser: the store's categories with their
 * sales, one category's products, and monthly sales for either.
 *
 * A category is addressed by its name; UNCATEGORIZED stands for products
 * without one, and null for the whole store. The period is either the last
 * 3, 6 or 12 months, or one chosen month ("Y-m"), compared with the month
 * before it.
 */
class CatalogBrowser
{
    public const UNCATEGORIZED = '__none__';

    public const RANGES = [3, 6, 12];

    /** Months of history behind every sparkline, chart and product line. */
    private const HISTORY = 12;

    /** How far back the month picker reaches. */
    private const PICKABLE = 36;

    /**
     * Months that have sales, newest first, for the month picker.
     *
     * @return list<string> Y-m keys
     */
    public function availableMonths(Store $store): array
    {
        return array_values(ProductSale::query()->where('store_id', $store->id)
            ->distinct()->orderByDesc('period')->limit(self::PICKABLE * 2)->pluck('period')
            ->map(fn ($period) => substr((string) $period, 0, 7))
            ->unique()->take(self::PICKABLE)->all());
    }

    /**
     * Totals for the period against the same length of time just before it.
     *
     * @return array<string, mixed>
     */
    public function summary(Store $store, ?string $category, int $months, ?string $month = null): array
    {
        $length = $month ? 1 : $months;
        $start = $this->start($store, $length, $month);
        $previous = $start->copy()->subMonths($length);
        $end = $this->end($store, $month)->format('Y-m');

        $rows = $this->sales($store, $category)
            ->where('product_sales.period', '>=', $previous->toDateString())
            ->get(['product_sales.period', 'product_sales.revenue', 'product_sales.units', 'product_sales.orders'])
            ->filter(fn (ProductSale $s) => $this->month($s) <= $end);
        [$current, $before] = $rows->partition(fn (ProductSale $s) => $this->month($s) >= $start->format('Y-m'));

        $total = fn (Collection $set, string $field) => (float) $set->sum($field);
        $delta = fn (string $field) => $total($before, $field) > 0
            ? (int) round(($total($current, $field) - $total($before, $field)) / $total($before, $field) * 100)
            : null;

        $products = $this->inCategory($store, $category);

        return [
            'revenue' => round($total($current, 'revenue'), 2),
            'units' => (int) $total($current, 'units'),
            'orders' => (int) $total($current, 'orders'),
            'products' => (clone $products)->count(),
            'avg_price' => round((float) (clone $products)->whereNotNull('price')->avg('price'), 2),
            'delta' => ['revenue' => $delta('revenue'), 'units' => $delta('units'), 'orders' => $delta('orders')],
        ];
    }

    /**
     * Monthly revenue, units and orders, oldest first, empty months filled.
     * A chosen month is shown with the year leading up to it.
     *
     * @return list<array{period: string, revenue: float, units: int, orders: int, partial: bool, selected: bool}>
     */
    public function trend(Store $store, ?string $category, int $months, ?string $month = null): array
    {
        $length = $month ? self::HISTORY : $months;
        $keys = $this->months($store, $length, $month);
        $rows = $this->sales($store, $category)
            ->where('product_sales.period', '>=', $keys[0].'-01')
            ->get(['product_sales.period', 'product_sales.revenue', 'product_sales.units', 'product_sales.orders'])
            ->groupBy(fn (ProductSale $s) => $this->month($s));

        return array_map(fn (string $key) => [
            'period' => $key.'-01',
            'revenue' => round((float) $rows->get($key, collect())->sum('revenue'), 2),
            'units' => (int) $rows->get($key, collect())->sum('units'),
            'orders' => (int) $rows->get($key, collect())->sum('orders'),
            // The current month is still filling up; the chart marks it.
            'partial' => $key === now()->format('Y-m'),
            'selected' => $key === $month,
        ], $keys);
    }

    /**
     * Every category with its products, its sales in the period, a 12-month
     * revenue line and a few of its best-selling product images. With
     * $byUnits (revenue hidden from the store) the line, the share and the
     * order all follow units sold instead.
     *
     * @return list<array<string, mixed>>
     */
    public function categories(Store $store, int $months, ?string $month = null, bool $byUnits = false): array
    {
        $measure = $byUnits ? 'units' : 'revenue';
        $start = $this->start($store, $month ? 1 : $months, $month)->format('Y-m');
        $end = $this->end($store, $month)->format('Y-m');
        $history = $this->months($store, self::HISTORY, $month);

        $sales = ProductSale::query()
            ->join('products', 'products.id', '=', 'product_sales.product_id')
            ->where('product_sales.store_id', $store->id)
            ->where('product_sales.period', '>=', min($history[0], $start).'-01')
            ->get(['products.category', 'product_sales.period', 'product_sales.revenue', 'product_sales.units'])
            // category comes from the joined products row.
            ->groupBy(fn (ProductSale $s): string => $s->getAttribute('category') ?? self::UNCATEGORIZED);

        $products = Product::query()->where('store_id', $store->id)
            ->get(['id', 'category', 'image_url', 'revenue', 'units_sold', 'performance'])
            ->groupBy(fn (Product $p) => $p->category ?? self::UNCATEGORIZED);

        $rows = $products->map(function (Collection $items, string $key) use ($sales, $start, $end, $history, $byUnits, $measure) {
            $rows = $sales->get($key, collect());
            $current = $rows->filter(fn (ProductSale $s) => $this->month($s) >= $start && $this->month($s) <= $end);
            $byMonth = $rows->groupBy(fn (ProductSale $s) => $this->month($s));

            return [
                'key' => $key,
                'name' => $key === self::UNCATEGORIZED ? null : $key,
                'products' => $items->count(),
                'revenue' => round((float) $current->sum('revenue'), 2),
                'units' => (int) $current->sum('units'),
                'winners' => $items->where('performance', Product::WINNER)->count(),
                'stagnant' => $items->where('performance', Product::STAGNANT)->count(),
                'images' => $items->whereNotNull('image_url')->sortByDesc($byUnits ? 'units_sold' : 'revenue')->take(3)->pluck('image_url')->values()->all(),
                'spark' => array_map(fn (string $m) => round((float) $byMonth->get($m, collect())->sum($measure), 2), $history),
            ];
        });

        $total = max(1.0, (float) $rows->sum($measure));

        return array_values($rows->map(fn (array $row) => [...$row, 'share' => round($row[$measure] / $total * 100, 1)])
            ->sortByDesc($measure)->all());
    }

    /**
     * One page of products in a category (or the whole store), each with
     * the 12 months of units and revenue up to the period's end. With a
     * chosen month, cards carry that month's sales and rank by them.
     *
     * @param  array{search?: string|null, class?: string|null, sort?: string|null}  $filters
     * @return array<string, mixed> the paginator as sent to the page
     */
    public function products(Store $store, ?string $category, array $filters, ?string $month = null): array
    {
        $page = $this->inCategory($store, $category)
            ->select('products.*')
            ->when($month, fn (Builder $q) => $q
                ->leftJoinSub(ProductSale::query()
                    ->selectRaw('product_id, SUM(units) AS month_units, SUM(revenue) AS month_revenue')
                    ->where('store_id', $store->id)
                    ->where('period', '>=', $month.'-01')
                    ->where('period', '<', $this->end($store, $month)->copy()->addMonth()->toDateString())
                    ->groupBy('product_id'), 'month_sales', 'month_sales.product_id', '=', 'products.id')
                ->addSelect('month_sales.month_units', 'month_sales.month_revenue'))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")))
            ->when($filters['class'] ?? null, fn (Builder $q, string $class) => $class === 'unclassified'
                ? $q->whereNull('products.performance')
                : $q->where('products.performance', $class))
            ->when($filters['sort'] ?? 'revenue', fn (Builder $q, string $sort) => match ($sort) {
                'units' => $month ? $q->orderByRaw('COALESCE(month_sales.month_units, 0) DESC') : $q->orderByDesc('products.units_sold'),
                'newest' => $q->orderByRaw('products.launched_at IS NULL')->orderByDesc('products.launched_at'),
                'price_low' => $q->orderByRaw('products.price IS NULL')->orderBy('products.price'),
                'price_high' => $q->orderByRaw('products.price IS NULL')->orderByDesc('products.price'),
                'stock' => $q->orderByDesc('products.stock'),
                default => $month ? $q->orderByRaw('COALESCE(month_sales.month_revenue, 0) DESC') : $q->orderByDesc('products.revenue'),
            })
            ->orderBy('products.id')
            ->paginate(24)
            ->withQueryString();

        $months = $this->months($store, self::HISTORY, $month);
        $history = ProductSale::query()
            ->whereIn('product_id', $page->getCollection()->modelKeys())
            ->where('period', '>=', $months[0].'-01')
            ->get(['product_id', 'period', 'units', 'revenue'])
            ->groupBy('product_id');

        return $page->through(function (Product $p) use ($history, $months, $month) {
            $rows = $history->get($p->id, collect())->groupBy(fn (ProductSale $s) => $this->month($s));

            return [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'category' => $p->category,
                'price' => $p->price !== null ? (float) $p->price : null,
                'image_url' => $p->image_url,
                'performance' => $p->performance,
                'performance_score' => $p->performance_score,
                'units_sold' => $p->units_sold,
                'revenue' => (float) $p->revenue,
                'month_units' => $month ? (int) $p->getAttribute('month_units') : null,
                'month_revenue' => $month ? round((float) $p->getAttribute('month_revenue'), 2) : null,
                'stock' => $p->stock,
                'turnover_days' => $p->turnover_days,
                'return_rate' => (float) $p->return_rate,
                'features' => $p->features ?? [],
                'launched_at' => $p->launched_at?->toDateString(),
                'history' => array_map(fn (string $m) => [
                    'period' => $m.'-01',
                    'units' => (int) $rows->get($m, collect())->sum('units'),
                    'revenue' => round((float) $rows->get($m, collect())->sum('revenue'), 2),
                ], $months),
            ];
        })->toArray();
    }

    /**
     * Everything the product page shows beyond the product row: 12 months of
     * sales, how it compares with its category, and best sellers beside it.
     *
     * @return array{history: list<array<string, mixed>>, category: array<string, mixed>, similar: list<array<string, mixed>>}
     */
    public function productDetail(Store $store, Product $product): array
    {
        $months = $this->months($store, self::HISTORY);
        $rows = ProductSale::query()->where('product_id', $product->id)->where('period', '>=', $months[0].'-01')
            ->get(['period', 'units', 'revenue', 'orders', 'returns'])
            ->groupBy(fn (ProductSale $s) => $this->month($s));

        $peers = $this->inCategory($store, $product->category ?? self::UNCATEGORIZED)
            ->get(['id', 'name', 'image_url', 'price', 'performance', 'units_sold', 'revenue', 'return_rate', 'turnover_days']);
        $median = fn (string $field) => ($values = $peers->pluck($field)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v))->isEmpty()
            ? null : round((float) $values->median(), 2);

        return [
            'history' => array_map(fn (string $m) => [
                'period' => $m.'-01',
                'units' => (int) $rows->get($m, collect())->sum('units'),
                'revenue' => round((float) $rows->get($m, collect())->sum('revenue'), 2),
                'orders' => (int) $rows->get($m, collect())->sum('orders'),
                'returns' => (int) $rows->get($m, collect())->sum('returns'),
            ], $months),
            'category' => [
                'key' => $product->category ?? self::UNCATEGORIZED,
                'name' => $product->category,
                'products' => $peers->count(),
                // 1 = highest revenue in the category.
                'rank' => $peers->sortByDesc('revenue')->values()->search(fn (Product $p) => $p->id === $product->id) + 1,
                'median' => [
                    'price' => $median('price'),
                    'units_sold' => $median('units_sold'),
                    'revenue' => $median('revenue'),
                    'return_rate' => $median('return_rate'),
                    'turnover_days' => $median('turnover_days'),
                ],
            ],
            'similar' => array_values($peers->where('id', '!=', $product->id)->sortByDesc('revenue')->take(4)
                ->map(fn (Product $p) => [...$p->only(['id', 'name', 'image_url', 'performance', 'units_sold']), 'price' => $p->price !== null ? (float) $p->price : null])
                ->all()),
        ];
    }

    /**
     * @return Builder<ProductSale>
     */
    private function sales(Store $store, ?string $category): Builder
    {
        return ProductSale::query()
            ->where('product_sales.store_id', $store->id)
            ->when($category !== null, fn (Builder $q) => $q
                ->join('products', 'products.id', '=', 'product_sales.product_id')
                ->when($category === self::UNCATEGORIZED,
                    fn (Builder $q) => $q->whereNull('products.category'),
                    fn (Builder $q) => $q->where('products.category', $category)));
    }

    /**
     * @return Builder<Product>
     */
    private function inCategory(Store $store, ?string $category): Builder
    {
        return Product::query()
            ->where('products.store_id', $store->id)
            ->when($category !== null, fn (Builder $q) => $category === self::UNCATEGORIZED
                ? $q->whereNull('products.category')
                : $q->where('products.category', $category));
    }

    /** @var array<int, CarbonInterface> */
    private array $ends = [];

    /**
     * The last month of every series: the chosen month, else the latest
     * month with sales, so a store synced weeks ago does not end on empty
     * months.
     */
    private function end(Store $store, ?string $month = null): CarbonInterface
    {
        if ($month) {
            return now()->parse($month.'-01')->startOfMonth();
        }

        return $this->ends[$store->id] ??= ($latest = ProductSale::query()->where('store_id', $store->id)->max('period'))
            ? min(now(), now()->parse($latest))->startOfMonth()
            : now()->startOfMonth();
    }

    private function start(Store $store, int $months, ?string $month = null): CarbonInterface
    {
        return $this->end($store, $month)->copy()->subMonths($months - 1);
    }

    /**
     * @return list<string> Y-m keys, oldest first
     */
    private function months(Store $store, int $months, ?string $month = null): array
    {
        $start = $this->start($store, $months, $month);

        return array_map(fn (int $i) => $start->copy()->addMonths($i)->format('Y-m'), range(0, $months - 1));
    }

    private function month(ProductSale $sale): string
    {
        return substr((string) $sale->getRawOriginal('period'), 0, 7);
    }
}
