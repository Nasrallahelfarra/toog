<?php

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\SeasonalEvent;
use App\Models\Trend;
use Illuminate\Support\Collection;

/**
 * Deterministic scoring for the quality gate (FR-3.2 .. FR-3.6, FR-5.1).
 *
 * Six criteria, each 0-100 where higher is always better, combined with the
 * store's weights. The numbers come only from the store's data, so the same
 * candidate always gets the same score and every point can be explained;
 * the language model writes the explanation, it does not pick the score.
 */
class EvaluationEngine
{
    /**
     * @param  list<array{severity: string}>  $culturalFlags
     * @return array{
     *     score: int,
     *     criteria: array<string, array{score: int, weight: float}>,
     *     recommendation: string,
     *     is_novel: bool,
     *     launch: array<string, mixed>|null,
     *     evidence: array<string, mixed>
     * }
     */
    public function score(Candidate $c, StoreContext $ctx, array $culturalFlags = []): array
    {
        $evidence = [];

        $criteria = [
            'success_similarity' => $this->successSimilarity($c, $ctx, $evidence),
            'trend_fit' => $this->trendFit($c, $ctx, $evidence),
            'saudi_fit' => $this->saudiFit($c, $ctx, $culturalFlags, $evidence),
            'failure_risk' => $this->failureRisk($c, $ctx, $evidence),
            'saturation' => $this->saturation($c, $ctx, $evidence),
            'price_fit' => $this->priceFit($c, $ctx, $evidence),
        ];

        $total = 0.0;
        $out = [];
        foreach ($criteria as $key => $value) {
            $weight = $ctx->weights[$key] ?? 0;
            $total += $value * $weight / 100;
            $out[$key] = ['score' => (int) round($value), 'weight' => $weight];
        }

        $score = (int) round(max(0, min(100, $total)));

        return [
            'score' => $score,
            'criteria' => $out,
            'recommendation' => $this->decide($score, $out, $ctx),
            'is_novel' => $out['success_similarity']['score'] < 55 && $out['trend_fit']['score'] >= 55,
            'launch' => $evidence['launch'] ?? null,
            'evidence' => $evidence,
        ];
    }

    /**
     * Concrete, simulated modifications for the weakest criteria (FR-3.6).
     * Each change is re-scored so the expected gain is real, not a guess.
     *
     * @param  array{criteria: array<string, array{score: int, weight: float}>, score: int}  $result
     * @return array{modifications: list<array<string, mixed>>, expected_score: int}
     */
    public function modifications(Candidate $c, StoreContext $ctx, array $result, array $culturalFlags = []): array
    {
        $mods = [];
        $weak = collect($result['criteria'])->filter(fn ($v) => $v['score'] < 70)->sortBy('score')->keys();

        foreach ($weak as $criterion) {
            $mod = match ($criterion) {
                'price_fit' => $this->fixPrice($c, $ctx),
                'trend_fit' => $this->fixTrend($c, $ctx),
                'success_similarity', 'failure_risk' => $this->fixTowardsWinners($c, $ctx, $criterion),
                'saudi_fit' => $this->fixSaudi($c, $ctx),
                'saturation' => $this->fixSaturation($c, $ctx),
                default => null,
            };

            if (! $mod) {
                continue;
            }

            $after = $this->score($c->with($mod['change']), $ctx, $culturalFlags)['score'];
            $mod['expected_gain'] = max(0, $after - $result['score']);

            if ($mod['expected_gain'] > 0) {
                $mods[] = ['criterion' => $criterion, ...$mod];
            }
        }

        $mods = collect($mods)->unique(fn ($m) => json_encode($m['change']))->sortByDesc('expected_gain')->values()->take(5)->all();

        $combined = $c;
        foreach ($mods as $mod) {
            $combined = $combined->with($mod['change']);
        }

        return [
            'modifications' => $mods,
            'expected_score' => $mods ? $this->score($combined, $ctx, $culturalFlags)['score'] : $result['score'],
        ];
    }

    /**
     * Safer alternatives for a rejected candidate, built from the store's
     * winning attributes combined with rising trends.
     *
     * @return list<array<string, mixed>>
     */
    public function alternatives(Candidate $c, StoreContext $ctx): array
    {
        $winners = $ctx->inCategory($ctx->winners, $c->category)->pluck('candidate');
        $rising = $ctx->trends->where('status', 'rising')->sortByDesc('momentum');

        $pattern = $this->mode($winners->pluck('pattern'));
        $material = $this->mode($winners->pluck('material'));
        $winColors = $this->topValues($winners->pluck('colors')->flatten(), 2);
        $trendColor = $rising->firstWhere('type', 'color')?->value;
        $trendPattern = $rising->firstWhere('type', 'pattern')?->value;

        $options = array_filter([
            ['colors' => $winColors, 'pattern' => $pattern, 'material' => $material],
            $trendColor ? ['colors' => array_values(array_unique([$trendColor, ...array_slice($winColors, 0, 1)])), 'pattern' => $pattern] : null,
            $trendPattern ? ['pattern' => $trendPattern, 'colors' => $winColors] : null,
        ]);

        return collect($options)
            ->map(fn ($change) => array_filter($change))
            ->filter()
            ->map(fn ($change) => ['change' => $change, 'score' => $this->score($c->with($change), $ctx)['score']])
            ->unique(fn ($a) => json_encode($a['change']))
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{score: int, weight: float}>  $criteria
     */
    private function decide(int $score, array $criteria, StoreContext $ctx): string
    {
        $risk = $criteria['failure_risk']['score'];
        $saturation = $criteria['saturation']['score'];

        if ($score < $ctx->rejectThreshold || $risk < 25 || $saturation < 20) {
            return Evaluation::REJECT;
        }

        if ($score >= $ctx->acceptThreshold && $risk >= 40) {
            return Evaluation::ACCEPT;
        }

        return Evaluation::MODIFY;
    }

    /**
     * 1. Shares the attributes of the store's best sellers.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function successSimilarity(Candidate $c, StoreContext $ctx, array &$evidence): float
    {
        $winners = $ctx->inCategory($ctx->winners, $c->category);

        if ($winners->isEmpty()) {
            return 50;
        }

        $top = $winners->map(fn ($w) => ['name' => $w['name'], 'similarity' => $c->similarity($w['candidate'])])
            ->sortByDesc('similarity')->take(3)->values();

        $evidence['similar_winners'] = $top->map(fn ($w) => ['name' => $w['name'], 'similarity' => (int) round($w['similarity'] * 100)])->all();

        // A perfect copy is not the goal, so scale so ~0.75 similarity is ~95.
        return min(100, $top->avg('similarity') * 125 + 5);
    }

    /**
     * 2. Matches what is rising with competitors and on social media.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function trendFit(Candidate $c, StoreContext $ctx, array &$evidence): float
    {
        $matched = $this->matchingTrends($c, $ctx);
        $score = 30.0;

        foreach ($matched as $t) {
            $score += match ($t->status) {
                'rising' => $t->momentum * 0.45,
                'stable' => $t->momentum * 0.2,
                default => -15,
            };
        }

        $evidence['matched_trends'] = $matched->map(fn (Trend $t) => [
            'name_ar' => $t->name_ar, 'name_en' => $t->name_en, 'type' => $t->type,
            'status' => $t->status, 'momentum' => $t->momentum, 'saturation' => $t->saturation,
        ])->values()->all();

        return max(0, min(100, $score));
    }

    /**
     * 3. Fits Saudi taste, culture and the seasonal calendar.
     *
     * @param  list<array{severity: string}>  $flags
     * @param  array<string, mixed>  $evidence
     */
    private function saudiFit(Candidate $c, StoreContext $ctx, array $flags, array &$evidence): float
    {
        $score = 55.0;

        $paletteHits = array_values(array_intersect($c->colors, $ctx->saudiPalette));
        $score += min(15, count($paletteHits) * 7.5);
        $evidence['saudi_palette_matches'] = $paletteHits;

        if (in_array($c->pattern, ['arabesque', 'calligraphy', 'sadu', 'geometric', 'embroidery'], true)) {
            $score += 7;
        }

        $event = $this->alignedEvent($c, $ctx);
        if ($event) {
            $score += 18;
            $launch = $event->starts_on->subWeeks($event->prep_weeks);
            $evidence['launch'] = [
                'event_ar' => $event->name_ar,
                'event_en' => $event->name_en,
                'event_starts_on' => $event->starts_on->toDateString(),
                'date' => ($launch->isPast() ? now() : $launch)->toDateString(),
            ];
        } elseif ($c->season === 'all_year' || $c->season === null) {
            $score += 6;
        } else {
            // Seasonal product with no matching upcoming season.
            $score -= 10;
            $evidence['season_mismatch'] = true;
        }

        foreach ($flags as $flag) {
            $score -= match ($flag['severity'] ?? 'low') {
                'high' => 35,
                'medium' => 15,
                default => 5,
            };
        }

        return max(0, min(100, $score));
    }

    /**
     * 4. Low resemblance to products that failed in this store.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function failureRisk(Candidate $c, StoreContext $ctx, array &$evidence): float
    {
        $stagnant = $ctx->inCategory($ctx->stagnant, $c->category, 2);

        if ($stagnant->isEmpty()) {
            return 75;
        }

        $top = $stagnant->map(fn ($s) => ['name' => $s['name'], 'similarity' => $c->similarity($s['candidate'])])
            ->sortByDesc('similarity')->take(3)->values();

        $evidence['similar_stagnant'] = $top->filter(fn ($s) => $s['similarity'] >= 0.35)
            ->map(fn ($s) => ['name' => $s['name'], 'similarity' => (int) round($s['similarity'] * 100)])->values()->all();

        $risk = $top->max('similarity') * 0.6 + $top->avg('similarity') * 0.4;

        return max(0, min(100, 100 - $risk * 115));
    }

    /**
     * 5. The idea is not already everywhere — in the market or in the
     * store's own recent launches (repetition control, FR-5.1).
     *
     * @param  array<string, mixed>  $evidence
     */
    private function saturation(Candidate $c, StoreContext $ctx, array &$evidence): float
    {
        $market = $ctx->competitorProducts->filter(fn ($p) => ! $c->category || ! $p['category'] || $p['category'] === $c->category);
        $overlap = $market->filter(fn ($p) => $c->similarity($p['candidate']) >= 0.5)->count();
        $marketLevel = $market->isEmpty() ? 30 : min(100, $overlap / max(1, $market->count()) * 250);

        $matched = $this->matchingTrends($c, $ctx);
        $trendLevel = $matched->isEmpty() ? 35 : $matched->avg('saturation');

        $repeats = $ctx->recentLaunches->filter(fn (Candidate $r) => $c->similarity($r) >= 0.7)->count();
        $selfLevel = min(100, $repeats * 30);

        $evidence['competitor_overlap'] = ['similar' => $overlap, 'total' => $market->count()];
        $evidence['self_repetition'] = $repeats;

        $level = 0.45 * $marketLevel + 0.35 * $trendLevel + 0.20 * $selfLevel;

        return max(0, min(100, 100 - $level));
    }

    /**
     * 6. Price sits inside the band that sells in this category.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function priceFit(Candidate $c, StoreContext $ctx, array &$evidence): float
    {
        $source = $ctx->inCategory($ctx->winners, $c->category, 2);
        if ($source->count() < 2) {
            $source = $ctx->inCategory($ctx->allProducts, $c->category, 2);
        }

        $prices = $source->pluck('candidate.price')->filter()->sort()->values();

        if ($prices->isEmpty() || ! $c->price) {
            return 60;
        }

        $low = $this->percentile($prices, 25);
        $high = $this->percentile($prices, 75);
        $median = $this->percentile($prices, 50);
        $evidence['price_band'] = ['low' => round($low), 'median' => round($median), 'high' => round($high)];

        if ($c->price >= $low && $c->price <= $high) {
            $spread = max(1, ($high - $low) / 2);

            return 100 - min(12, abs($c->price - $median) / $spread * 12);
        }

        $distance = $c->price < $low ? $low - $c->price : $c->price - $high;

        return max(5, 88 - $distance / max(1, $median) * 140);
    }

    /**
     * @return Collection<int, Trend>
     */
    private function matchingTrends(Candidate $c, StoreContext $ctx): Collection
    {
        return $ctx->trends->filter(function (Trend $t) use ($c) {
            if ($t->categories && $c->category && ! in_array($c->category, $t->categories, true)) {
                return false;
            }

            return match ($t->type) {
                'color' => in_array($t->value, $c->colors, true),
                'pattern' => $t->value === $c->pattern,
                'style' => $t->value === $c->style,
                'material' => $t->value === $c->material,
                'theme' => $t->value === $c->season,
                default => false,
            };
        })->values();
    }

    private function alignedEvent(Candidate $c, StoreContext $ctx): ?SeasonalEvent
    {
        if (! $c->season || $c->season === 'all_year') {
            return null;
        }

        // The season must be close enough to plan for: inside its prep
        // window plus a ten-week production lead, or running now.
        return $ctx->upcomingEvents->first(
            fn (SeasonalEvent $e) => $e->season_key === $c->season
                && $e->starts_on->subWeeks($e->prep_weeks + 10)->isPast(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixPrice(Candidate $c, StoreContext $ctx): ?array
    {
        $evidence = [];
        $this->priceFit($c, $ctx, $evidence);
        $band = $evidence['price_band'] ?? null;

        if (! $band || ! $c->price) {
            return null;
        }

        $target = (float) $band['median'];

        return [
            'type' => 'price',
            'change' => ['price' => $target],
            'params' => ['from' => round($c->price), 'to' => round($target), 'low' => $band['low'], 'high' => $band['high']],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixTrend(Candidate $c, StoreContext $ctx): ?array
    {
        $rising = $ctx->trends->where('status', 'rising')->sortByDesc(fn (Trend $t) => $t->momentum - $t->saturation / 2);

        $color = $rising->first(fn (Trend $t) => $t->type === 'color' && ! in_array($t->value, $c->colors, true));
        if ($color) {
            $colors = $c->colors;
            if (count($colors) >= 2) {
                array_pop($colors);
            }

            return [
                'type' => 'trend_color',
                'change' => ['colors' => array_values(array_unique([...$colors, $color->value]))],
                'params' => ['value' => $color->value, 'name_ar' => $color->name_ar, 'name_en' => $color->name_en, 'momentum' => $color->momentum],
            ];
        }

        $pattern = $rising->first(fn (Trend $t) => $t->type === 'pattern' && $t->value !== $c->pattern);

        return $pattern ? [
            'type' => 'trend_pattern',
            'change' => ['pattern' => $pattern->value],
            'params' => ['value' => $pattern->value, 'name_ar' => $pattern->name_ar, 'name_en' => $pattern->name_en, 'momentum' => $pattern->momentum],
        ] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixTowardsWinners(Candidate $c, StoreContext $ctx, string $criterion): ?array
    {
        $winners = $ctx->inCategory($ctx->winners, $c->category)->pluck('candidate');

        if ($winners->isEmpty()) {
            return null;
        }

        // Try each attribute and keep the one change that helps most.
        $options = collect([
            'pattern' => $this->mode($winners->pluck('pattern')),
            'material' => $this->mode($winners->pluck('material')),
            'style' => $this->mode($winners->pluck('style')),
        ])->filter(fn ($value, $field) => $value && $value !== $c->{$field});

        $best = null;
        $bestScore = -1;
        foreach ($options as $field => $value) {
            $s = $this->score($c->with([$field => $value]), $ctx)['criteria'][$criterion]['score'];
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = [$field, $value];
            }
        }

        if (! $best) {
            return null;
        }

        return [
            'type' => $criterion === 'failure_risk' ? 'avoid_failure_'.$best[0] : 'winner_'.$best[0],
            'change' => [$best[0] => $best[1]],
            'params' => ['field' => $best[0], 'value' => $best[1], 'from' => $c->{$best[0]}],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixSaudi(Candidate $c, StoreContext $ctx): ?array
    {
        $next = $ctx->upcomingEvents->first(fn (SeasonalEvent $e) => $e->season_key && $e->starts_on->isFuture());

        if ($next && $c->season !== $next->season_key) {
            return [
                'type' => 'season',
                'change' => ['season' => $next->season_key],
                'params' => ['value' => $next->season_key, 'name_ar' => $next->name_ar, 'name_en' => $next->name_en, 'date' => $next->starts_on->toDateString()],
            ];
        }

        $missing = array_values(array_diff($ctx->saudiPalette, $c->colors));

        return $missing ? [
            'type' => 'saudi_color',
            'change' => ['colors' => array_values(array_unique([...array_slice($c->colors, 0, 2), $missing[0]]))],
            'params' => ['value' => $missing[0]],
        ] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixSaturation(Candidate $c, StoreContext $ctx): ?array
    {
        $gap = $ctx->trends
            ->filter(fn (Trend $t) => $t->type === 'pattern' && $t->status === 'rising' && $t->saturation < 50 && $t->value !== $c->pattern)
            ->sortByDesc('momentum')
            ->first();

        return $gap ? [
            'type' => 'gap_pattern',
            'change' => ['pattern' => $gap->value],
            'params' => ['value' => $gap->value, 'name_ar' => $gap->name_ar, 'name_en' => $gap->name_en, 'saturation' => $gap->saturation],
        ] : null;
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function mode(Collection $values): ?string
    {
        return $values->filter()->countBy()->sortDesc()->keys()->first();
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<string>
     */
    private function topValues(Collection $values, int $n): array
    {
        return $values->filter()->countBy()->sortDesc()->keys()->take($n)->values()->all();
    }

    /**
     * @param  Collection<int, float>  $sorted
     */
    private function percentile(Collection $sorted, int $p): float
    {
        $index = ($sorted->count() - 1) * $p / 100;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return (float) $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower);
    }
}
