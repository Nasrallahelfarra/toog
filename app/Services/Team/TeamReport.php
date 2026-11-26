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
    /** Products per page in a member's list, on the phone and the desktop alike. */
    public const PER_PAGE = 20;

    public function __construct(private readonly CatalogBrowser $catalog) {}

    /**
     * Every staff member with their products and sales, best seller first,
     * plus the products nobody holds and the store totals.
     *
     * @return array{months: list<string>, length: int, members: list<array<string, mixed>>, unassigned: array<string, mixed>, totals: array<string, mixed>}
     */
    /**
     * The staff as people, without their sales: for managing the team.
     *
     * @return list<array<string, mixed>>
     */
    public function roster(Store $store): array
    {
        $held = DB::table('products')
            ->where('store_id', $store->id)
            ->where('excluded', false)
            ->whereNotNull('assigned_user_id')
            ->groupBy('assigned_user_id')
            ->pluck(DB::raw('COUNT(*)'), 'assigned_user_id');

        return $store->staff()->orderBy('users.name')->get()->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'status' => $member->status,
            'two_factor' => $member->hasEnabledTwoFactorAuthentication(),
            'last_login_at' => $member->last_login_at?->toIso8601String(),
            'joined_at' => ($joined = $member->pivot->created_at) ? Carbon::parse($joined)->toIso8601String() : null,
            'permissions' => StoreSections::stored($member->pivot->permissions),
            'products' => (int) ($held[$member->id] ?? 0),
        ])->values()->all();
    }

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
                SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS average,
                SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS at_risk,
                SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS stagnant,
                SUM(CASE WHEN performance IS NULL THEN 1 ELSE 0 END) AS unrated', [Product::WINNER, Product::AVERAGE, Product::AT_RISK, Product::STAGNANT])
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

        [$uploads, $success] = $this->uploads($store, $period, $holder);

        $figures = function (int $id) use ($products, $sales, $selling, $uploads, $success, $period, $length): array {
            $rows = $sales->get($id, collect());
            $byMonth = $rows->groupBy(fn (object $row) => substr((string) $row->period, 0, 7));
            $current = $rows->filter(fn (object $row) => substr((string) $row->period, 0, 10) >= $period['from']);
            $held = $products->get($id, collect());
            $count = (int) $held->sum('products');
            $units = (int) $current->sum('units');
            $made = $uploads->get($id, collect());
            $tried = $success[$id] ?? ['judged' => 0, 'hit' => 0];

            return [
                'products' => $count,
                'selling' => (int) $selling->get($id, collect())->sum('selling'),
                'winners' => (int) $held->sum('winners'),
                'at_risk' => (int) $held->sum('at_risk'),
                'stagnant' => (int) $held->sum('stagnant'),
                // How the products held stand today: moving (winning or
                // average), slowing, stagnant, or not classified yet.
                'mix' => [
                    'moving' => (int) $held->sum('winners') + (int) $held->sum('average'),
                    'slowing' => (int) $held->sum('at_risk'),
                    'stagnant' => (int) $held->sum('stagnant'),
                    'unrated' => (int) $held->sum('unrated'),
                ],
                // Products created in Zid in the period, by whoever holds them now.
                'uploads' => $made->filter(fn (string $created) => $created >= $period['from'] && $created < $period['to'])->count(),
                // Of the new products of the last 12 months old enough to
                // judge, how many sold in their first two months.
                'success' => [
                    'judged' => $tried['judged'],
                    'hit' => $tried['hit'],
                    'rate' => $tried['judged'] > 0 ? round($tried['hit'] / $tried['judged'] * 100, 1) : null,
                ],
                'units' => $units,
                'orders' => (int) $current->sum('orders'),
                'revenue' => round((float) $current->sum('revenue'), 2),
                'avg_per_product' => $count > 0 ? round($units / $count, 1) : 0.0,
                'avg_per_month' => round($units / $length, 1),
                'monthly' => array_map(fn (string $key) => [
                    'period' => $key.'-01',
                    'units' => (int) $byMonth->get($key, collect())->sum('units'),
                    'revenue' => round((float) $byMonth->get($key, collect())->sum('revenue'), 2),
                    'uploads' => $made->filter(fn (string $created) => str_starts_with($created, $key))->count(),
                ], $period['months']),
            ];
        };

        $members = $staff->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'status' => $member->status,
            'two_factor' => $member->hasEnabledTwoFactorAuthentication(),
            'last_login_at' => $member->last_login_at?->toIso8601String(),
            'joined_at' => ($joined = $member->pivot->created_at) ? Carbon::parse($joined)->toIso8601String() : null,
            'permissions' => StoreSections::stored($member->pivot->permissions),
            ...$figures($member->id),
            'best' => ($top = $best->get($member->id)) ? [
                'id' => (int) $top->id,
                'name' => $top->name,
                'image_url' => $top->image_url,
                'units' => (int) $top->units,
            ] : null,
        ]);

        // The rating looks at the chosen months, or at the three months up
        // to a single chosen month: one month alone says too little.
        $window = array_slice($period['months'], -($month ? 3 : $length));
        $ratings = TeamRating::rate($members->mapWithKeys(fn (array $row) => [$row['id'] => $this->ratingInputs($row, $window)])->all());
        $members = $members->map(fn (array $row) => [...$row, 'rating' => $ratings[$row['id']]]);

        $unassigned = $figures(0);
        $units = (int) $members->sum('units') + $unassigned['units'];
        $revenue = (float) $members->sum('revenue') + $unassigned['revenue'];
        $share = fn (float|int $part, float|int $whole): float => $whole > 0 ? round($part / $whole * 100, 1) : 0.0;

        return [
            'months' => $period['months'],
            'length' => $length,
            'rating' => ['months' => count($window), 'until' => end($window).'-01', 'weights' => TeamRating::WEIGHTS],
            // Best rated first; those still being rated after, by units sold.
            'members' => array_values($members
                ->map(fn (array $row) => [...$row, 'share' => $share($row['units'], $units), 'revenue_share' => $share($row['revenue'], $revenue)])
                ->sortBy([
                    fn (array $a, array $b) => ($b['rating']['score'] ?? -1) <=> ($a['rating']['score'] ?? -1),
                    ['units', 'desc'], ['products', 'desc'], ['name', 'asc'],
                ])
                ->all()),
            'unassigned' => [...$unassigned, 'share' => $share($unassigned['units'], $units), 'revenue_share' => $share($unassigned['revenue'], $revenue)],
            'totals' => [
                'products' => (int) $members->sum('products') + $unassigned['products'],
                'assigned' => (int) $members->sum('products'),
                'units' => $units,
                'orders' => (int) $members->sum('orders') + $unassigned['orders'],
                'revenue' => round($revenue, 2),
                'uploads' => (int) $members->sum('uploads') + $unassigned['uploads'],
            ],
        ];
    }

    /**
     * Products by the moment they were created in Zid, per holder: the
     * creation moments from the start of the history (for the monthly
     * uploads) and, for the success rate, how many of the last year's new
     * products old enough to judge (60 days) sold in their first two months.
     *
     * @param  array{from: string, to: string, months: list<string>}  $period
     * @return array{0: \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, string>>, 1: array<int, array{judged: int, hit: int}>}
     */
    private function uploads(Store $store, array $period, \Closure $holder): array
    {
        $to = $period['to'];
        $yearAgo = Carbon::parse($to)->subMonths(12)->toDateString();
        $from = min($period['months'][0].'-01', $yearAgo);
        $judgeBefore = Carbon::parse($to)->subDays(60)->toDateString();

        // listed_at has the time; products synced before it existed have the day only.
        $rows = DB::table('products')
            ->where('store_id', $store->id)
            ->where('excluded', false)
            ->where(fn ($w) => $w
                ->where(fn ($q) => $q->where('listed_at', '>=', $from)->where('listed_at', '<', $to))
                ->orWhere(fn ($q) => $q->whereNull('listed_at')->where('launched_at', '>=', $from)->where('launched_at', '<', $to)))
            ->selectRaw('id, assigned_user_id AS user_id, COALESCE(listed_at, launched_at) AS created')
            ->get()
            ->map(fn (object $row) => (object) ['id' => (int) $row->id, 'user_id' => $row->user_id, 'created' => substr((string) $row->created, 0, 10)]);

        $judged = $rows->filter(fn (object $row) => $row->created >= $yearAgo && $row->created < $judgeBefore);
        $soldMonths = collect();
        foreach ($judged->pluck('id')->chunk(500) as $ids) {
            $soldMonths = $soldMonths->merge(DB::table('product_sales')
                ->where('store_id', $store->id)
                ->whereIn('product_id', $ids->all())
                ->where('units', '>', 0)
                ->get(['product_id', 'period'])
                ->map(fn (object $row) => [(int) $row->product_id, substr((string) $row->period, 0, 7)]));
        }
        $sold = $soldMonths->groupBy(0)->map(fn ($rows) => $rows->pluck(1)->all());

        $success = [];
        foreach ($judged as $row) {
            $first = substr($row->created, 0, 7);
            $second = Carbon::parse($row->created)->startOfMonth()->addMonth()->format('Y-m');
            $key = $holder($row);
            $success[$key] ??= ['judged' => 0, 'hit' => 0];
            $success[$key]['judged']++;
            $months = $sold->get($row->id, []);
            if (in_array($first, $months, true) || in_array($second, $months, true)) {
                $success[$key]['hit']++;
            }
        }

        return [$rows->groupBy($holder)->map(fn ($set) => $set->pluck('created')->values()), $success];
    }

    /**
     * What the rating needs from one member's row: per month on the team
     * within the window, so someone who joined midway is not behind.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $window  months as Y-m
     * @return array{reason: ?string, sales: float, success: ?float, uploads: float, status: ?float}
     */
    private function ratingInputs(array $row, array $window): array
    {
        $joined = $row['joined_at'] ? Carbon::parse($row['joined_at']) : null;
        $onTeam = max(1, count(array_filter($window, fn (string $key) => ! $joined || $key >= $joined->format('Y-m'))));
        $inWindow = array_filter($row['monthly'], fn (array $point) => in_array(substr($point['period'], 0, 7), $window, true));
        $status = $row['mix'];
        $classified = $status['moving'] + $status['slowing'] + $status['stagnant'];

        return [
            'reason' => match (true) {
                $row['status'] === 'disabled' => 'disabled',
                $row['products'] < TeamRating::MIN_PRODUCTS => 'few_products',
                $joined !== null && $joined->gt(now()->subDays(TeamRating::MIN_DAYS)) => 'new_member',
                default => null,
            },
            'sales' => array_sum(array_column($inWindow, 'units')) / $onTeam,
            'uploads' => array_sum(array_column($inWindow, 'uploads')) / $onTeam,
            'success' => $row['success']['judged'] >= TeamRating::MIN_JUDGED ? $row['success']['hit'] / $row['success']['judged'] : null,
            'status' => $classified > 0 ? ($status['moving'] + 0.5 * $status['slowing']) / $classified : null,
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

        $query = Product::query()
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
            // The products created in Zid in one month ("what did they bring in October").
            ->when($filters['uploaded'] ?? null, fn (Builder $q, string $month) => self::createdIn($q, $month))
            // Every unit each product has sold, whatever the period: how it did since it went up.
            ->when($withSales, fn (Builder $q) => $q
                ->leftJoinSub(ProductSale::query()
                    ->selectRaw('product_id, SUM(units) AS total_units')
                    ->where('store_id', $store->id)
                    ->groupBy('product_id'), 'all_sales', 'all_sales.product_id', '=', 'products.id')
                ->addSelect('all_sales.total_units'))
            // Products another member holds go last.
            ->orderByRaw('CASE WHEN products.assigned_user_id IS NOT NULL AND products.assigned_user_id != ? THEN 1 ELSE 0 END', [$member->id])
            ->when($bySales,
                fn (Builder $q) => $q->orderByRaw('COALESCE(period_sales.period_units, 0) DESC')->orderBy('products.name'),
                // Newest first, so a product just added leads its list: the
                // one taken last among the member's own, the one launched
                // last among those on offer. A product with no launch date
                // goes after the dated ones rather than ahead of them.
                fn (Builder $q) => $tab === 'mine'
                    ? $q->orderByDesc('products.assigned_at')
                    : Product::newestFirst($q))
            ->orderByDesc('products.id');

        $page = (clone $query)->paginate(self::PER_PAGE)->withQueryString();

        // Adding or removing the last products of the last page would leave
        // an empty page: show the page that is now last instead.
        if ($page->isEmpty() && $page->currentPage() > 1 && $page->total() > 0) {
            $page = $query->paginate(self::PER_PAGE, ['*'], 'page', $page->lastPage())->withQueryString();
        }

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
                'units_total' => (int) $p->getAttribute('total_units'),
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
     * What came of the products a member brought in one month: how many,
     * how many have sold since and how many units, and how they stand today.
     *
     * @return array{month: string, products: int, sold: int, unsold: int, units: int, moving: int, slowing: int, stagnant: int, unrated: int}
     */
    public function uploadSummary(Store $store, User $member, string $month): array
    {
        $rows = self::createdIn(DB::table('products')
            ->where('products.store_id', $store->id)
            ->where('products.excluded', false)
            ->where('products.assigned_user_id', $member->id), $month)
            ->leftJoinSub(DB::table('product_sales')
                ->selectRaw('product_id, SUM(units) AS units')
                ->where('store_id', $store->id)
                ->groupBy('product_id'), 'sold', 'sold.product_id', '=', 'products.id')
            ->get(['products.performance', DB::raw('COALESCE(sold.units, 0) AS units')]);

        $sold = $rows->filter(fn (object $row) => (int) $row->units > 0)->count();

        return [
            'month' => $month,
            'products' => $rows->count(),
            'sold' => $sold,
            'unsold' => $rows->count() - $sold,
            'units' => (int) $rows->sum('units'),
            'moving' => $rows->whereIn('performance', [Product::WINNER, Product::AVERAGE])->count(),
            'slowing' => $rows->where('performance', Product::AT_RISK)->count(),
            'stagnant' => $rows->where('performance', Product::STAGNANT)->count(),
            'unrated' => $rows->whereNull('performance')->count(),
        ];
    }

    /**
     * Products created in Zid within a month (Y-m): by the moment when it is
     * known, by the day for products synced before the time was kept.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function createdIn(Builder|QueryBuilder $query, string $month): Builder|QueryBuilder
    {
        $from = $month.'-01';
        $to = Carbon::parse($from)->addMonth()->toDateString();

        return $query->where(fn ($w) => $w
            ->where(fn ($q) => $q->where('products.listed_at', '>=', $from)->where('products.listed_at', '<', $to))
            ->orWhere(fn ($q) => $q->whereNull('products.listed_at')->where('products.launched_at', '>=', $from)->where('products.launched_at', '<', $to)));
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
