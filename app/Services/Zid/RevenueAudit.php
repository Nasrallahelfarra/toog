<?php

namespace App\Services\Zid;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Revenue audit: reads one month of orders straight from Zid and sets them
 * beside what the import stored, so a gap between the store's own figures
 * and ours can be traced to its cause — a stale import, orders the import
 * missed, order lines that match no product (variants), amounts that sit on
 * the order rather than its lines (shipping, VAT, discounts), returns, time
 * zones, currencies, or sales rows that never came from Zid.
 *
 * Read-only: the report is the only thing it saves.
 */
class RevenueAudit
{
    public const TYPE = 'revenue_audit';

    public const ACTIVE = ['queued', 'running'];

    /** 8,000 orders — far more than one month of the stores served. */
    private const MAX_PAGES = 80;

    private const STORE_TIMEZONE = 'Asia/Riyadh';

    private const SAMPLES = 12;

    /** Where an order's total may sit, by Zid API version. */
    private const TOTAL_KEYS = ['order_total', 'total', 'grand_total', 'total_amount', 'amount', 'transaction_amount'];

    /** Money-like numeric fields, summed without knowing their names in advance. */
    private const MONEY = '/total|amount|price|cost|fee|discount|vat|tax|coupon|shipping|net|subtotal|paid|refund|wallet|credit|cash|charge/i';

    private const NOT_MONEY = '/(^|_)id$|_id_|phone|mobile|code$|count|quantity|qty|weight|percentage|rate$|^is_|^has_/i';

    /** Personal data never enters the report. */
    private const PRIVATE = '/customer|consignee|receiver|recipient|address|e_?mail|phone|mobile|street|district|(^|_)city|zip|postal|^lat$|^lng$|latitude|longitude|^ip$|ip_address|note|comment|national|iban|card_number|cvv|token|password|user_agent|gift_message|(^|_)url$|_link$|^link$/i';

    public function __construct(private readonly ZidMapper $mapper) {}

    /**
     * @param  string  $month  Y-m
     * @param  (callable(int, int): void)|null  $progress  pages read, orders read
     * @return array<string, mixed>
     */
    public function run(Store $store, string $month, ?float $expected = null, ?callable $progress = null): array
    {
        $began = microtime(true);
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', 'UTC');
        $end = $start->copy()->endOfMonth();
        $catalog = $this->catalog($store);

        $ours = $this->ours($store, $start, $catalog);
        $zid = $this->zid($store, $start, $end, $catalog, $ours['stored_orders']['ids'], $progress);
        $ours['stored_orders']['ids'] = count($ours['stored_orders']['ids']);

        $candidates = $this->candidates($ours, $zid, $expected);

        return [
            'version' => 1,
            'month' => $month,
            'expected' => $expected,
            'generated_at' => now()->toIso8601String(),
            'app_timezone' => config('app.timezone'),
            'store' => ['id' => $store->id, 'name' => $store->name, 'zid_store_id' => $store->platform_store_id, 'last_synced_at' => $store->last_synced_at?->toIso8601String()],
            'catalog' => ['products' => $catalog['count'], 'with_zid_id' => count($catalog['by_id']), 'without_zid_id' => $catalog['without_id']],
            'last_sync' => $this->lastSync($store),
            'ours' => $ours,
            'zid' => $zid,
            'candidates' => $candidates,
            'findings' => $this->findings($ours, $zid),
            'duration_s' => round(microtime(true) - $began, 1),
        ];
    }

    /**
     * @return array{by_id: array<string, int>, by_sku: array<string, int>, count: int, without_id: int}
     */
    private function catalog(Store $store): array
    {
        $products = Product::query()->where('store_id', $store->id)->get(['id', 'external_id', 'sku']);

        return [
            // Keyed as the import resolves lines, so the audit counts exactly what it counts.
            'by_id' => ZidMapper::catalogKeys($products->filter(fn (Product $p) => filled($p->external_id))->pluck('id', 'external_id')),
            'by_sku' => $products->filter(fn (Product $p) => filled($p->sku))->pluck('id', 'sku')->all(),
            'count' => $products->count(),
            'without_id' => $products->filter(fn (Product $p) => blank($p->external_id))->count(),
        ];
    }

    /**
     * What the dashboard and catalog show for the month (product_sales), and
     * the orders the import stored for it (zid_orders).
     *
     * @param  array{by_id: array<string, int>}  $catalog
     * @return array<string, mixed>
     */
    private function ours(Store $store, Carbon $start, array $catalog): array
    {
        $sales = ProductSale::query()
            ->leftJoin('products', 'products.id', '=', 'product_sales.product_id')
            ->where('product_sales.store_id', $store->id)
            ->where('product_sales.period', '>=', $start->toDateString())
            ->where('product_sales.period', '<', $start->copy()->addMonth()->toDateString())
            ->get(['product_sales.revenue', 'product_sales.units', 'product_sales.orders', 'product_sales.returns', 'products.id as catalog_id', 'products.external_id']);

        $fromZid = $sales->filter(fn ($s) => filled($s->external_id));
        $other = $sales->reject(fn ($s) => filled($s->external_id));

        $stored = ['orders' => 0, 'returned' => 0, 'lines' => 0, 'revenue' => 0.0, 'returned_revenue' => 0.0, 'matched_revenue' => 0.0, 'unmatched_lines' => 0, 'unmatched_revenue' => 0.0, 'ids' => []];
        foreach (DB::table('zid_orders')->where('store_id', $store->id)->where('month', $start->toDateString())->cursor() as $order) {
            $stored['orders']++;
            $stored['returned'] += (int) $order->returned;
            $lines = json_decode($order->lines, true) ?: [];
            $sum = 0.0;
            foreach ($lines as $line) {
                $stored['lines']++;
                $revenue = (float) ($line['revenue'] ?? 0);
                $sum += $revenue;
                if ($order->returned) {
                    $stored['returned_revenue'] += $revenue;

                    continue;
                }
                $stored['revenue'] += $revenue;
                if (ZidMapper::productFor($line, $catalog['by_id']) !== null) {
                    $stored['matched_revenue'] += $revenue;
                } else {
                    $stored['unmatched_lines']++;
                    $stored['unmatched_revenue'] += $revenue;
                }
            }
            $stored['ids'][(string) $order->external_id] = ['returned' => (bool) $order->returned, 'revenue' => $sum];
        }

        $range = DB::table('zid_orders')->where('store_id', $store->id)->selectRaw('COUNT(*) AS c, MIN(month) AS first, MAX(month) AS last')->first();

        return [
            // The number the dashboard and the catalog show for this month.
            'sales' => [
                'rows' => $sales->count(),
                'revenue' => $this->round($sales->sum(fn ($s) => (float) $s->revenue)),
                'units' => (int) $sales->sum('units'),
                // Each product line of an order counts once, so this is not the order count.
                'order_lines' => (int) $sales->sum('orders'),
                'returns' => (int) $sales->sum('returns'),
                'zid_products_revenue' => $this->round($fromZid->sum(fn ($s) => (float) $s->revenue)),
                'other_rows' => $other->count(),
                'other_revenue' => $this->round($other->sum(fn ($s) => (float) $s->revenue)),
                'orphan_rows' => $sales->whereNull('catalog_id')->count(),
            ],
            'stored_orders' => [
                ...array_map(fn ($v) => is_float($v) ? $this->round($v) : $v, array_diff_key($stored, ['ids' => true])),
                'ids' => $stored['ids'],
                'all_months' => ['orders' => (int) ($range->c ?? 0), 'first' => $range->first ?? null, 'last' => $range->last ?? null],
            ],
        ];
    }

    /**
     * Reads the month from Zid and summarises it.
     *
     * @param  array{by_id: array<string, int>, by_sku: array<string, int>}  $catalog
     * @param  array<string, array{returned: bool, revenue: float}>  $stored
     * @return array<string, mixed>
     */
    private function zid(Store $store, Carbon $start, Carbon $end, array $catalog, array $stored, ?callable $progress): array
    {
        $client = new ZidClient($store, timeout: 30);
        $client->refreshIfNeeded();
        $format = fn (Carbon $time) => $time->format('Y-m-d\TH:i:s.vO');

        // Zid's own summary of the month, as UTC and as Riyadh time, and
        // without an end date (shows whether date_to is honored).
        $riyadhStart = Carbon::parse($start->toDateString().' 00:00:00', self::STORE_TIMEZONE);
        $windows = [
            'utc' => ['date_from' => $format($start), 'date_to' => $format($end)],
            'riyadh' => ['date_from' => $format($riyadhStart), 'date_to' => $format($riyadhStart->copy()->endOfMonth())],
            'from_only' => ['date_from' => $format($start)],
        ];
        $summaries = [];
        foreach ($windows as $key => $filters) {
            try {
                $page = $this->request(fn () => $client->auditOrderPage([...$filters, 'per_page' => 1], 1));
                $summaries[$key] = ['query' => $filters, 'meta' => $page['meta'], 'first_created_at' => $page['items'][0]['created_at'] ?? null];
            } catch (Throwable $e) {
                $summaries[$key] = ['query' => $filters, 'error' => $this->error($e)];
            }
        }

        // The month with a day either side, so time zone shifts show up.
        $from = $start->copy()->subDay();
        $to = $end->copy()->addDay();
        $filters = ['date_from' => $format($from), 'date_to' => $format($to)];
        $records = [];
        $sample = null;
        $pages = 0;
        $read = 0;
        $complete = false;
        $stopped = null;
        $dateToRejected = false;
        $moneyPaths = [];
        $linePaths = [];
        $rawTimes = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $result = $this->request(fn () => $client->auditOrderPage($filters, $page));
            } catch (ZidApiException $e) {
                if ($page === 1 && $e->httpStatus === 422 && isset($filters['date_to'])) {
                    unset($filters['date_to']);
                    $dateToRejected = true;
                    $page = 0;

                    continue;
                }
                $stopped = ['reason' => 'zid_error', 'page' => $page, ...$this->error($e)];
                break;
            } catch (Throwable $e) {
                $stopped = ['reason' => $e instanceof ZidRateLimited ? 'rate_limited' : 'failed', 'page' => $page, ...$this->error($e)];
                break;
            }
            $pages++;
            $beyond = $result['items'] !== [];
            foreach ($result['items'] as $order) {
                $read++;
                $record = $this->record($order, $catalog);
                if (count($rawTimes) < 3 && $record['created_at'] !== null) {
                    $rawTimes[] = $record['created_at'];
                }
                if ($record['time'] === null || $record['time']->lte($to)) {
                    $beyond = false;
                }
                $records[$record['id'] ?? 'row-'.$read] = $record;
                if ($sample === null && $record['month'] === $start->format('Y-m') && ! $record['cancelled'] && $record['lines'] !== []) {
                    $sample = $this->sanitize($order);
                }
            }
            $progress && $progress($pages, $read);
            if (! $result['has_next']) {
                $complete = true;
                break;
            }
            // date_to was ignored and the read has passed the month.
            if ($beyond) {
                $complete = true;
                break;
            }
        }
        if (! $complete && $stopped === null) {
            $stopped = ['reason' => 'page_limit', 'page' => $pages];
        }

        $key = $start->format('Y-m');
        $month = array_filter($records, fn (array $r) => $r['month'] === $key);
        $counted = array_filter($month, fn (array $r) => ! $r['cancelled']);

        $totals = [
            'order_total_all' => 0.0, 'order_total_counted' => 0.0, 'order_total_not_returned' => 0.0,
            'lines_counted' => 0.0, 'lines_kept' => 0.0, 'our_method' => 0.0,
        ];
        $groups = ['status' => [], 'payment_status' => [], 'payment_method' => [], 'currency' => [], 'total_key' => []];
        $variants = ['lines' => 0, 'revenue' => 0.0];
        $unmatched = ['lines' => 0, 'revenue' => 0.0, 'by_sku' => ['lines' => 0, 'revenue' => 0.0], 'none' => ['lines' => 0, 'revenue' => 0.0], 'samples' => []];
        $returned = ['orders' => 0, 'order_total' => 0.0, 'lines' => 0.0];
        $cancelled = ['orders' => 0, 'order_total' => 0.0];
        $withoutTotal = 0;
        $withoutLines = 0;
        $diffs = [];

        foreach ($month as $record) {
            $total = $record['total'] ?? 0.0;
            $totals['order_total_all'] += $total;
            $withoutTotal += $record['total'] === null ? 1 : 0;
            foreach (['status', 'payment_status', 'payment_method', 'currency', 'total_key'] as $group) {
                $name = $record[$group] ?? '—';
                $groups[$group][$name] ??= ['orders' => 0, 'order_total' => 0.0, 'lines' => 0.0];
                $groups[$group][$name]['orders']++;
                $groups[$group][$name]['order_total'] += $total;
                $groups[$group][$name]['lines'] += $record['lines_revenue'];
            }
            if ($record['cancelled']) {
                $cancelled['orders']++;
                $cancelled['order_total'] += $total;

                continue;
            }
            $totals['order_total_counted'] += $total;
            $totals['lines_counted'] += $record['lines_revenue'];
            $withoutLines += $record['lines'] === [] ? 1 : 0;
            if ($record['total'] !== null) {
                $diffs[] = ['id' => $record['id'], 'status' => $record['status'], 'order_total' => $this->round($record['total']), 'lines' => $this->round($record['lines_revenue']), 'diff' => $this->round($record['total'] - $record['lines_revenue']), 'line_count' => count($record['lines'])];
            }
            foreach ($record['money'] as $path => $value) {
                $moneyPaths[$path] = ($moneyPaths[$path] ?? 0.0) + $value;
            }
            foreach ($record['line_money'] as $path => $value) {
                $linePaths[$path] = ($linePaths[$path] ?? 0.0) + $value;
            }
            if ($record['returned']) {
                $returned['orders']++;
                $returned['order_total'] += $total;
                $returned['lines'] += $record['lines_revenue'];

                continue;
            }
            $totals['order_total_not_returned'] += $total;
            $totals['lines_kept'] += $record['lines_revenue'];
            foreach ($record['lines'] as $line) {
                // Options (sizes, colors) count for the product they belong to.
                if ($line['match'] === 'id' || $line['match'] === 'parent') {
                    $totals['our_method'] += $line['revenue'];
                    $variants['lines'] += (int) ($line['match'] === 'parent');
                    $variants['revenue'] += $line['match'] === 'parent' ? $line['revenue'] : 0.0;

                    continue;
                }
                $unmatched['lines']++;
                $unmatched['revenue'] += $line['revenue'];
                $bucket = $line['match'] === 'sku' ? 'by_sku' : 'none';
                $unmatched[$bucket]['lines']++;
                $unmatched[$bucket]['revenue'] += $line['revenue'];
                if (count($unmatched['samples']) < self::SAMPLES) {
                    $unmatched['samples'][] = ['order' => $record['id'], ...array_diff_key($line, ['match' => true]), 'would_match_by' => $line['match']];
                }
            }
        }

        usort($diffs, fn ($a, $b) => abs($b['diff']) <=> abs($a['diff']));

        return [
            'read' => [
                'query' => $filters, 'date_to_rejected' => $dateToRejected, 'pages' => $pages, 'orders' => $read,
                'complete' => $complete, 'stopped' => $stopped, 'raw_created_at' => $rawTimes,
            ],
            'summaries' => $summaries,
            'month_orders' => count($month),
            'counted_orders' => count($counted),
            'totals' => array_map(fn ($v) => $this->round($v), $totals),
            'cancelled' => array_map(fn ($v) => is_float($v) ? $this->round($v) : $v, $cancelled),
            'returned' => array_map(fn ($v) => is_float($v) ? $this->round($v) : $v, $returned),
            'unmatched' => $this->roundTree($unmatched),
            'matched_by_parent' => $this->roundTree($variants),
            'orders_without_total' => $withoutTotal,
            'orders_without_lines' => $withoutLines,
            'groups' => $this->roundTree($groups),
            'order_fields' => $this->topPaths($moneyPaths),
            'line_fields' => $this->topPaths($linePaths),
            'largest_differences' => array_slice(array_values(array_filter($diffs, fn ($d) => abs($d['diff']) >= 0.01)), 0, 8),
            'timezone' => $this->timezone($records, $key),
            'reconciliation' => $this->reconcile($store, $records, $counted, $stored),
            'sample_order' => $sample,
        ];
    }

    /**
     * One order reduced to what the audit needs.
     *
     * @param  array<string, mixed>  $order
     * @param  array{by_id: array<string, int>, by_sku: array<string, int>}  $catalog
     * @return array<string, mixed>
     */
    private function record(array $order, array $catalog): array
    {
        $created = $order['created_at'] ?? $order['date'] ?? null;
        $created = is_string($created) && $created !== '' ? $created : null;
        $time = $this->time($created);
        $mapped = $this->mapper->order($order);
        $status = $this->text($order['order_status']['code'] ?? null) ?? $this->text($order['order_status']['name'] ?? null) ?? $this->text($order['status'] ?? null);

        [$total, $totalKey] = [null, null];
        foreach (self::TOTAL_KEYS as $candidate) {
            if (($value = $this->number($order[$candidate] ?? null)) !== null) {
                [$total, $totalKey] = [$value, $candidate];
                break;
            }
        }

        $lines = [];
        $lineMoney = [];
        $raw = $order['products'] ?? $order['items'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $id = $line['product_id'] ?? $line['id'] ?? null;
            if (! is_scalar($id)) {
                continue;
            }
            $id = (string) $id;
            $parent = is_scalar($line['parent_id'] ?? null) ? (string) $line['parent_id'] : null;
            $sku = is_scalar($line['sku'] ?? null) ? (string) $line['sku'] : null;
            $units = (int) ($line['quantity'] ?? 1);
            // Exactly as the import reads a line.
            $revenue = $this->number($line['total'] ?? null) ?? ($this->number($line['price'] ?? null) ?? 0) * $units;
            $lines[] = [
                'id' => $id, 'parent_id' => $parent, 'sku' => $sku,
                'name' => mb_substr((string) ($this->text($line['name'] ?? null) ?? ''), 0, 80),
                'units' => $units, 'revenue' => (float) $revenue,
                'match' => match (true) {
                    isset($catalog['by_id'][ZidMapper::key($id) ?? '']) => 'id',
                    $parent !== null && isset($catalog['by_id'][ZidMapper::key($parent) ?? '']) => 'parent',
                    $sku !== null && isset($catalog['by_sku'][$sku]) => 'sku',
                    default => null,
                },
            ];
            foreach ($this->money($line, ignore: ['images', 'options']) as $path => $value) {
                $lineMoney[$path] = ($lineMoney[$path] ?? 0.0) + $value;
            }
        }

        return [
            'id' => isset($order['id']) && is_scalar($order['id']) ? (string) $order['id'] : null,
            'created_at' => $created,
            'time' => $time,
            'offset' => $created !== null && preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $created) === 1,
            // The month the import files the order under.
            'month' => $mapped !== null ? substr($mapped['month'], 0, 7) : $this->mapper->placedAt($created)?->format('Y-m'),
            'status' => $status !== null ? mb_strtolower($status) : null,
            'payment_status' => $this->text($order['payment_status']['code'] ?? null) ?? $this->text($order['payment_status'] ?? null) ?? $this->text($order['payment']['status'] ?? null),
            'payment_method' => $this->text($order['payment']['method']['code'] ?? null) ?? $this->text($order['payment_method']['code'] ?? null) ?? $this->text($order['payment_method'] ?? null),
            'currency' => $this->text($order['currency_code'] ?? null) ?? $this->text($order['currency']['order_currency']['code'] ?? null) ?? $this->text($order['currency']['code'] ?? null) ?? $this->text($order['currency'] ?? null),
            'total' => $total,
            'total_key' => $totalKey,
            'cancelled' => $mapped === null,
            'returned' => (bool) ($mapped['returned'] ?? false),
            'lines' => $lines,
            'lines_revenue' => array_sum(array_column($lines, 'revenue')),
            'money' => $this->money($order, ignore: ['products', 'items', 'histories', 'images']),
            'line_money' => $lineMoney,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function timezone(array $records, string $key): array
    {
        $withOffset = 0;
        $shiftedIn = 0;
        $shiftedOut = 0;
        $boundary = ['orders' => 0, 'order_total' => 0.0];
        foreach ($records as $record) {
            if (! $record['time'] instanceof Carbon) {
                continue;
            }
            if ($record['offset']) {
                $withOffset++;
                $riyadh = $record['time']->copy()->setTimezone(self::STORE_TIMEZONE)->format('Y-m');
                $shiftedIn += (int) ($riyadh === $key && $record['month'] !== $key);
                $shiftedOut += (int) ($riyadh !== $key && $record['month'] === $key);
            }
            // Orders within three hours of the month's edges, as written by Zid.
            $local = Carbon::parse($record['time']->format('Y-m-d H:i:s'));
            $near = $local->copy()->startOfMonth()->addHours(3)->gt($local) || $local->copy()->endOfMonth()->subHours(3)->lt($local);
            if ($near && ! $record['cancelled'] && $record['month'] === $key) {
                $boundary['orders']++;
                $boundary['order_total'] += $record['total'] ?? 0.0;
            }
        }

        return [
            'with_offset' => $withOffset, 'without_offset' => count($records) - $withOffset,
            'riyadh_moves_in' => $shiftedIn, 'riyadh_moves_out' => $shiftedOut,
            'near_month_edge' => ['orders' => $boundary['orders'], 'order_total' => $this->round($boundary['order_total'])],
        ];
    }

    /**
     * Orders Zid has for the month against those the import stored for it.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @param  array<string, array<string, mixed>>  $counted
     * @param  array<string, array{returned: bool, revenue: float}>  $stored
     * @return array<string, mixed>
     */
    private function reconcile(Store $store, array $records, array $counted, array $stored): array
    {
        $missing = array_diff_key($counted, $stored);
        $storedElsewhere = $missing === [] ? [] : DB::table('zid_orders')->where('store_id', $store->id)
            ->whereIn('external_id', array_map('strval', array_keys($missing)))->pluck('month', 'external_id')->all();

        $extra = ['count' => 0, 'revenue' => 0.0, 'cancelled_in_zid' => 0, 'other_month_in_zid' => 0, 'not_read' => 0, 'samples' => []];
        foreach (array_diff_key($stored, $counted) as $id => $row) {
            $extra['count']++;
            $extra['revenue'] += $row['returned'] ? 0 : $row['revenue'];
            $live = $records[$id] ?? null;
            $reason = match (true) {
                $live === null => 'not_read',
                $live['cancelled'] => 'cancelled_in_zid',
                default => 'other_month_in_zid',
            };
            $extra[$reason]++;
            if (count($extra['samples']) < self::SAMPLES) {
                $extra['samples'][] = ['id' => (string) $id, 'reason' => $reason, 'revenue' => $this->round($row['revenue']), 'zid_month' => $live['month'] ?? null, 'zid_status' => $live['status'] ?? null];
            }
        }

        $changed = ['returned_flag' => 0, 'lines_revenue' => 0, 'lines_difference' => 0.0, 'samples' => []];
        foreach (array_intersect_key($counted, $stored) as $id => $live) {
            $row = $stored[$id];
            $changed['returned_flag'] += (int) ($row['returned'] !== $live['returned']);
            $difference = $live['lines_revenue'] - $row['revenue'];
            if (abs($difference) >= 0.01) {
                $changed['lines_revenue']++;
                $changed['lines_difference'] += $difference;
                if (count($changed['samples']) < self::SAMPLES) {
                    $changed['samples'][] = ['id' => (string) $id, 'stored' => $this->round($row['revenue']), 'zid' => $this->round($live['lines_revenue'])];
                }
            }
        }

        return [
            'stored_for_month' => count($stored),
            'zid_counted_for_month' => count($counted),
            'missing_from_ours' => [
                'count' => count($missing),
                'lines_revenue' => $this->round(array_sum(array_map(fn ($r) => $r['returned'] ? 0 : $r['lines_revenue'], $missing))),
                'stored_under_other_month' => count($storedElsewhere),
                'samples' => array_slice(array_map(fn ($r) => [
                    'id' => $r['id'], 'created_at' => $r['created_at'], 'status' => $r['status'],
                    'order_total' => $r['total'] !== null ? $this->round($r['total']) : null, 'lines' => $this->round($r['lines_revenue']),
                    'stored_month' => $storedElsewhere[$r['id']] ?? null,
                ], array_values($missing)), 0, self::SAMPLES),
            ],
            'extra_in_ours' => $this->roundTree($extra),
            'changed_since_import' => $this->roundTree($changed),
        ];
    }

    /**
     * Every figure the user might be comparing against, and how far each is
     * from the number they typed from the Zid dashboard.
     *
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $zid
     * @return array<string, mixed>
     */
    private function candidates(array $ours, array $zid, ?float $expected): array
    {
        $values = [
            'ours_dashboard' => $ours['sales']['revenue'],
            'ours_stored_orders' => $ours['stored_orders']['revenue'],
            'zid_order_total_all' => $zid['totals']['order_total_all'],
            'zid_order_total_counted' => $zid['totals']['order_total_counted'],
            'zid_order_total_not_returned' => $zid['totals']['order_total_not_returned'],
            'zid_lines_counted' => $zid['totals']['lines_counted'],
            'zid_lines_kept' => $zid['totals']['lines_kept'],
            'zid_our_method' => $zid['totals']['our_method'],
        ];
        foreach (['utc', 'riyadh'] as $window) {
            foreach ($zid['summaries'][$window]['meta'] ?? [] as $field => $value) {
                if (is_numeric($value) && preg_match(self::MONEY, (string) $field)) {
                    $values["zid_list_{$window}_{$field}"] = $this->round((float) $value);
                }
            }
        }

        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = ['key' => $key, 'value' => $value, 'diff_from_expected' => $expected !== null ? $this->round($value - $expected) : null];
        }
        $closest = $expected === null ? null : collect($rows)->sortBy(fn ($r) => abs($r['diff_from_expected']))->first()['key'];

        return ['rows' => $rows, 'closest_to_expected' => $closest];
    }

    /**
     * Likely causes of the gap, largest first, for the page to explain.
     *
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $zid
     * @return list<array{code: string, amount: float|null, count: int|null}>
     */
    private function findings(array $ours, array $zid): array
    {
        $found = [];
        $add = function (string $code, ?float $amount = null, ?int $count = null) use (&$found): void {
            $found[] = ['code' => $code, 'amount' => $amount !== null ? $this->round($amount) : null, 'count' => $count];
        };
        $reconcile = $zid['reconciliation'];
        $totals = $zid['totals'];

        if ($ours['sales']['other_revenue'] >= 1) {
            $add('non_zid_rows', $ours['sales']['other_revenue'], $ours['sales']['other_rows']);
        }
        // Comparisons with a partial read of Zid would mislead.
        if (! $zid['read']['complete']) {
            $add('incomplete_read', null, $zid['read']['orders']);

            return $found;
        }
        $stale = $ours['sales']['zid_products_revenue'] - $totals['our_method'];
        if (abs($stale) >= 1) {
            $add('stale_import', $stale, $reconcile['missing_from_ours']['count'] + $reconcile['extra_in_ours']['count'] + $reconcile['changed_since_import']['lines_revenue']);
        }
        if ($reconcile['missing_from_ours']['count'] > 0) {
            $add('missing_orders', $reconcile['missing_from_ours']['lines_revenue'], $reconcile['missing_from_ours']['count']);
        }
        if ($reconcile['extra_in_ours']['count'] > 0) {
            $add('extra_orders', $reconcile['extra_in_ours']['revenue'], $reconcile['extra_in_ours']['count']);
        }
        if ($zid['unmatched']['revenue'] >= 1) {
            $add('unmatched_lines', $zid['unmatched']['revenue'], $zid['unmatched']['lines']);
        }
        $orderLevel = $totals['order_total_counted'] - $totals['lines_counted'];
        if ($zid['orders_without_total'] < $zid['counted_orders'] && abs($orderLevel) >= 1) {
            $add('order_level_amounts', $orderLevel, $zid['counted_orders']);
        }
        if ($zid['returned']['lines'] >= 1) {
            $add('returns_excluded', $zid['returned']['lines'], $zid['returned']['orders']);
        }
        if ($zid['cancelled']['orders'] > 0) {
            $add('cancelled_excluded', $zid['cancelled']['order_total'], $zid['cancelled']['orders']);
        }
        $moves = $zid['timezone']['riyadh_moves_in'] + $zid['timezone']['riyadh_moves_out'];
        if ($moves > 0) {
            $add('timezone_shift', null, $moves);
        }
        $currencies = array_keys($zid['groups']['currency']);
        if (count($currencies) > 1 || ($currencies !== [] && ! in_array(strtoupper((string) $currencies[0]), ['SAR', '—'], true))) {
            $add('currency', null, count($currencies));
        }
        if ($ours['sales']['order_lines'] !== $zid['counted_orders'] - $zid['returned']['orders'] && $ours['sales']['order_lines'] > 0) {
            $add('orders_are_lines', null, $ours['sales']['order_lines']);
        }

        usort($found, fn ($a, $b) => abs($b['amount'] ?? 0) <=> abs($a['amount'] ?? 0));

        return $found;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastSync(Store $store): ?array
    {
        $run = SyncRun::query()->where('store_id', $store->id)->where('type', 'zid_sync')->latest('id')->first();
        if (! $run) {
            return null;
        }
        $completed = SyncRun::query()->where('store_id', $store->id)->where('type', 'zid_sync')->where('status', 'completed')->latest('id')->first();

        return [
            'id' => $run->id, 'status' => $run->status, 'error' => $run->error,
            'scope' => $run->stats['scope'] ?? null, 'phase' => $run->stats['phase'] ?? null,
            'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
            'orders_since' => $run->stats['orders_since'] ?? null, 'changed_since' => $run->stats['changed_since'] ?? null,
            'orders_read' => $run->stats['orders_read'] ?? null, 'orders' => $run->stats['orders'] ?? null,
            'last_completed_at' => $completed?->finished_at?->toIso8601String(),
            'last_full_completed_at' => SyncRun::query()->where('store_id', $store->id)->where('type', 'zid_sync')->where('status', 'completed')
                ->where(fn ($q) => $q->where('stats->scope', 'full')->orWhereNull('stats->scope'))->latest('id')->first()?->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Retries short rate-limit waits instead of abandoning the read.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function request(callable $call): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (ZidRateLimited $e) {
                if ($attempt >= 4 || $e->retryAfter > 60) {
                    throw $e;
                }
                sleep($e->retryAfter);
            }
        }
    }

    /**
     * Money-like numeric leaves by dotted path, skipping personal data.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $ignore
     * @return array<string, float>
     */
    private function money(array $data, string $prefix = '', int $depth = 0, array $ignore = []): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || in_array($key, $ignore, true) || preg_match(self::PRIVATE, $key)) {
                continue;
            }
            $path = $prefix === '' ? $key : $prefix.'.'.$key;
            if (is_array($value)) {
                if ($depth < 4 && ! array_is_list($value)) {
                    $out += $this->money($value, $path, $depth + 1);
                }

                continue;
            }
            if ((is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
                && preg_match(self::MONEY, $key) && ! preg_match(self::NOT_MONEY, $key)) {
                $out[$path] = (float) $value;
            }
        }

        return $out;
    }

    /**
     * The order as Zid sent it, without personal data or links.
     */
    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value)) {
            if ($depth >= 6) {
                return '[…]';
            }
            $out = [];
            $list = array_is_list($value);
            foreach ($value as $key => $item) {
                if ($list && $key >= 3) {
                    $out[] = '[+'.(count($value) - 3).']';
                    break;
                }
                $out[$key] = is_string($key) && preg_match(self::PRIVATE, $key) ? '[hidden]' : $this->sanitize($item, $depth + 1);
            }

            return $out;
        }
        if (is_string($value)) {
            return str_starts_with($value, 'http') ? '[link]' : mb_substr($value, 0, 160);
        }

        return $value;
    }

    /**
     * @param  array<string, float>  $paths
     * @return array<string, float>
     */
    private function topPaths(array $paths): array
    {
        uasort($paths, fn ($a, $b) => abs($b) <=> abs($a));

        return array_map(fn ($v) => $this->round($v), array_slice($paths, 0, 40, true));
    }

    private function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['ar'] ?? $value['en'] ?? $value['code'] ?? $value['name'] ?? null;
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function number(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value['amount'] ?? $value['value'] ?? null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value !== null ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function round(float|int $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @return array<array-key, mixed>
     */
    private function roundTree(array $tree): array
    {
        return array_map(fn ($v) => is_array($v) ? $this->roundTree($v) : (is_float($v) ? $this->round($v) : $v), $tree);
    }

    /**
     * @return array{http: int|null, message: string}
     */
    private function error(Throwable $e): array
    {
        $status = $e instanceof ZidApiException ? $e->httpStatus : null;
        $previous = $e->getPrevious();
        if ($status === null && $previous instanceof RequestException) {
            $status = $previous->response->status();
        }

        return ['http' => $status, 'message' => mb_substr($e->getMessage(), 0, 400)];
    }
}
