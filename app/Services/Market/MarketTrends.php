<?php

namespace App\Services\Market;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SocialPost;
use App\Models\Store;
use App\Support\FashionVocabulary;
use App\Support\Vocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "توجهات السوق", read live from two sources set side by side:
 *
 * - the market: what the active competitors carry now (clothing only in a
 *   fashion store), weighted by each store's own public demand signals,
 *   and what their posts show when engagement beats the account's usual;
 * - the store: units sold per attribute in the last three complete months
 *   against the three before, as a share of all units, so a store-wide
 *   season such as Ramadan does not make everything "rise".
 *
 * Seeded sample rows are ignored once the store has real scans. Units and
 * orders only, never revenue.
 */
class MarketTrends
{
    public const TYPES = ['garment', 'color', 'pattern', 'material', 'style'];

    /** Baseline values that are never an opportunity on their own. */
    private const NEUTRAL = ['solid', 'all_year'];

    private const GROUPS = ['color' => 'colors', 'pattern' => 'patterns', 'material' => 'materials', 'style' => 'styles'];

    /** Units over both windows before a value's own trend is trusted. */
    private const MIN_UNITS = 10;

    private string $locale;

    /** The store's units in L3, all products in scope. */
    private int $unitsNow = 0;

    public function __construct(
        private readonly CompetitorSignals $signals,
        private readonly PostTagger $tagger,
        private readonly FeatureExtractor $extractor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Store $store, ?string $locale = null): array
    {
        $this->locale = $locale ?? app()->getLocale();
        $this->unitsNow = 0;

        $own = Product::query()->where('store_id', $store->id)->get();
        $fashion = self::isFashion($own);
        if ($fashion) {
            $own = $own->filter(fn (Product $p) => FashionVocabulary::garmentFor($p->category, $p->name) !== null)->values();
        }

        $active = Competitor::query()->where('store_id', $store->id)->where('is_active', true)->pluck('name', 'id');
        $live = self::hasLiveData($store, $active->keys()->all());
        [$products, $excluded] = $this->marketProducts($store, $active, $live, $fashion);
        $posts = $this->marketPosts($store, $active, $live);

        $market = $this->marketStats($products, $posts);
        $window = self::window($store);
        $mine = $this->mineStats($store, $own, $window);

        $items = collect(array_unique([...array_keys($market), ...array_keys($mine)]))
            ->map(fn (string $key) => $this->item($key, $market[$key] ?? null, $mine[$key] ?? null))
            ->filter()
            ->values();

        $opportunities = self::diverse($items->filter(fn (array $i) => $i['verdict'] === 'opportunity')->sortByDesc('heat'), 6);
        $focus = self::diverse($items->filter(fn (array $i) => $i['verdict'] === 'focus')->sortByDesc(fn (array $i) => $i['heat'] + $i['mine']['change_pct'] / 4), 4);
        $rising = self::diverse($items->filter(fn (array $i) => $i['verdict'] === 'mine_rising')
            ->sortByDesc(fn (array $i) => $i['mine']['change_pct'] * log(1 + $i['mine']['units'])), 6);
        $cooling = self::diverse($items->filter(fn (array $i) => $i['verdict'] === 'cooling')->sortBy(fn (array $i) => $i['mine']['change_pct']), 4);
        // The market at a glance: what competitors push most, and whether you carry it.
        $pulse = self::diverse($items->filter(fn (array $i) => $i['strong'])->sortByDesc('heat'), 8);

        $lifted = $posts->filter(fn (array $p) => $p['lift'] !== null && $p['baseline'] >= CompetitorSignals::SMALL_BASELINE);
        $top = $lifted->sortByDesc('lift')->first();

        return [
            'basis' => $products->isEmpty() && $posts->isEmpty() ? 'none' : ($live ? 'live' : 'sample'),
            'fashion' => $fashion,
            'window' => ['from' => $window['l3'][0], 'to' => $window['l3'][1], 'before_from' => $window['p3'][0], 'before_to' => $window['p3'][1]],
            'coverage' => [
                'brands' => $products->pluck('brand')->concat($posts->pluck('brand'))->unique()->count(),
                'products' => $products->count(),
                'demand' => $products->where('demand', true)->count(),
                'excluded' => $excluded,
                'posts' => $posts->count(),
                'hot_posts' => $lifted->filter(fn (array $p) => $p['lift'] >= 2)->count(),
                'scanned_at' => collect([$products->max('seen_at'), $posts->max('seen_at')])->filter()->max(),
                'own_products' => $own->count(),
                'units' => $this->unitsNow,
            ],
            'opportunities' => $opportunities,
            'focus' => $focus,
            'mine_rising' => $rising,
            'cooling' => $cooling,
            'pulse' => $pulse,
            'top_post' => $top ? [
                'id' => $top['id'],
                'competitor' => $top['competitor'],
                'platform' => $top['platform'],
                'lift' => $top['lift'],
                'image_url' => $top['image_url'],
                'url' => $top['url'],
                'garment' => $top['attrs']['garment'][0] ?? null,
            ] : null,
        ];
    }

    /**
     * A clothing store: at least three of its own products are garments the
     * designer knows. Other stores (gifts, perfume) keep every product.
     *
     * @param  Collection<int, Product>  $products
     */
    public static function isFashion(Collection $products): bool
    {
        return $products->filter(fn (Product $p) => FashionVocabulary::garmentFor($p->category, $p->name) !== null)->count() >= 3;
    }

    /**
     * Real scans set evidence on every row; seeded samples never do.
     *
     * @param  list<int|string>  $competitorIds
     */
    public static function hasLiveData(Store $store, array $competitorIds): bool
    {
        return $competitorIds !== [] && (
            CompetitorProduct::query()->where('store_id', $store->id)->whereIn('competitor_id', $competitorIds)->whereNotNull('evidence')->exists()
            || SocialPost::query()->where('store_id', $store->id)->whereIn('competitor_id', $competitorIds)->whereNotNull('evidence')->exists()
        );
    }

    /**
     * L3 is the last three complete months with sales, P3 the three before.
     * The current, partial month is left out.
     *
     * @return array{l3: array{0: string, 1: string}, p3: array{0: string, 1: string}}
     */
    public static function window(Store $store): array
    {
        $latest = ProductSale::query()->where('store_id', $store->id)
            ->where('period', '<', now()->startOfMonth()->toDateString())->max('period');
        $end = CarbonImmutable::parse($latest ?? now()->startOfMonth()->subMonth())->startOfMonth();
        if ($end->lt(now()->startOfMonth()->subMonths(3))) {
            $end = CarbonImmutable::now()->startOfMonth()->subMonth();
        }

        return [
            'l3' => [$end->subMonths(2)->toDateString(), $end->toDateString()],
            'p3' => [$end->subMonths(5)->toDateString(), $end->subMonths(3)->toDateString()],
        ];
    }

    /**
     * Competitor products seen in the last 45 days, with their attributes
     * and a demand weight from the store's own public signals.
     *
     * @param  Collection<int|string, string>  $active
     * @return array{0: Collection<int, array<string, mixed>>, 1: int}
     */
    private function marketProducts(Store $store, Collection $active, bool $live, bool $fashion): array
    {
        if ($active->isEmpty()) {
            return [collect(), 0];
        }

        $rows = CompetitorProduct::query()->where('store_id', $store->id)
            ->whereIn('competitor_id', $active->keys())
            ->when($live, fn ($q) => $q->whereNotNull('evidence'))
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '>=', now()->subDays(45)))
            ->get();
        $firstSeen = $rows->groupBy('competitor_id')->map(fn (Collection $items) => $items->min('first_seen_at'));

        $products = $rows->map(function (CompetitorProduct $cp) use ($active, $firstSeen) {
            $garment = FashionVocabulary::garmentFor($cp->category, $cp->name);
            $signals = $this->signals->signals($cp, $firstSeen[$cp->competitor_id] ?? null);
            $sold = self::publishedSales($cp);

            return [
                'id' => $cp->id,
                'name' => $cp->name,
                'image_url' => $cp->image_url,
                'url' => $cp->url,
                'price' => $cp->price !== null ? (float) $cp->price : null,
                'currency' => $cp->evidence['currency'] ?? null,
                'competitor' => (string) ($active[$cp->competitor_id] ?? ''),
                'brand' => CompetitorSignals::brand((string) ($active[$cp->competitor_id] ?? $cp->competitor_id)),
                'garment' => $garment,
                'attrs' => $this->attributes(CompetitorSignals::features($cp), $garment),
                'published_sales' => $sold,
                'best_seller' => in_array('best_seller_label', $signals, true),
                'demand' => CompetitorSignals::isDemand($signals),
                'new' => in_array('new_arrival', $signals, true),
                'weight' => 1 + ($cp->is_best_seller ? 1 : 0) + ($sold ? min(2, log10(1 + $sold)) : 0) + (! empty($cp->evidence['promotion']) ? 0.5 : 0),
                'seen_at' => ($cp->last_seen_at ?? $cp->created_at)?->toIso8601String(),
            ];
        });

        if (! $fashion) {
            return [$products->values(), 0];
        }

        $clothing = $products->filter(fn (array $p) => $p['garment'] !== null)->values();

        return [$clothing, $products->count() - $clothing->count()];
    }

    /**
     * Competitor posts of the last 90 days with their engagement against
     * the same account's usual and what they show.
     *
     * @param  Collection<int|string, string>  $active
     * @return Collection<int, array<string, mixed>>
     */
    private function marketPosts(Store $store, Collection $active, bool $live): Collection
    {
        if ($active->isEmpty()) {
            return collect();
        }

        $posts = SocialPost::query()->where('store_id', $store->id)
            ->whereIn('competitor_id', $active->keys())
            ->when($live, fn ($q) => $q->whereNotNull('evidence'))
            ->where(fn ($q) => $q->whereNull('posted_at')->orWhere('posted_at', '>=', now()->subDays(90)))
            ->get();
        $lifts = $this->signals->lifts($posts);

        return $posts->map(function (SocialPost $p) use ($active, $lifts) {
            $tags = $this->tagger->tag($p);
            $read = collect((array) ($p->ai_reading ?? []))->first(fn ($r) => is_array($r)) ?? [];
            $text = $this->extractor->fromText((string) $p->caption);
            $features = [
                'colors' => $tags['colors'] ?: ((array) ($read['colors'] ?? []) ?: ($text['colors'] ?? [])),
                'pattern' => $tags['pattern'] ?? $read['pattern'] ?? $text['pattern'] ?? null,
                'material' => $p->features['material'] ?? $text['material'] ?? null,
                'style' => $tags['style'] ?? $text['style'] ?? null,
            ];
            $garment = is_string($read['garment'] ?? null) && isset(FashionVocabulary::garments()[$read['garment']]) ? $read['garment'] : $tags['garment'];

            return [
                'id' => $p->id,
                'competitor' => (string) ($active[$p->competitor_id] ?? ''),
                'brand' => CompetitorSignals::brand((string) ($active[$p->competitor_id] ?? $p->competitor_id)),
                'platform' => $p->platform,
                'url' => $p->url,
                'image_url' => CompetitorRadar::image($p),
                'attrs' => $this->attributes($features, $garment),
                'lift' => $lifts[$p->id]['lift'] ?? null,
                'baseline' => $lifts[$p->id]['baseline'] ?? 0,
                'seen_at' => ($p->posted_at ?? $p->created_at)?->toIso8601String(),
            ];
        });
    }

    /**
     * Per attribute value: its demand-weighted share of the competitors'
     * catalog, the brands and best sellers carrying it, and how posts
     * showing it engage against their account's usual.
     *
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return array<string, array<string, mixed>>
     */
    private function marketStats(Collection $products, Collection $posts): array
    {
        $out = [];

        foreach (self::TYPES as $type) {
            $withType = $products->filter(fn (array $p) => $p['attrs'][$type] !== []);
            $total = $withType->sum('weight');
            $values = $withType->flatMap(fn (array $p) => $p['attrs'][$type])
                ->concat($posts->flatMap(fn (array $p) => $p['attrs'][$type]))->unique();

            foreach ($values as $value) {
                $carriers = $withType->filter(fn (array $p) => in_array($value, $p['attrs'][$type], true));
                $showing = $posts->filter(fn (array $p) => in_array($value, $p['attrs'][$type], true));
                $measured = $showing->filter(fn (array $p) => $p['lift'] !== null && $p['baseline'] >= CompetitorSignals::SMALL_BASELINE);

                $out["{$type}:{$value}"] = [
                    // A share needs a catalog to be a share of.
                    'pct' => $withType->count() >= 10 && $total > 0 ? (int) round($carriers->sum('weight') / $total * 100) : null,
                    'items' => $carriers->count(),
                    'brands' => $carriers->pluck('brand')->concat($showing->pluck('brand'))->unique()->count(),
                    'demand' => $carriers->where('demand', true)->count(),
                    'new' => $carriers->where('new', true)->count(),
                    'posts' => $measured->count(),
                    'post_lift' => $measured->count() >= 2 ? round((float) $measured->pluck('lift')->median(), 1) : null,
                    'examples' => $carriers->sortByDesc(fn (array $p) => [$p['weight'], $p['published_sales'] ?? 0])->take(3)
                        ->map(fn (array $p) => array_intersect_key($p, array_flip(['id', 'name', 'image_url', 'url', 'competitor', 'published_sales', 'best_seller', 'price', 'currency', 'garment'])))
                        ->values()->all(),
                ];
            }
        }

        return $out;
    }

    /**
     * Per attribute value: the store's own units in L3 and P3, the change
     * in its share of all units, and how much of the live catalog has it.
     *
     * @param  Collection<int, Product>  $own
     * @param  array{l3: array{0: string, 1: string}, p3: array{0: string, 1: string}}  $window
     * @return array<string, array<string, mixed>>
     */
    private function mineStats(Store $store, Collection $own, array $window): array
    {
        if ($own->isEmpty()) {
            return [];
        }

        $sales = ProductSale::query()->where('store_id', $store->id)
            ->whereIn('product_id', $own->pluck('id'))
            ->where('period', '>=', $window['p3'][0])
            // Periods are stored with a time, so compare with the next month.
            ->where('period', '<', CarbonImmutable::parse($window['l3'][1])->addMonth()->toDateString())
            ->get(['product_id', 'period', 'units']);
        $sum = fn (array $range) => $sales->filter(fn (ProductSale $s) => $s->period->toDateString() >= $range[0] && $s->period->toDateString() <= $range[1])
            ->groupBy('product_id')->map(fn (Collection $rows) => (int) $rows->sum('units'));
        $now = $sum($window['l3']);
        $before = $sum($window['p3']);
        $this->unitsNow = (int) $now->sum();
        $allNow = max(1, $this->unitsNow);
        $allBefore = max(1, $before->sum());

        $rows = $own->map(fn (Product $p) => [
            'product' => $p,
            'attrs' => $this->attributes($p->features ?? [], FashionVocabulary::garmentFor($p->category, $p->name)),
            'now' => (int) ($now[$p->id] ?? 0),
            'before' => (int) ($before[$p->id] ?? 0),
            'live' => $p->stock > 0 || ($p->launched_at && $p->launched_at->gte(now()->subYear())),
        ]);
        $out = [];

        foreach (self::TYPES as $type) {
            $liveWithType = $rows->filter(fn (array $r) => $r['live'] && $r['attrs'][$type] !== []);

            foreach ($rows->flatMap(fn (array $r) => $r['attrs'][$type])->unique() as $value) {
                $carriers = $rows->filter(fn (array $r) => in_array($value, $r['attrs'][$type], true));
                $units = (int) $carriers->sum('now');
                $unitsBefore = (int) $carriers->sum('before');
                $shareNow = $units / $allNow;
                $shareBefore = $unitsBefore / $allBefore;
                $trusted = $units + $unitsBefore >= self::MIN_UNITS && $carriers->count() >= 2 && $shareBefore > 0;

                $out["{$type}:{$value}"] = [
                    'units' => $units,
                    'units_before' => $unitsBefore,
                    'unit_pct' => (int) round($shareNow * 100),
                    'change_pct' => $trusted ? (int) round(($shareNow - $shareBefore) / $shareBefore * 100) : null,
                    'catalog_pct' => $liveWithType->isEmpty() ? 0 : (int) round($liveWithType->filter(fn (array $r) => in_array($value, $r['attrs'][$type], true))->count() / $liveWithType->count() * 100),
                    'products' => $carriers->count(),
                    'in_stock' => $carriers->filter(fn (array $r) => $r['product']->stock > 0)->count(),
                    'stock' => (int) $carriers->sum(fn (array $r) => max(0, (int) $r['product']->stock)),
                    'examples' => $carriers->sortByDesc('now')->take(3)->map(fn (array $r) => [
                        'id' => $r['product']->id,
                        'name' => $r['product']->name,
                        'image_url' => $r['product']->image_url,
                        'units' => $r['now'],
                        'garment' => $r['attrs']['garment'][0] ?? null,
                    ])->values()->all(),
                ];
            }
        }

        return $out;
    }

    /**
     * One attribute value with its verdict (null when it is none of the
     * four), or null for a value outside the vocabulary.
     *
     * @param  array<string, mixed>|null  $m
     * @param  array<string, mixed>|null  $mine
     * @return array<string, mixed>|null
     */
    private function item(string $key, ?array $m, ?array $mine): ?array
    {
        [$type, $value] = explode(':', $key, 2);
        $label = $this->label($type, $value);
        if ($label === null) {
            return null;
        }

        $neutral = in_array($value, self::NEUTRAL, true);
        // Strong in the market: a real share of the competitors' catalog
        // backed by more than one brand or by their own demand signals, or
        // posts showing it that beat their accounts' usual engagement.
        $strong = $m !== null && (
            ($m['items'] >= 5 && $m['pct'] !== null && (($m['pct'] >= 12 && ($m['brands'] >= 2 || $m['demand'] >= 3)) || ($m['pct'] >= 25 && $m['items'] >= 8)))
            || ($m['posts'] >= 2 && $m['post_lift'] !== null && $m['post_lift'] >= 1.5)
        );
        $change = $mine['change_pct'] ?? null;
        $absent = $mine === null || ($mine['catalog_pct'] < 5 && $mine['unit_pct'] < 5);

        $verdict = match (true) {
            $strong && ! $neutral && $absent => 'opportunity',
            $strong && ! $neutral && $change !== null && $change >= 15 && $mine['units'] >= 8 => 'focus',
            $change !== null && $change >= 25 && $mine['units'] >= 8 => 'mine_rising',
            $change !== null && $change <= -25 && $mine['units_before'] >= 8 && $mine['in_stock'] > 0 => 'cooling',
            default => null,
        };

        $heat = $m === null ? 0.0 : ($m['pct'] ?? 0) * ($m['brands'] >= 2 ? 1.2 : 1.0)
            + max(0, ($m['post_lift'] ?? 1) - 1) * 12 * min(1, $m['posts'] / 3)
            + $m['demand'] * 0.5 + $m['new'] * 2;

        return [
            'key' => $key,
            'type' => $type,
            'value' => $value,
            'label' => $label,
            'hex' => $type === 'color' ? (Vocabulary::colors()[$value]['hex'] ?? null) : null,
            'verdict' => $verdict,
            'strong' => $strong,
            'heat' => round($heat, 1),
            'market' => $m !== null && ($m['items'] > 0 || $m['posts'] > 0) ? [...$m, 'crowded' => ($m['pct'] ?? 0) >= 40 && $m['brands'] >= 3] : null,
            'mine' => $mine,
            'design_href' => '/store/studio?mode=designer&inspire='.rawurlencode("attr:{$type}:{$value}"),
            'evaluate_href' => match ($type) {
                'color' => '/store/evaluations/create?colors[]='.rawurlencode($value),
                'garment' => null,
                default => "/store/evaluations/create?{$type}=".rawurlencode($value),
            },
        ];
    }

    /**
     * The best items, at most three of one attribute type.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function diverse(Collection $items, int $limit): array
    {
        $out = [];
        $perType = [];

        foreach ($items as $item) {
            if (($perType[$item['type']] ?? 0) >= 3) {
                continue;
            }
            $perType[$item['type']] = ($perType[$item['type']] ?? 0) + 1;
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $features
     * @return array<string, list<string>>
     */
    private function attributes(array $features, ?string $garment): array
    {
        $values = fn (mixed $v): array => array_values(array_unique(array_filter(is_array($v) ? $v : [$v], fn ($x) => is_string($x) && $x !== '')));

        return [
            'garment' => $garment ? [$garment] : [],
            'color' => array_slice($values($features['colors'] ?? []), 0, 3),
            'pattern' => $values($features['pattern'] ?? null),
            'material' => $values($features['material'] ?? null),
            'style' => $values($features['style'] ?? null),
        ];
    }

    /**
     * A known value's label, or null for one outside the vocabulary.
     */
    private function label(string $type, string $value): ?string
    {
        if ($type === 'garment') {
            return isset(FashionVocabulary::garments()[$value]) ? FashionVocabulary::label('garments', $value, $this->locale) : null;
        }

        $group = self::GROUPS[$type] ?? null;
        $list = match ($group) {
            'colors' => Vocabulary::colors(),
            'patterns' => Vocabulary::patterns(),
            'materials' => Vocabulary::materials(),
            'styles' => Vocabulary::styles(),
            default => [],
        };

        return isset($list[$value]) ? Vocabulary::label($group, $value, $this->locale) : null;
    }

    /**
     * The purchase count a competitor store shows, when it shows one.
     */
    public static function publishedSales(CompetitorProduct $cp): ?int
    {
        $sold = $cp->evidence['published_sales'] ?? null;
        if (is_array($sold)) {
            $sold = $sold['count'] ?? null;
        }
        if (is_string($sold) && preg_match('/\d+/', strtr($sold, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), $m)) {
            $sold = (int) $m[0];
        }

        return is_numeric($sold) && (int) $sold > 0 ? (int) $sold : null;
    }
}
