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
 * without one, and null for the whole store.
 */
class CatalogBrowser
{
    public const UNCATEGORIZED = '__none__';

    public const RANGES = [3, 6, 12];

    /** Months of history behind every sparkline and product chart. */
    private const HISTORY = 12;

    /**
     * Totals for the selected months against the months just before them.
     *
     * @return array<string, mixed>
     */
    public function summary(Store $store, ?string $category, int $months): array
    {
        $start = $this->start($store, $months);
        $previous = $start->copy()->subMonths($months);

        $rows = $this->sales($store, $category)
            ->where('product_sales.period', '>=', $previous->toDateString())
            ->get(['product_sales.period', 'product_sales.revenue', 'product_sales.units', 'product_sales.orders']);
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
     *
     * @return list<array{period: string, revenue: float, units: int, orders: int, partial: bool}>
     */
    public function trend(Store $store, ?string $category, int $months): array
    {
        $rows = $this->sales($store, $category)
            ->where('product_sales.period', '>=', $this->start($store, $months)->toDateString())
            ->get(['product_sales.period', 'product_sales.revenue', 'product_sales.units', 'product_sales.orders'])
            ->groupBy(fn (ProductSale $s) => $this->month($s));

        return array_map(fn (string $month) => [
            'period' => $month.'-01',
            'revenue' => round((float) $rows->get($month, collect())->sum('revenue'), 2),
            'units' => (int) $rows->get($month, collect())->sum('units'),
            'orders' => (int) $rows->get($month, collect())->sum('orders'),
            // The current month is still filling up; the chart marks it.
            'partial' => $month === now()->format('Y-m'),
        ], $this->months($store, $months));
    }

    /**
     * Every category with its products, its sales in the selected months,
     * a 12-month revenue line and a few of its best-selling product images.
     *
     * @return list<array<string, mixed>>
     */
    public function categories(Store $store, int $months): array
    {
        $start = $this->start($store, $months)->format('Y-m');
        $history = $this->months($store, self::HISTORY);

        $sales = ProductSale::query()
            ->join('products', 'products.id', '=', 'product_sales.product_id')
            ->where('product_sales.store_id', $store->id)
            ->where('product_sales.period', '>=', $this->start($store, max($months, self::HISTORY))->toDateString())
            ->get(['products.category', 'product_sales.period', 'product_sales.revenue', 'product_sales.units'])
            // category comes from the joined products row.
            ->groupBy(fn (ProductSale $s): string => $s->getAttribute('category') ?? self::UNCATEGORIZED);

        $products = Product::query()->where('store_id', $store->id)
            ->get(['id', 'category', 'image_url', 'revenue', 'performance'])
            ->groupBy(fn (Product $p) => $p->category ?? self::UNCATEGORIZED);

        $rows = $products->map(function (Collection $items, string $key) use ($sales, $start, $history) {
            $rows = $sales->get($key, collect());
            $current = $rows->filter(fn (ProductSale $s) => $this->month($s) >= $start);
            $byMonth = $rows->groupBy(fn (ProductSale $s) => $this->month($s));

            return [
                'key' => $key,
                'name' => $key === self::UNCATEGORIZED ? null : $key,
                'products' => $items->count(),
                'revenue' => round((float) $current->sum('revenue'), 2),
                'units' => (int) $current->sum('units'),
                'winners' => $items->where('performance', Product::WINNER)->count(),
                'stagnant' => $items->where('performance', Product::STAGNANT)->count(),
                'images' => $items->whereNotNull('image_url')->sortByDesc('revenue')->take(3)->pluck('image_url')->values()->all(),
                'spark' => array_map(fn (string $m) => round((float) $byMonth->get($m, collect())->sum('revenue'), 2), $history),
            ];
        });

        $total = max(1.0, (float) $rows->sum('revenue'));

        return array_values($rows->map(fn (array $row) => [...$row, 'share' => round($row['revenue'] / $total * 100, 1)])
            ->sortByDesc('revenue')->all());
    }

    /**
     * One page of products in a category (or the whole store), each with its
     * last 12 months of units and revenue for the card line and detail chart.
     *
     * @param  array{search?: string|null, class?: string|null, sort?: string|null}  $filters
     * @return array<string, mixed> the paginator as sent to the page
     */
    public function products(Store $store, ?string $category, array $filters): array
    {
        $page = $this->inCategory($store, $category)
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->when($filters['class'] ?? null, fn (Builder $q, string $class) => $class === 'unclassified'
                ? $q->whereNull('performance')
                : $q->where('performance', $class))
            ->when($filters['sort'] ?? 'revenue', fn (Builder $q, string $sort) => match ($sort) {
                'units' => $q->orderByDesc('units_sold'),
                'newest' => $q->orderByRaw('launched_at IS NULL')->orderByDesc('launched_at'),
                'price_low' => $q->orderByRaw('price IS NULL')->orderBy('price'),
                'price_high' => $q->orderByRaw('price IS NULL')->orderByDesc('price'),
                'stock' => $q->orderByDesc('stock'),
                default => $q->orderByDesc('revenue'),
            })
            ->orderBy('id')
            ->paginate(24)
            ->withQueryString();

        $months = $this->months($store, self::HISTORY);
        $history = ProductSale::query()
            ->whereIn('product_id', $page->getCollection()->modelKeys())
            ->where('period', '>=', $months[0].'-01')
            ->get(['product_id', 'period', 'units', 'revenue'])
            ->groupBy('product_id');

        return $page->through(function (Product $p) use ($history, $months) {
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
            ->where('store_id', $store->id)
            ->when($category !== null, fn (Builder $q) => $category === self::UNCATEGORIZED
                ? $q->whereNull('category')
                : $q->where('category', $category));
    }

    /** @var array<int, CarbonInterface> */
    private array $ends = [];

    /**
     * The last month of every series: the latest month with sales, so a
     * store synced weeks ago does not end on empty months.
     */
    private function end(Store $store): CarbonInterface
    {
        return $this->ends[$store->id] ??= ($latest = ProductSale::query()->where('store_id', $store->id)->max('period'))
            ? min(now(), now()->parse($latest))->startOfMonth()
            : now()->startOfMonth();
    }

    private function start(Store $store, int $months): CarbonInterface
    {
        return $this->end($store)->copy()->subMonths($months - 1);
    }

    /**
     * @return list<string> Y-m keys, oldest first
     */
    private function months(Store $store, int $months): array
    {
        $start = $this->start($store, $months);

        return array_map(fn (int $i) => $start->copy()->addMonths($i)->format('Y-m'), range(0, $months - 1));
    }

    private function month(ProductSale $sale): string
    {
        return substr((string) $sale->getRawOriginal('period'), 0, 7);
    }
}
