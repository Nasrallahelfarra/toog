<?php

namespace App\Services\Analytics;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Classifies every catalog product as winner / average / stagnant (FR-1.3).
 *
 * Each product gets a 0-100 performance score from its percentile rank in
 * the store on five signals; the weights below are shown to the user so
 * the classification is never a black box.
 */
class PerformanceClassifier
{
    public const WEIGHTS = [
        'velocity' => 45,        // units sold per active month
        'revenue' => 20,
        'add_to_cart' => 15,     // add-to-cart rate
        'returns' => 10,         // lower return rate is better
        'turnover' => 10,        // fewer days to sell the stock is better
    ];

    public const WINNER_FROM = 67;

    public const STAGNANT_UNTIL = 33;

    public function classify(Store $store, int $months = 12): void
    {
        $since = now()->subMonths($months)->startOfMonth();

        $sales = ProductSale::query()
            ->where('store_id', $store->id)
            ->where('period', '>=', $since)
            ->get()
            ->groupBy('product_id');

        $products = Product::query()->where('store_id', $store->id)->get();

        $metrics = $products->mapWithKeys(function (Product $p) use ($sales) {
            /** @var Collection<int, ProductSale> $rows */
            $rows = $sales->get($p->id, collect());
            $units = (int) $rows->sum('units');
            $views = (int) $rows->sum('views');
            $activeMonths = max(1, $rows->where('units', '>', 0)->count());
            $dailyUnits = $units / max(30, $rows->count() * 30);

            return [$p->id => [
                'units' => $units,
                'revenue' => (float) $rows->sum('revenue'),
                'views' => $views,
                'velocity' => $units / $activeMonths,
                'add_to_cart' => $views > 0 ? $rows->sum('add_to_cart') / $views * 100 : 0,
                'returns' => $units > 0 ? $rows->sum('returns') / $units * 100 : 0,
                'turnover' => $dailyUnits > 0 ? min(365, (int) round(max(0, $p->stock) / $dailyUnits)) : 365,
                'has_data' => $rows->isNotEmpty(),
            ]];
        });

        $ranked = collect(array_keys(self::WEIGHTS))->mapWithKeys(fn ($signal) => [
            $signal => $this->percentiles($metrics->map(fn ($m) => $m[$signal]), lowerIsBetter: in_array($signal, ['returns', 'turnover'], true)),
        ]);

        foreach ($products as $product) {
            $m = $metrics[$product->id];

            if (! $m['has_data']) {
                $product->update(['performance' => null, 'performance_score' => null]);

                continue;
            }

            $score = 0.0;
            foreach (self::WEIGHTS as $signal => $weight) {
                $score += $ranked[$signal][$product->id] * $weight / 100;
            }

            $class = match (true) {
                $score >= self::WINNER_FROM && $m['returns'] < 25 => Product::WINNER,
                $score <= self::STAGNANT_UNTIL || $m['turnover'] >= 240 => Product::STAGNANT,
                default => Product::AVERAGE,
            };

            $product->update([
                'performance' => $class,
                'performance_score' => (int) round($score),
                'units_sold' => $m['units'],
                'revenue' => $m['revenue'],
                'views' => $m['views'],
                'add_to_cart_rate' => round($m['add_to_cart'], 2),
                'return_rate' => round($m['returns'], 2),
                'turnover_days' => $m['turnover'],
            ]);
        }
    }

    /**
     * Percentile rank (0-100) of each value among all values.
     *
     * @param  Collection<int, float|int>  $values
     * @return array<int, float>
     */
    private function percentiles(Collection $values, bool $lowerIsBetter = false): array
    {
        $sorted = $values->sort()->values();
        $n = max(1, $sorted->count() - 1);

        return $values->map(function ($v) use ($sorted, $n, $lowerIsBetter) {
            $rank = $sorted->search($v) / $n * 100;

            return $lowerIsBetter ? 100 - $rank : $rank;
        })->all();
    }
}
