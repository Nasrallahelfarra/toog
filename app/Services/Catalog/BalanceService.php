<?php

namespace App\Services\Catalog;

use App\Models\Evaluation;
use App\Models\Product;
use App\Models\Store;
use App\Services\Evaluation\Candidate;
use Illuminate\Support\Collection;

/**
 * Catalog balance (module 5): diversity per category (FR-5.3), proven vs
 * novel ratio against the store's target (FR-5.2), launch pace
 * recommendations (FR-5.4) and imbalance alerts (FR-5.5).
 */
class BalanceService
{
    public const REPETITIVE_BELOW = 45;

    /**
     * @return array{
     *     overall: array<string, int>,
     *     categories: list<array<string, mixed>>,
     *     alerts: list<array<string, mixed>>
     * }
     */
    public function analyze(Store $store): array
    {
        $products = Product::query()->where('store_id', $store->id)->whereNotNull('category')->get();
        $targetProven = (int) $store->setting('proven_ratio');
        $targetNovel = 100 - $targetProven;

        $recentProducts = $products->filter(fn (Product $p) => $p->launched_at?->greaterThan(now()->subDays(180)));
        $recentApproved = Evaluation::query()
            ->where('store_id', $store->id)
            ->where('final_decision', 'approved')
            ->where('decided_at', '>=', now()->subDays(180))
            ->get(['category', 'is_novel']);

        $recent = $recentProducts->map(fn (Product $p) => ['category' => $p->category, 'is_novel' => $p->is_novel])
            ->concat($recentApproved->map(fn (Evaluation $e) => ['category' => $e->category, 'is_novel' => $e->is_novel]));

        $categories = $products->groupBy('category')->map(function (Collection $items, string $category) use ($recent, $targetNovel) {
            [$diversity, $top] = $this->diversity($items);
            $launches = $recent->where('category', $category);
            $novelRatio = $launches->isEmpty() ? null : (int) round($launches->where('is_novel', true)->count() / $launches->count() * 100);
            $winners = $items->where('performance', Product::WINNER)->count();
            $stagnant = $items->where('performance', Product::STAGNANT)->count();
            $stagnantShare = $items->count() ? $stagnant / $items->count() : 0;

            $status = match (true) {
                $stagnantShare > 0.4 => 'overloaded',
                $diversity < self::REPETITIVE_BELOW => 'repetitive',
                $launches->isEmpty() => 'stale',
                default => 'healthy',
            };

            return [
                'category' => $category,
                'products' => $items->count(),
                'diversity' => $diversity,
                'top_signature' => $top,
                'novel_ratio' => $novelRatio,
                'target_novel' => $targetNovel,
                'winners' => $winners,
                'stagnant' => $stagnant,
                'recent_launches' => $launches->count(),
                'recommended_per_month' => $this->pace($items->count(), $stagnantShare, $diversity),
                'status' => $status,
            ];
        })->sortBy('diversity')->values();

        $novelOverall = $recent->isEmpty() ? 0 : (int) round($recent->where('is_novel', true)->count() / $recent->count() * 100);

        return [
            'overall' => [
                'diversity' => (int) round($categories->avg('diversity') ?? 0),
                'novel_ratio' => $novelOverall,
                'proven_ratio' => $recent->isEmpty() ? 0 : 100 - $novelOverall,
                'target_novel' => $targetNovel,
                'target_proven' => $targetProven,
                'products' => $products->count(),
                'recent_launches' => $recent->count(),
            ],
            'categories' => $categories->all(),
            'alerts' => $this->alerts($categories, $novelOverall, $targetNovel, $recent->isNotEmpty()),
        ];
    }

    /**
     * Visual and conceptual diversity, 0-100: how different the category's
     * products are from each other on average (colours, pattern, material,
     * style, season, price), penalising near-duplicate pairs. Also returns
     * the most repeated look (main colour + pattern + style).
     *
     * @param  Collection<int, Product>  $items
     * @return array{0: int, 1: array{colors: list<string>, pattern: string|null, style: string|null, share: int}|null}
     */
    private function diversity(Collection $items): array
    {
        if ($items->count() < 2) {
            return [100, null];
        }

        $candidates = $items->map(fn (Product $p) => Candidate::fromProduct($p))->values();
        $sum = 0.0;
        $near = 0;
        $pairs = 0;

        for ($i = 0; $i < $candidates->count(); $i++) {
            for ($j = $i + 1; $j < $candidates->count(); $j++) {
                $s = $candidates[$i]->similarity($candidates[$j]);
                $sum += $s;
                $near += (int) ($s >= 0.55);
                $pairs++;
            }
        }

        $score = 100 - ($sum / $pairs) * 200 - ($near / $pairs) * 150;

        $signatures = $items->map(function (Product $p) {
            $f = $p->features ?? [];

            return implode('|', [$f['colors'][0] ?? '-', $f['pattern'] ?? '-', $f['style'] ?? '-']);
        })->countBy()->sortDesc();

        $topKey = (string) $signatures->keys()->first();
        [$color, $pattern, $style] = explode('|', $topKey);

        return [
            (int) round(max(0, min(100, $score))),
            [
                'colors' => $color === '-' ? [] : [$color],
                'pattern' => $pattern === '-' ? null : $pattern,
                'style' => $style === '-' ? null : $style,
                'share' => (int) round($signatures->first() / $items->count() * 100),
            ],
        ];
    }

    /**
     * New products per month for a category: enough to stay fresh, fewer
     * when slow movers pile up, a little more when the range is repetitive.
     */
    private function pace(int $products, float $stagnantShare, int $diversity): int
    {
        $base = $products * 0.12 * (1 - $stagnantShare);

        if ($diversity < self::REPETITIVE_BELOW) {
            $base += 1;
        }

        return (int) max(1, min(8, round($base)));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    private function alerts(Collection $categories, int $novel, int $targetNovel, bool $hasLaunches): array
    {
        $alerts = [];

        foreach ($categories as $c) {
            if ($c['status'] === 'repetitive') {
                $alerts[] = ['type' => 'repetitive', 'severity' => 'warning', 'category' => $c['category'], 'params' => ['diversity' => $c['diversity'], 'share' => $c['top_signature']['share'] ?? null]];
            }

            if ($c['status'] === 'overloaded') {
                $alerts[] = ['type' => 'overloaded', 'severity' => 'danger', 'category' => $c['category'], 'params' => ['stagnant' => $c['stagnant'], 'products' => $c['products']]];
            }
        }

        if ($hasLaunches && $novel < $targetNovel - 8) {
            $alerts[] = ['type' => 'low_innovation', 'severity' => 'warning', 'category' => null, 'params' => ['novel' => $novel, 'target' => $targetNovel]];
        }

        if ($hasLaunches && $novel > $targetNovel + 15) {
            $alerts[] = ['type' => 'too_experimental', 'severity' => 'warning', 'category' => null, 'params' => ['novel' => $novel, 'target' => $targetNovel]];
        }

        return $alerts;
    }
}
