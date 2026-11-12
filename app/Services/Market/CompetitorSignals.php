<?php

namespace App\Services\Market;

use App\Models\CompetitorProduct;
use App\Models\SocialPost;
use App\Models\Store;
use App\Support\FashionVocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Public selling signals read from competitors, shared by the AI designer
 * and the competitor radar.
 *
 * Store products: a best-seller label (the competitor's own claim), a
 * published sales count, homepage placement (the competitor pushing it,
 * not buyers pulling it), a price cut and a genuinely new arrival.
 * Social posts: engagement as a multiple of the same account's usual —
 * engagement is interest, not sales.
 */
class CompetitorSignals
{
    /** Ordering weight of each store signal; never shown as sales. */
    public const POINTS = ['best_seller_label' => 3, 'published_sales' => 3, 'homepage_featured' => 2, 'price_drop' => 1, 'new_arrival' => 1];

    /** Posts an account needs before its usual engagement is trusted. */
    public const BASELINE = 5;

    /** Below BASELINE but at least this many: shown as a small sample. */
    public const SMALL_BASELINE = 3;

    /**
     * Competitor store products seen lately that carry at least one signal,
     * best first. An "echo" is the same look (garment + colour + pattern)
     * flagged at two or more competitors.
     *
     * @param  Collection<int|string, string>  $active  competitor names by id
     * @return Collection<int, array{product: CompetitorProduct, signals: list<string>, echo: bool, echo_count: int, rank: int, drop_pct: int|null, look: string|null, garment: string|null}>
     */
    public function storeHits(Store $store, Collection $active): Collection
    {
        if ($active->isEmpty()) {
            return collect();
        }

        $products = CompetitorProduct::query()->where('store_id', $store->id)
            ->whereIn('competitor_id', $active->keys())
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '>=', now()->subDays(45)))
            ->with('competitor:id,platform')
            ->get();
        // A product found in a competitor's very first crawl is not "new".
        $firstSeen = $products->groupBy('competitor_id')->map(fn (Collection $items) => $items->min('first_seen_at'));

        $flagged = $products->map(fn (CompetitorProduct $cp) => [$cp, $this->signals($cp, $firstSeen[$cp->competitor_id] ?? null)])
            ->filter(fn (array $r) => $r[1] !== []);
        $echo = $flagged->groupBy(fn (array $r) => self::look($r[0]) ?? 'none:'.$r[0]->id)
            ->map(fn (Collection $g) => $g->map(fn (array $r) => self::brand((string) ($active[$r[0]->competitor_id] ?? $r[0]->competitor_id)))->unique()->count());

        return $flagged
            ->map(function (array $r) use ($echo) {
                [$cp, $signals] = $r;
                $look = self::look($cp);
                $brands = $look ? (int) ($echo[$look] ?? 1) : 1;

                return [
                    'product' => $cp,
                    'signals' => $signals,
                    'echo' => $brands >= 2,
                    'echo_count' => $brands,
                    'rank' => array_sum(array_map(fn (string $s) => self::POINTS[$s], $signals)) + ($brands >= 2 ? 2 : 0),
                    'drop_pct' => in_array('price_drop', $signals, true)
                        ? (int) round(((float) $cp->previous_price - (float) $cp->price) / (float) $cp->previous_price * 100) : null,
                    'look' => $look,
                    'garment' => FashionVocabulary::garmentFor($cp->category, $cp->name),
                ];
            })
            ->sortByDesc('rank')
            ->values();
    }

    /**
     * A product "selling" by its own store's signals: a best-seller label,
     * a published sales count, or homepage placement plus another signal.
     * A price cut or a new arrival alone is activity, not demand.
     *
     * @param  list<string>  $signals
     */
    public static function isDemand(array $signals): bool
    {
        return in_array('best_seller_label', $signals, true)
            || in_array('published_sales', $signals, true)
            || (in_array('homepage_featured', $signals, true) && count($signals) >= 2);
    }

    /**
     * @return list<string>
     */
    public function signals(CompetitorProduct $cp, mixed $competitorFirstSeen): array
    {
        $evidence = $cp->evidence ?? [];
        $out = [];
        if ($cp->is_best_seller) {
            $out[] = 'best_seller_label';
        }
        if (! empty($evidence['published_sales'])) {
            $out[] = 'published_sales';
        }
        if (! empty($evidence['promotion'])) {
            $out[] = 'homepage_featured';
        }
        if ($cp->previous_price && $cp->price && (float) $cp->previous_price > 0
            && ((float) $cp->previous_price - (float) $cp->price) / (float) $cp->previous_price >= 0.10) {
            $out[] = 'price_drop';
        }
        if ($cp->first_seen_at && $cp->first_seen_at->gte(now()->subDays(30)) && $competitorFirstSeen
            && $cp->first_seen_at->gt(CarbonImmutable::parse($competitorFirstSeen)->addDay())) {
            $out[] = 'new_arrival';
        }

        return $out;
    }

    /**
     * Each post's engagement as a multiple of its own account's usual (the
     * median of the account's posts). An account compares engagement rates
     * only when every post knows its follower count; otherwise raw
     * interactions; hidden likes compare comments. Rates against views are
     * never used, since they penalise reach.
     *
     * @param  Collection<int, SocialPost>  $posts  the account baseline pool
     * @return array<int, array{lift: float|null, baseline: int, metric: string}>
     */
    public function lifts(Collection $posts): array
    {
        $out = [];

        foreach ($posts->groupBy('competitor_id') as $group) {
            $hidden = $group->contains(fn (SocialPost $p) => ! empty($p->evidence['likes_hidden']));
            $rates = $group->every(fn (SocialPost $p) => ($p->evidence['followers'] ?? 0) > 0 && $p->measuredEngagement() !== null);
            $metric = $hidden ? 'comments' : ($rates ? 'rate' : 'interactions');
            $value = fn (SocialPost $p): float => match ($metric) {
                'comments' => (float) $p->comments,
                'rate' => (float) $p->measuredEngagement(),
                default => (float) ($p->likes + $p->comments + $p->shares),
            };
            $median = (float) $group->map($value)->median();
            $n = $group->count();

            foreach ($group as $post) {
                $out[$post->id] = [
                    'lift' => $n >= self::SMALL_BASELINE && $median > 0 ? round($value($post) / $median, 2) : null,
                    'baseline' => $n,
                    'metric' => $metric,
                ];
            }
        }

        return $out;
    }

    /**
     * A product's look: garment + primary colour + pattern. Garment and
     * colour are required, otherwise products cannot be compared.
     */
    public static function look(CompetitorProduct $cp): ?string
    {
        $garment = FashionVocabulary::garmentFor($cp->category, $cp->name);
        $features = self::features($cp);
        $color = $features['colors'][0] ?? null;

        return $garment && is_string($color) ? implode('|', [$garment, $color, $features['pattern'] ?? '']) : null;
    }

    /**
     * A product's attributes; when the scan extracted none, the colour and
     * pattern words in its own name (stores name products by them).
     *
     * @return array<string, mixed>
     */
    public static function features(CompetitorProduct $cp): array
    {
        $features = $cp->features ?? [];
        if (! empty($features['colors'])) {
            return $features;
        }

        return [...app(FeatureExtractor::class)->fromText(trim($cp->name.' '.$cp->category)), ...array_filter($features)];
    }

    /**
     * Competitors are saved per platform; the same brand's store and its
     * Instagram account share a name, so the normalised name stands for
     * the brand.
     */
    public static function brand(string $name): string
    {
        $name = mb_strtolower(trim($name));
        // "Blue Age — بلو إيج" and "blue age" are the same brand.
        $name = preg_split('/\s+[—–|\-]\s+/u', $name)[0] ?? $name;

        return trim((string) preg_replace('/[\s_.]+/u', ' ', $name));
    }
}
