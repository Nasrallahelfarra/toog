<?php

namespace App\Services\Analytics;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Classifies every catalog product as winner / average / stagnant (FR-1.3).
 *
 * Each product gets a 0-100 performance score from its percentile rank in
 * the store on five signals; the weights below are shown to the user so
 * the classification is never a black box.
 */
class PerformanceClassifier
{
    private const WRITE_BATCH = 100;

    private const RESULT_COLUMNS = ['performance', 'performance_score', 'units_sold', 'revenue', 'views', 'add_to_cart_rate', 'return_rate', 'turnover_days'];

    public const WEIGHTS = [
        'velocity' => 45,        // units sold per observed month
        'revenue' => 20,
        'add_to_cart' => 15,     // add-to-cart rate
        'returns' => 10,         // lower return rate is better
        'turnover' => 10,        // fewer days to sell the stock is better
    ];

    public const WINNER_FROM = 67;

    public const STAGNANT_UNTIL = 33;

    public const UNSOLD_AFTER_DAYS = 90;

    public function classify(Store $store, int $months = 12): void
    {
        $products = Product::query()->where('store_id', $store->id)
            ->get(['id', 'external_id', 'stock', 'launched_at', 'created_at', ...self::RESULT_COLUMNS]);
        $metrics = $this->metrics($store, $products, $months);
        $measured = $metrics->filter(fn (array $m) => $m['has_data']);

        $ranked = collect(array_keys(self::WEIGHTS))->mapWithKeys(fn ($signal) => [
            $signal => $this->percentiles($measured->filter(fn ($m) => $signal !== 'add_to_cart' || $m['views'] > 0)
                ->map(fn ($m) => (float) $m[$signal]), lowerIsBetter: self::lowerIsBetter($signal)),
        ]);

        $updates = [];
        foreach ($products as $product) {
            $m = $metrics[$product->id];

            if (! $m['has_data']) {
                $product->fill(['performance' => null, 'performance_score' => null,
                    'units_sold' => 0, 'revenue' => 0, 'views' => 0, 'add_to_cart_rate' => 0,
                    'return_rate' => 0, 'turnover_days' => null]);
                if ($product->isDirty(self::RESULT_COLUMNS)) {
                    $updates[$product->id] = $product->only(self::RESULT_COLUMNS);
                }

                continue;
            }

            $score = 0.0;
            foreach (self::WEIGHTS as $signal => $weight) {
                $score += ($ranked[$signal][$product->id] ?? 0) * $weight / 100;
            }
            $score = (int) round($score);

            $class = match (true) {
                $score <= self::STAGNANT_UNTIL || $m['turnover'] >= 240 => Product::STAGNANT,
                $score >= self::WINNER_FROM && $m['returns'] < 25 => Product::WINNER,
                default => Product::AVERAGE,
            };

            $product->fill([
                'performance' => $class,
                'performance_score' => $score,
                'units_sold' => $m['units'],
                'revenue' => $m['revenue'],
                'views' => $m['views'],
                'add_to_cart_rate' => round($m['add_to_cart'], 2),
                'return_rate' => round($m['returns'], 2),
                'turnover_days' => $m['turnover'],
            ]);
            if ($product->isDirty(self::RESULT_COLUMNS)) {
                $updates[$product->id] = $product->only(self::RESULT_COLUMNS);
            }
        }

        // Save only derived metrics, in bounded statements. Product names,
        // images and features are never overwritten or reinserted.
        DB::transaction(function () use ($store, $updates): void {
            foreach (array_chunk($updates, self::WRITE_BATCH, true) as $batch) {
                $this->saveBatch($store->id, $batch);
            }
        });
    }

    /** @param array<int, array<string, mixed>> $batch */
    private function saveBatch(int $storeId, array $batch): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $id = $grammar->wrap('id');
        $sets = [];
        $bindings = [];
        foreach (self::RESULT_COLUMNS as $column) {
            $cases = [];
            foreach ($batch as $productId => $values) {
                // IDs come from the database and are rendered as integers;
                // every result value remains a bound parameter.
                $cases[] = 'WHEN '.(int) $productId.' THEN ?';
                $bindings[] = $values[$column];
            }
            $sets[] = $grammar->wrap($column).' = CASE '.$id.' '.implode(' ', $cases).' ELSE '.$grammar->wrap($column).' END';
        }
        $sets[] = $grammar->wrap('updated_at').' = ?';
        $ids = array_keys($batch);
        DB::update('UPDATE '.$grammar->wrapTable('products').' SET '.implode(', ', $sets)
            .' WHERE '.$grammar->wrap('store_id').' = ? AND '.$id.' IN ('.implode(', ', array_fill(0, count($ids), '?')).')',
            [...$bindings, now()->toDateTimeString(), $storeId, ...$ids]);
    }

    public static function lowerIsBetter(string $signal): bool
    {
        return in_array($signal, ['returns', 'turnover'], true);
    }

    /**
     * Each product's raw value on every signal over the last $months.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, array{units: int, revenue: float, views: int, velocity: float|int, add_to_cart: float|int, returns: float|int, turnover: int, has_data: bool}>
     */
    public function metrics(Store $store, Collection $products, int $months = 12): Collection
    {
        $since = now()->startOfMonth()->subMonths(max(0, $months - 1));
        $coverage = $this->salesCoverage($store, $since);

        $sales = ProductSale::query()
            ->where('store_id', $store->id)
            ->where('period', '>=', $since)
            ->where('period', '<=', now())
            ->selectRaw('product_id, COUNT(*) AS observed_months, SUM(units) AS units, SUM(revenue) AS revenue, SUM(views) AS views, SUM(add_to_cart) AS add_to_cart, SUM(returns) AS returns')
            ->groupBy('product_id')->get()->keyBy('product_id');

        return $products->mapWithKeys(function (Product $p) use ($sales, $coverage) {
            $row = $sales->get($p->id);
            $units = (int) ($row->units ?? 0);
            $views = (int) ($row->views ?? 0);
            // An absent order row is only evidence of zero sales after a
            // complete Zid import, for a product old enough to be observed.
            $observedDays = null;
            if ($coverage && filled($p->external_id) && $p->created_at?->lte($coverage['until'])) {
                $launched = $p->launched_at ?? $p->created_at;
                $start = $launched->gt($coverage['since']) ? $launched : $coverage['since'];
                $observedDays = max(0, (int) $start->diffInDays($coverage['until'], false));
            }
            $observedMonths = max(1, $observedDays !== null ? $observedDays / 30 : (int) ($row?->getAttribute('observed_months') ?? 0));
            $dailyUnits = $units / ($observedMonths * 30);

            return [$p->id => [
                'units' => $units,
                'revenue' => (float) ($row->revenue ?? 0),
                'views' => $views,
                'velocity' => $units / $observedMonths,
                'add_to_cart' => $views > 0 ? ($row->add_to_cart ?? 0) / $views * 100 : 0,
                'returns' => $units > 0 ? ($row->returns ?? 0) / $units * 100 : 0,
                'turnover' => $p->stock <= 0 ? 0 : ($dailyUnits > 0 ? min(365, (int) round($p->stock / $dailyUnits)) : 365),
                'has_data' => $row !== null || ($p->stock > 0 && $observedDays !== null && $observedDays >= self::UNSOLD_AFTER_DAYS),
            ]];
        });
    }

    /**
     * Missing CSV rows or unfinished imports do not prove zero sales.
     * A full import establishes coverage; subsequent changes keep it current.
     *
     * @return array{since: CarbonInterface, until: CarbonInterface}|null
     */
    private function salesCoverage(Store $store, CarbonInterface $since): ?array
    {
        if ($store->platform !== 'zid' || blank($store->platform_store_id)) {
            return null;
        }
        $runs = $store->syncRuns()->where('type', 'zid_sync')->where('status', 'completed')
            ->where('stats->zid_store_id', (string) $store->platform_store_id)->whereNotNull('finished_at');
        $latest = (clone $runs)->latest('id')->first();
        $full = (clone $runs)->where(fn ($q) => $q->where('stats->scope', 'full')->orWhereNull('stats->scope'))
            ->whereNotNull('stats->orders_since')->latest('id')->first();
        if (! $latest || ! $full) {
            return null;
        }

        $start = Carbon::parse($full->stats['orders_since']);

        return ['since' => $start->gt($since) ? $start : $since, 'until' => $latest->finished_at];
    }

    /**
     * Percentile rank (0-100) of each value among all values.
     *
     * @param  Collection<int, float>  $values
     * @return array<int, float>
     */
    private function percentiles(Collection $values, bool $lowerIsBetter = false): array
    {
        $sorted = $values->sort()->values();
        $n = $sorted->count();
        $ranks = [];
        $offset = 0;
        foreach ($sorted->groupBy(fn ($v) => (string) $v) as $value => $ties) {
            // Equal values share their midpoint rank; a constant signal is neutral.
            $ranks[$value] = $n > 1 ? ($offset + ($ties->count() - 1) / 2) / ($n - 1) * 100 : 50;
            $offset += $ties->count();
        }

        return $values->map(function ($v) use ($ranks, $lowerIsBetter) {
            $rank = $ranks[(string) $v];

            return $lowerIsBetter ? 100 - $rank : $rank;
        })->all();
    }
}
