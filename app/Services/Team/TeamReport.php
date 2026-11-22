<?php

namespace App\Services\Team;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogBrowser;
use App\Support\StoreSections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The owner's view of the team: what each staff member is responsible
 * for and how it sells, side by side and month by month.
 *
 * A product's sales all belong to the member who holds it now, including
 * what sold before they took it; moving a product moves its sales. The
 * period follows the catalog browser: the last 3, 6 or 12 months, or one
 * chosen month shown with the year leading up to it.
 *
 * Money is always under the keys "revenue" and "revenue_share", so
 * RevenueVisibility can blank it for stores that do not see revenue.
 */
class TeamReport
{
    public function __construct(private readonly CatalogBrowser $catalog) {}

    /**
     * Every staff member with their products and sales, best seller first,
     * plus the products nobody holds and the store totals.
     *
     * @return array{months: list<string>, length: int, members: list<array<string, mixed>>, unassigned: array<string, mixed>, totals: array<string, mixed>}
     */
    public function comparison(Store $store, int $months, ?string $month = null): array
    {
        $period = $this->catalog->period($store, $months, $month);
        $length = $month ? 1 : $months;
        $staff = $store->staff()->orderBy('users.name')->get();
        $isStaff = array_flip($staff->modelKeys());
        // Products held by someone who is no longer staff count as nobody's.
        $holder = fn (object $row): int => isset($isStaff[(int) $row->user_id]) ? (int) $row->user_id : 0;

        $products = DB::table('products')
            ->where('store_id', $store->id)
            ->where('excluded', false)
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id AS user_id, COUNT(*) AS products,
                SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS winners,
                SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS at_risk,
                SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS stagnant', [Product::WINNER, Product::AT_RISK, Product::STAGNANT])
            ->get()
            ->groupBy($holder);

        $sales = $this->salesByHolder($store, min($period['months'][0].'-01', $period['from']), $period['to'])
            ->groupBy('products.assigned_user_id', 'product_sales.period')
            ->selectRaw('products.assigned_user_id AS user_id, product_sales.period AS period,
                SUM(product_sales.units) AS units, SUM(product_sales.orders) AS orders, SUM(product_sales.revenue) AS revenue')
            ->get()
            ->groupBy($holder);

        $selling = $this->salesByHolder($store, $period['from'], $period['to'])
            ->where('product_sales.units', '>', 0)
            ->groupBy('products.assigned_user_id')
            ->selectRaw('products.assigned_user_id AS user_id, COUNT(DISTINCT product_sales.product_id) AS selling')
            ->get()
            ->groupBy($holder);

        $best = $staff->isEmpty() ? collect() : $this->salesByHolder($store, $period['from'], $period['to'])
            ->whereIn('products.assigned_user_id', $staff->modelKeys())
            ->groupBy('products.id', 'products.name', 'products.image_url', 'products.assigned_user_id')
            ->selectRaw('products.id AS id, products.name AS name, products.image_url AS image_url,
                products.assigned_user_id AS user_id, SUM(product_sales.units) AS units')
            ->orderByDesc('units')
            ->get()
            ->filter(fn (object $row) => (int) $row->units > 0)
            ->unique('user_id')
            ->keyBy(fn (object $row) => (int) $row->user_id);

        $figures = function (int $id) use ($products, $sales, $selling, $period, $length): array {
            $rows = $sales->get($id, collect());
            $byMonth = $rows->groupBy(fn (object $row) => substr((string) $row->period, 0, 7));
            $current = $rows->filter(fn (object $row) => substr((string) $row->period, 0, 10) >= $period['from']);
            $count = (int) $products->get($id, collect())->sum('products');
            $units = (int) $current->sum('units');

            return [
                'products' => $count,
                'selling' => (int) $selling->get($id, collect())->sum('selling'),
                'winners' => (int) $products->get($id, collect())->sum('winners'),
                'at_risk' => (int) $products->get($id, collect())->sum('at_risk'),
                'stagnant' => (int) $products->get($id, collect())->sum('stagnant'),
                'units' => $units,
                'orders' => (int) $current->sum('orders'),
                'revenue' => round((float) $current->sum('revenue'), 2),
                'avg_per_product' => $count > 0 ? round($units / $count, 1) : 0.0,
                'avg_per_month' => round($units / $length, 1),
                'monthly' => array_map(fn (string $key) => [
                    'period' => $key.'-01',
                    'units' => (int) $byMonth->get($key, collect())->sum('units'),
                    'revenue' => round((float) $byMonth->get($key, collect())->sum('revenue'), 2),
                ], $period['months']),
            ];
        };

        $members = $staff->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'status' => $member->status,
            'last_login_at' => $member->last_login_at?->toIso8601String(),
            'joined_at' => ($joined = $member->pivot->created_at) ? Carbon::parse($joined)->toIso8601String() : null,
            'permissions' => StoreSections::clean((array) json_decode((string) ($member->pivot->permissions ?? '[]'), true)),
            ...$figures($member->id),
            'best' => ($top = $best->get($member->id)) ? [
                'id' => (int) $top->id,
                'name' => $top->name,
                'image_url' => $top->image_url,
                'units' => (int) $top->units,
            ] : null,
        ]);

        $unassigned = $figures(0);
        $units = (int) $members->sum('units') + $unassigned['units'];
        $revenue = (float) $members->sum('revenue') + $unassigned['revenue'];
        $share = fn (float|int $part, float|int $whole): float => $whole > 0 ? round($part / $whole * 100, 1) : 0.0;

        return [
            'months' => $period['months'],
            'length' => $length,
            'members' => array_values($members
                ->map(fn (array $row) => [...$row, 'share' => $share($row['units'], $units), 'revenue_share' => $share($row['revenue'], $revenue)])
                ->sortBy([['units', 'desc'], ['products', 'desc'], ['name', 'asc']])
                ->all()),
            'unassigned' => [...$unassigned, 'share' => $share($unassigned['units'], $units), 'revenue_share' => $share($unassigned['revenue'], $revenue)],
            'totals' => [
                'products' => (int) $members->sum('products') + $unassigned['products'],
                'assigned' => (int) $members->sum('products'),
                'units' => $units,
                'orders' => (int) $members->sum('orders') + $unassigned['orders'],
                'revenue' => round($revenue, 2),
            ],
        ];
    }

    /**
     * One member's line of the comparison, for their own page.
     *
     * @return array<string, mixed>|null
     */
    public function member(Store $store, User $member, int $months, ?string $month = null): ?array
    {
        $report = $this->comparison($store, $months, $month);

        return collect($report['members'])->firstWhere('id', $member->id);
    }

    /**
     * One page of a member's products ("mine"), or of the products they
     * could take ("add"), each with its sales in the period and month by
     * month. "add" lists products nobody holds; with $withTaken it also
     * shows the ones other members hold, so it is clear why they are not
     * on offer.
     *
     * With $withSales off (a staff member's own view) the rows carry no
     * sales, performance class or history. $sort is "newest" or, with
     * sales, "units".
     *
     * @param  array{search?: string|null, category?: string|null}  $filters
     * @return array<string, mixed> the paginator as sent to the page
     */
    public function products(Store $store, User $member, string $tab, array $filters, int $months, ?string $month = null, bool $withTaken = false, bool $withSales = true, string $sort = 'newest'): array
    {
        // Sales and performance are the store manager's to see. For a staff
        // member they are not read at all, so they cannot reach the page.
        $period = $withSales ? $this->catalog->period($store, $months, $month) : null;
        $bySales = $withSales && $sort === 'units';

        $page = Product::query()
            ->where('products.store_id', $store->id)
            ->select('products.*')
            ->with('assignee:id,name')
            ->when($tab === 'mine',
                fn (Builder $q) => $q->where('products.assigned_user_id', $member->id),
                fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->whereNull('products.assigned_user_id')
                    ->when($withTaken, fn (Builder $w) => $w->orWhere('products.assigned_user_id', '!=', $member->id))))
            ->when($period, fn (Builder $q) => $q
                ->leftJoinSub(ProductSale::query()
                    ->selectRaw('product_id, SUM(units) AS period_units, SUM(orders) AS period_orders, SUM(revenue) AS period_revenue')
                    ->where('store_id', $store->id)
                    ->where('period', '>=', $period['from'])
                    ->where('period', '<', $period['to'])
                    ->groupBy('product_id'), 'period_sales', 'period_sales.product_id', '=', 'products.id')
                ->addSelect('period_sales.period_units', 'period_sales.period_orders', 'period_sales.period_revenue'))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $category === CatalogBrowser::UNCATEGORIZED
                ? $q->whereNull('products.category')
                : $q->where('products.category', $category))
            // Products another member holds go last.
            ->orderByRaw('CASE WHEN products.assigned_user_id IS NOT NULL AND products.assigned_user_id != ? THEN 1 ELSE 0 END', [$member->id])
            ->when($bySales,
                fn (Builder $q) => $q->orderByRaw('COALESCE(period_sales.period_units, 0) DESC')->orderBy('products.name'),
                // Newest first, so a product just added leads its list: the
                // one taken last among the member's own, the one launched
                // last among those on offer.
                fn (Builder $q) => $tab === 'mine'
                    ? $q->orderByDesc('products.assigned_at')
                    : $q->orderByRaw('COALESCE(products.launched_at, products.created_at) DESC'))
            ->orderByDesc('products.id')
            ->paginate(20)
            ->withQueryString();

        $history = $period ? ProductSale::query()
            ->whereIn('product_id', $page->getCollection()->modelKeys())
            ->where('period', '>=', $period['months'][0].'-01')
            ->where('period', '<', $period['to'])
            ->get(['product_id', 'period', 'units', 'revenue'])
            ->groupBy('product_id') : collect();

        return $page->through(function (Product $p) use ($history, $period) {
            $row = [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'category' => $p->category,
                'price' => $p->price !== null ? (float) $p->price : null,
                'image_url' => $p->image_url,
                'assignee' => $p->assignee ? ['id' => $p->assignee->id, 'name' => $p->assignee->name] : null,
                'assigned_at' => $p->assigned_at?->toIso8601String(),
                'launched_at' => $p->launched_at?->toDateString(),
            ];

            if (! $period) {
                return $row;
            }

            $rows = $history->get($p->id, collect())->groupBy(fn (ProductSale $s) => substr((string) $s->getRawOriginal('period'), 0, 7));

            return [
                ...$row,
                'performance' => $p->performance,
                'units' => (int) $p->getAttribute('period_units'),
                'orders' => (int) $p->getAttribute('period_orders'),
                'revenue' => round((float) $p->getAttribute('period_revenue'), 2),
                'history' => array_map(fn (string $m) => [
                    'period' => $m.'-01',
                    'units' => (int) $rows->get($m, collect())->sum('units'),
                    'revenue' => round((float) $rows->get($m, collect())->sum('revenue'), 2),
                ], $period['months']),
            ];
        })->toArray();
    }

    /**
     * How many products sit in each tab, and the categories to filter by.
     *
     * @return array{mine: int, free: int, categories: list<string>}
     */
    public function counts(Store $store, User $member): array
    {
        $products = Product::query()->where('store_id', $store->id);

        return [
            'mine' => (clone $products)->where('assigned_user_id', $member->id)->count(),
            'free' => (clone $products)->whereNull('assigned_user_id')->count(),
            'categories' => (clone $products)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all(),
        ];
    }

    /**
     * Sales rows joined to the product that holds them, between two dates
     * ("to" exclusive).
     */
    private function salesByHolder(Store $store, string $from, string $to): QueryBuilder
    {
        return DB::table('product_sales')
            ->join('products', 'products.id', '=', 'product_sales.product_id')
            ->where('products.excluded', false)
            ->where('product_sales.store_id', $store->id)
            ->where('product_sales.period', '>=', $from)
            ->where('product_sales.period', '<', $to);
    }
}
