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
 * Separates products that have stopped selling from those at risk of it,
 * by sales alone.
 *
 * Stock is never a signal: stores that make pieces to order carry stock
 * numbers that are not real, so "has stock" proves nothing and "days to
 * sell the stock" means nothing. A product is stagnant when it has gone
 * 90 days without a sale, and at risk when it has gone 60, or sells weakly
 * beside the store's other selling products.
 *
 * Each product that sold gets a 0-100 performance score from its
 * percentile rank among the store's selling products on four signals; the
 * weights below are shown to the user so the classification is never a
 * black box. A product that never sold is not ranked: it scores 0.
 */
class PerformanceClassifier
{
    public const VERSION = 3;

    private const WRITE_BATCH = 100;

    // turnover_days was stock-based; it is saved as null to clear old values.
    private const RESULT_COLUMNS = ['performance', 'performance_score', 'units_sold', 'revenue', 'views', 'add_to_cart_rate', 'return_rate', 'turnover_days', 'no_sales_days'];

    public const WEIGHTS = [
        'velocity' => 55,        // units sold per observed month
        'revenue' => 20,
        'add_to_cart' => 15,     // add-to-cart rate
        'returns' => 10,         // lower return rate is better
    ];

    public const WINNER_FROM = 67;

    public const STAGNANT_UNTIL = 33;

    /** Days without a sale that make a product stagnant. */
    public const UNSOLD_AFTER_DAYS = 90;

    /** Days without a sale that warn it is heading there. */
    public const QUIET_AFTER_DAYS = 60;

    public function classify(Store $store, int $months = 12): void
    {
        $products = Product::query()->where('store_id', $store->id)
            ->get(['id', 'external_id', 'launched_at', 'created_at', ...self::RESULT_COLUMNS]);
        $metrics = $this->metrics($store, $products, $months);
        // Ranked among the products that sold: a long tail of never-sold
        // products would otherwise lift every seller to the top.
        $measured = $metrics->filter(fn (array $m) => $m['ranked']);

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
                    'return_rate' => 0, 'turnover_days' => null, 'no_sales_days' => $m['no_sales_days']]);
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
            $quiet = (int) $m['no_sales_days'];

            // Sales decide, whatever the stock says.
            $class = match (true) {
                $quiet >= self::UNSOLD_AFTER_DAYS => Product::STAGNANT,
                // Recorded but never sold, without the 90 days to call it stagnant.
                $quiet >= self::QUIET_AFTER_DAYS || $score <= self::STAGNANT_UNTIL || $m['units'] === 0 => Product::AT_RISK,
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
                'turnover_days' => null,
                'no_sales_days' => $m['no_sales_days'],
            ]);
            if ($product->isDirty(self::RESULT_COLUMNS)) {
                $updates[$product->id] = $product->only(self::RESULT_COLUMNS);
            }
        }

        // Save only derived metrics, in bounded statements. Product names,
        // images and features are never overwritten or reinserted.
        DB::transaction(function () use ($store, $updates): void {
            $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
            foreach (array_chunk($updates, self::WRITE_BATCH, true) as $batch) {
                $this->saveBatch($store->id, $batch);
            }
            if (($locked->settings['performance_classification_version'] ?? null) !== self::VERSION) {
                $locked->update(['settings' => [...($locked->settings ?? []), 'performance_classification_version' => self::VERSION]]);
            }
            $store->settings = $locked->settings;
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
        return $signal === 'returns';
    }

    /**
     * Each product's raw value on every signal over the last $months.
     *
     * @param  Collection<int, Product>  $products
     *                                              `has_data`: there is enough to classify the product. `ranked`: it has
     *                                              sales rows, so it takes part in the percentile ranking.
     *                                              `no_sales_days` is null when the data cannot show a quiet period.
     * @return Collection<int, array{units: int, revenue: float, views: int, velocity: float|int, add_to_cart: float|int, returns: float|int, has_data: bool, ranked: bool, no_sales_days: int|null}>
     */
    public function metrics(Store $store, Collection $products, int $months = 12): Collection
    {
        $since = now()->startOfMonth()->subMonths(max(0, $months - 1));
        $coverage = $this->salesCoverage($store, $since);

        $sales = ProductSale::query()
            ->where('store_id', $store->id)
            ->where('period', '>=', $since)
            ->where('period', '<=', now())
            ->selectRaw('product_id, COUNT(*) AS observed_months, MIN(period) AS first_period, MAX(period) AS last_period, MAX(CASE WHEN units > 0 THEN period END) AS last_sale_period, SUM(units) AS units, SUM(revenue) AS revenue, SUM(views) AS views, SUM(add_to_cart) AS add_to_cart, SUM(returns) AS returns')
            ->groupBy('product_id')->get()->keyBy('product_id');

        return $products->mapWithKeys(function (Product $p) use ($sales, $coverage) {
            $row = $sales->get($p->id);
            $units = (int) ($row->units ?? 0);
            $views = (int) ($row->views ?? 0);
            // An absent order row is only evidence of zero sales after a
            // complete Zid import, for a product old enough to be observed.
            $observedDays = null;
            $observedStart = null;
            $observedUntil = null;
            if ($coverage && filled($p->external_id) && $p->created_at?->lte($coverage['until'])) {
                $launched = $p->launched_at ?? $p->created_at;
                $start = $launched->gt($coverage['since']) ? $launched : $coverage['since'];
                $observedDays = max(0, (int) $start->diffInDays($coverage['until'], false));
                $observedStart = $start;
                $observedUntil = $coverage['until'];
            } elseif ($row) {
                // Explicit, consecutive monthly rows can establish coverage.
                // A missing month is unknown, never an implied zero-sales month.
                $first = Carbon::parse($row->getAttribute('first_period'))->startOfMonth();
                $last = Carbon::parse($row->getAttribute('last_period'))->startOfMonth();
                if ((int) $row->getAttribute('observed_months') === (int) $first->diffInMonths($last) + 1) {
                    $observedStart = $p->launched_at && $p->launched_at->gt($first) ? $p->launched_at : $first;
                    // Only completed CSV months prove a full period without sales.
                    $observedUntil = $last->copy()->addMonth()->min(now()->startOfMonth());
                }
            }
            $noSalesDays = null;
            if ($observedStart && $observedUntil) {
                $lastSale = $row?->getAttribute('last_sale_period');
                // Monthly totals do not reveal the day of the last sale. Assume
                // it happened at month end, so we never overstate the quiet period.
                $quietStart = $lastSale ? Carbon::parse($lastSale)->startOfMonth()->addMonth()->max($observedStart) : $observedStart;
                $noSalesDays = max(0, (int) $quietStart->diffInDays($observedUntil, false));
            }
            $observedMonths = max(1, $observedDays !== null ? $observedDays / 30 : (int) ($row?->getAttribute('observed_months') ?? 0));

            return [$p->id => [
                'units' => $units,
                'revenue' => (float) ($row->revenue ?? 0),
                'views' => $views,
                'velocity' => $units / $observedMonths,
                'add_to_cart' => $views > 0 ? ($row->add_to_cart ?? 0) / $views * 100 : 0,
                'returns' => $units > 0 ? ($row->returns ?? 0) / $units * 100 : 0,
                // No sales rows still classifies a product once a complete
                // import has watched it long enough: it is not selling.
                'has_data' => $row !== null || ($observedDays !== null && $observedDays >= self::UNSOLD_AFTER_DAYS),
                'ranked' => $row !== null,
                'no_sales_days' => $noSalesDays === null ? null : min(65535, $noSalesDays),
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
