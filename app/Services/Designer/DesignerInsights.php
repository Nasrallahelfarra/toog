<?php

namespace App\Services\Designer;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SeasonalEvent;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\Trend;
use App\Services\Evaluation\Candidate;
use App\Services\Market\CompetitorSignals;
use App\Support\FashionVocabulary;
use App\Support\Vocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * What the AI designer knows about the store before it draws anything:
 * the store's best sellers and their winning attributes, products rising
 * or fading, what is working for competitors, the gaps between the two,
 * saturation, the seasons ahead, and the alerts that follow from them.
 *
 * Units and orders only, never money earned: revenue may be hidden from
 * the store, and the designer must read the same way either way.
 * Competitor signals keep their honesty rules: a best-seller label is the
 * merchant's own claim, homepage placement is not ad spend, and social
 * engagement is not sales.
 */
class DesignerInsights
{
    public function __construct(private readonly CompetitorSignals $signals) {}

    private const ATTRIBUTES = ['colors', 'pattern', 'material', 'style'];

    private const RETURN_LIMIT = 25;

    private Store $store;

    private string $locale;

    /** @var array{l3: array{0: string, 1: string}, p3: array{0: string, 1: string}, l12: array{0: string, 1: string}, months: list<string>} */
    private array $window;

    /** @var Collection<int, Product> */
    private Collection $products;

    /** @var \Illuminate\Database\Eloquent\Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, ProductSale>> */
    private Collection $sales;

    /**
     * @return array<string, mixed>
     */
    public function build(Store $store, ?string $locale = null): array
    {
        $this->store = $store;
        $this->locale = $locale ?? app()->getLocale();
        $this->window = $this->window();
        $this->products = Product::query()->where('store_id', $store->id)->get();
        $this->sales = ProductSale::query()->where('store_id', $store->id)
            ->where('period', '>=', $this->window['l12'][0])
            ->get(['product_id', 'period', 'units', 'orders', 'returns', 'views', 'add_to_cart'])
            ->groupBy('product_id');

        $bestsellers = $this->bestsellers();
        $movers = $this->movers();
        [$hits, $posts] = $this->competitorHits($bestsellers);
        $gaps = $this->gaps($hits, $posts);
        $saturation = $this->saturation();
        $seasons = $this->seasons();
        $coverage = $this->coverage();

        return [
            'window' => ['from' => $this->window['l3'][0], 'to' => $this->window['l3'][1]],
            'bestsellers' => $bestsellers,
            'dna' => $this->dna(),
            'rising' => $movers['rising'],
            'fading' => $movers['fading'],
            'competitor_hits' => $hits,
            'social' => $posts,
            'gaps' => $gaps,
            'saturation' => $saturation,
            'seasons' => $seasons,
            'coverage' => $coverage,
            'alerts' => $this->alerts($bestsellers, $movers, $hits, $posts, $gaps, $saturation, $seasons, $coverage),
        ];
    }

    /**
     * L3 is the last three complete months with sales, P3 the three before,
     * L12 the last twelve. The current, partial month is left out.
     *
     * @return array{l3: array{0: string, 1: string}, p3: array{0: string, 1: string}, l12: array{0: string, 1: string}, months: list<string>}
     */
    private function window(): array
    {
        $latest = ProductSale::query()->where('store_id', $this->store->id)
            ->where('period', '<', now()->startOfMonth()->toDateString())->max('period');
        $end = CarbonImmutable::parse($latest ?? now()->startOfMonth()->subMonth())->startOfMonth();
        // Sales that stopped long ago are not "the last three months".
        if ($end->lt(now()->startOfMonth()->subMonths(3))) {
            $end = CarbonImmutable::now()->startOfMonth()->subMonth();
        }

        $l3 = [$end->subMonths(2)->toDateString(), $end->toDateString()];
        $p3 = [$end->subMonths(5)->toDateString(), $end->subMonths(3)->toDateString()];
        $l12 = [$end->subMonths(11)->toDateString(), $end->toDateString()];
        $months = collect(range(11, 0))->map(fn (int $i) => $end->subMonths($i)->format('Y-m'))->all();

        return ['l3' => $l3, 'p3' => $p3, 'l12' => $l12, 'months' => $months];
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @return array{units: int, orders: int, returns: int, months: int}
     */
    private function totals(int $productId, array $range): array
    {
        $rows = $this->sales->get($productId, collect())
            ->filter(fn (ProductSale $s) => $s->period->toDateString() >= $range[0] && $s->period->toDateString() <= $range[1]);

        return [
            'units' => (int) $rows->sum('units'),
            'orders' => (int) $rows->sum('orders'),
            'returns' => (int) $rows->sum('returns'),
            'months' => $rows->count(),
        ];
    }

    /**
     * @return list<int>
     */
    private function spark(int $productId): array
    {
        $byMonth = $this->sales->get($productId, collect())->groupBy(fn (ProductSale $s) => $s->period->format('Y-m'));

        return array_map(fn (string $m) => (int) $byMonth->get($m, collect())->sum('units'), $this->window['months']);
    }

    /**
     * M1 — the store's own best sellers by net units in the last three
     * months (returns under 25%, at least two months of data).
     *
     * @return list<array<string, mixed>>
     */
    private function bestsellers(): array
    {
        return $this->products
            ->map(function (Product $p) {
                $now = $this->totals($p->id, $this->window['l3']);
                $before = $this->totals($p->id, $this->window['p3']);
                $net = $now['units'] - $now['returns'];
                $returnPct = $now['units'] > 0 ? round($now['returns'] / $now['units'] * 100, 1) : 0.0;

                return [$p, $now, $before, $net, $returnPct];
            })
            ->filter(fn (array $row) => $row[1]['months'] >= 2 && $row[3] >= 5 && $row[4] < self::RETURN_LIMIT)
            ->sortByDesc(fn (array $row) => $row[3])
            ->take(8)
            ->map(function (array $row) {
                [$p, $now, $before, $net, $returnPct] = $row;
                $perMonth = $now['units'] / 3;

                return [
                    ...$this->productCard($p),
                    'units' => $net,
                    'orders' => $now['orders'],
                    'return_pct' => $returnPct,
                    'growth_pct' => $before['units'] > 0 ? (int) round(($now['units'] - $before['units']) / $before['units'] * 100) : null,
                    'stock' => $p->stock,
                    'months_of_stock' => $perMonth > 0 ? round($p->stock / $perMonth, 1) : null,
                    'spark' => $this->spark($p->id),
                    'prefill' => $this->prefillFromProduct($p, 'bestseller'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * M1 — the winning DNA: attribute values that sell above their share of
     * the catalog (sales index = unit share ÷ product share × 100).
     *
     * @return list<array<string, mixed>>
     */
    private function dna(): array
    {
        $extracted = $this->products->filter(fn (Product $p) => ! empty($p->features['colors']) && ! empty($p->features['pattern']));
        if ($extracted->count() < 5) {
            return [];
        }

        $units = $extracted->mapWithKeys(fn (Product $p) => [$p->id => $this->totals($p->id, $this->window['l12'])['units']]);
        $totalUnits = max(1, $units->sum());
        $out = [];

        foreach (self::ATTRIBUTES as $attribute) {
            $values = $extracted->flatMap(fn (Product $p) => $this->values($p->features ?? [], $attribute))->unique();

            foreach ($values as $value) {
                $carriers = $extracted->filter(fn (Product $p) => in_array($value, $this->values($p->features ?? [], $attribute), true));
                $carrierUnits = $carriers->sum(fn (Product $p) => $units[$p->id] ?? 0);
                if ($carriers->count() < 3 || $carrierUnits < 20) {
                    continue;
                }

                $index = (int) round(($carrierUnits / $totalUnits) / ($carriers->count() / $extracted->count()) * 100);
                if ($index >= 130) {
                    $out[] = ['attribute' => $attribute, 'value' => $value, 'label' => $this->vocabLabel($attribute, $value),
                        'index' => $index, 'products' => $carriers->count(), 'units' => $carrierUnits,
                        'hex' => $attribute === 'colors' ? (Vocabulary::colors()[$value]['hex'] ?? null) : null];
                }
            }
        }

        return collect($out)->sortByDesc('index')->take(8)->values()->all();
    }

    /**
     * M2 — products rising or fading against the store as a whole, so a
     * store-wide peak such as Ramadan does not make everything "rise".
     *
     * @return array{rising: list<array<string, mixed>>, fading: list<array<string, mixed>>}
     */
    private function movers(): array
    {
        $rows = $this->products->map(fn (Product $p) => [$p, $this->totals($p->id, $this->window['l3']), $this->totals($p->id, $this->window['p3'])]);
        $allNow = max(1, $rows->sum(fn ($r) => $r[1]['units']));
        $allBefore = max(1, $rows->sum(fn ($r) => $r[2]['units']));

        $scored = $rows
            ->filter(fn ($r) => $r[1]['months'] > 0 && $r[2]['months'] > 0)
            ->map(function ($r) use ($allNow, $allBefore) {
                [$p, $now, $before] = $r;
                $shareNow = $now['units'] / $allNow;
                $shareBefore = $before['units'] / $allBefore;

                return [$p, $now, $before, $shareBefore > 0 ? ($shareNow - $shareBefore) / $shareBefore * 100 : null];
            })
            ->filter(fn ($r) => $r[3] !== null);

        $card = fn (array $r) => [
            ...$this->productCard($r[0]),
            'units' => $r[1]['units'],
            'units_before' => $r[2]['units'],
            'change_pct' => (int) round($r[3]),
            'stock' => $r[0]->stock,
            'spark' => $this->spark($r[0]->id),
            'prefill' => $this->prefillFromProduct($r[0], 'rising'),
        ];

        return [
            'rising' => $scored->filter(fn ($r) => $r[1]['units'] >= 6 && $r[3] >= 40)->sortByDesc(fn ($r) => $r[3])->take(5)->map($card)->values()->all(),
            'fading' => $scored->filter(fn ($r) => $r[2]['units'] >= 6 && $r[3] <= -40 && $r[0]->stock > 0)->sortBy(fn ($r) => $r[3])->take(5)->map($card)->values()->all(),
        ];
    }

    /**
     * M3 — competitor products with a public selling signal, ranked by an
     * ordering score that is never shown as sales, plus the competitors'
     * social posts that clearly beat their own usual engagement.
     *
     * @param  list<array<string, mixed>>  $bestsellers
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function competitorHits(array $bestsellers): array
    {
        $active = Competitor::query()->where('store_id', $this->store->id)->where('is_active', true)->pluck('name', 'id');
        if ($active->isEmpty()) {
            return [[], []];
        }

        $mine = $this->products->whereIn('id', array_column($bestsellers, 'id'));

        $hits = $this->signals->storeHits($this->store, $active)
            ->take(8)
            ->map(function (array $hit) use ($active, $mine) {
                $cp = $hit['product'];
                $sig = $hit['signals'];
                $candidate = $this->competitorCandidate($cp);
                $similar = $mine->first(fn (Product $p) => Candidate::fromProduct($p)->similarity($candidate) >= 0.55);

                return [
                    'id' => $cp->id,
                    'name' => $cp->name,
                    'competitor' => $active[$cp->competitor_id] ?? '—',
                    'competitor_id' => $cp->competitor_id,
                    'image_url' => $cp->image_url,
                    'url' => $cp->url,
                    'category' => $cp->category,
                    'price' => $cp->price !== null ? (float) $cp->price : null,
                    'currency' => $cp->evidence['currency'] ?? null,
                    'drop_pct' => $hit['drop_pct'],
                    'signals' => $sig,
                    'published_sales' => $cp->evidence['published_sales'] ?? null,
                    'echo' => $hit['echo'],
                    'rank' => $hit['rank'],
                    'colors' => array_values(array_filter((array) ($cp->features['colors'] ?? []), 'is_string')),
                    'pattern' => $cp->features['pattern'] ?? null,
                    'style' => $cp->features['style'] ?? null,
                    'similar_to' => $similar ? ['id' => $similar->id, 'name' => $similar->name] : null,
                    'seen_at' => $cp->first_seen_at?->toDateString(),
                    'prefill' => $this->prefillFromCompetitor($cp),
                ];
            })
            ->values()
            ->all();

        return [$hits, $this->viralPosts($active)];
    }

    /**
     * Posts whose engagement is at least twice their own account's usual,
     * measured against a baseline of at least five posts.
     *
     * @param  Collection<int, string>  $active
     * @return list<array<string, mixed>>
     */
    private function viralPosts(Collection $active): array
    {
        $posts = SocialPost::query()->where('store_id', $this->store->id)
            ->whereIn('competitor_id', $active->keys())
            ->where(fn ($q) => $q->whereNull('posted_at')->orWhere('posted_at', '>=', now()->subDays(90)))
            ->get();
        $lifts = $this->signals->lifts($posts);

        return $posts
            ->map(fn (SocialPost $p) => [$p, $lifts[$p->id]['lift'] ?? null, $lifts[$p->id]['baseline'] ?? 0])
            ->filter(fn ($r) => $r[1] !== null && $r[1] >= 2 && $r[2] >= CompetitorSignals::BASELINE)
            ->sortByDesc(fn ($r) => $r[1])
            ->take(4)
            ->map(fn ($r) => [
                'id' => $r[0]->id,
                'competitor' => $active[$r[0]->competitor_id] ?? '—',
                'platform' => $r[0]->platform,
                'url' => $r[0]->url,
                // Copied images do not expire like Instagram links do.
                'thumbnail_url' => self::publicUrl($r[0]->evidence['image_path'] ?? null) ?? $r[0]->thumbnail_url,
                'caption' => mb_substr((string) $r[0]->caption, 0, 140),
                'times_median' => $r[1],
                'likes' => $r[0]->likes,
                'comments' => $r[0]->comments,
                'views' => $r[0]->views,
                'colors' => array_values(array_filter((array) ($r[0]->features['colors'] ?? []), 'is_string')),
                'pattern' => $r[0]->features['pattern'] ?? null,
                'posted_at' => $r[0]->posted_at?->toDateString(),
                'prefill' => [
                    'colors' => array_slice(array_values(array_filter((array) ($r[0]->features['colors'] ?? []), 'is_string')), 0, 3),
                    'inspiration' => ['kind' => 'social', 'id' => $r[0]->id, 'label' => $active[$r[0]->competitor_id] ?? '—', 'image_url' => self::publicUrl($r[0]->evidence['image_path'] ?? null) ?? $r[0]->thumbnail_url],
                    'notes' => $this->say('منشور حقق تفاعلًا مرتفعًا لدى المنافس: استلهم الفكرة دون نسخ الشكل.', 'A high-engagement competitor post: borrow the idea, not the look.'),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * M4 — attribute values common among competitor hits (and rising
     * trends) but rare in the store's own live catalog.
     *
     * @param  list<array<string, mixed>>  $hits
     * @param  list<array<string, mixed>>  $posts
     * @return list<array<string, mixed>>
     */
    private function gaps(array $hits, array $posts): array
    {
        $market = CompetitorProduct::query()->where('store_id', $this->store->id)
            ->whereHas('competitor', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '>=', now()->subDays(45)))
            ->get()
            ->map(fn (CompetitorProduct $cp) => [
                'features' => $cp->features ?? [],
                'competitor' => $cp->competitor_id,
                'weight' => 1 + ($cp->is_best_seller ? 1 : 0) + (! empty($cp->evidence['published_sales']) ? 1 : 0) + (! empty($cp->evidence['promotion']) ? 0.5 : 0),
            ]);
        foreach ($posts as $post) {
            $market->push(['features' => ['colors' => $post['colors'], 'pattern' => $post['pattern']], 'competitor' => 'social:'.$post['competitor'], 'weight' => 1.5]);
        }

        $live = $this->products->filter(fn (Product $p) => $p->stock > 0 || ($p->launched_at && $p->launched_at->gte(now()->subYear())));
        $rising = Trend::query()->where('store_id', $this->store->id)->where('status', 'rising')->get();
        $out = [];

        foreach (self::ATTRIBUTES as $attribute) {
            $withType = $market->filter(fn ($m) => $this->values($m['features'], $attribute) !== []);
            $mineWithType = $live->filter(fn (Product $p) => $this->values($p->features ?? [], $attribute) !== []);
            $totalWeight = $withType->sum('weight');
            if ($withType->count() < 4 || $totalWeight <= 0) {
                continue;
            }

            foreach ($withType->flatMap(fn ($m) => $this->values($m['features'], $attribute))->unique() as $value) {
                $carriers = $withType->filter(fn ($m) => in_array($value, $this->values($m['features'], $attribute), true));
                $marketPct = $carriers->sum('weight') / $totalWeight * 100;
                $minePct = $mineWithType->isEmpty() ? 0 : $mineWithType->filter(fn (Product $p) => in_array($value, $this->values($p->features ?? [], $attribute), true))->count() / $mineWithType->count() * 100;
                $competitors = $carriers->pluck('competitor')->unique()->count();
                $trend = $rising->first(fn (Trend $t) => $t->value === $value && $this->trendType($t->type) === $attribute);

                if ($marketPct < 15 || $marketPct - $minePct < 10 || $minePct > 5 || $carriers->count() < 4 || ($competitors < 2 && ! $trend)) {
                    continue;
                }

                $out[] = $this->gapRow($attribute, $value, (int) round($marketPct), (int) round($minePct), $carriers->count(), $competitors, $trend, 'competitors',
                    ($marketPct - $minePct) * min(1, $carriers->count() / 10));
            }
        }

        // Rising market trends the catalog does not carry yet.
        foreach ($rising->where('in_catalog', false) as $trend) {
            $attribute = $this->trendType($trend->type);
            if (! $attribute || collect($out)->contains(fn ($g) => $g['attribute'] === $attribute && $g['value'] === $trend->value)) {
                continue;
            }
            $out[] = $this->gapRow($attribute, (string) $trend->value, null, 0, null, null, $trend, 'trend', $trend->momentum * 0.4);
        }

        return collect($out)->sortByDesc('score')->take(6)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function gapRow(string $attribute, string $value, ?int $marketPct, int $minePct, ?int $items, ?int $competitors, ?Trend $trend, string $source, float $score): array
    {
        $label = $this->vocabLabel($attribute, $value) ?? ($trend ? ($this->locale === 'ar' ? $trend->name_ar : $trend->name_en) : $value);

        return [
            'attribute' => $attribute,
            'value' => $value,
            'label' => $label,
            'source' => $source,
            'market_pct' => $marketPct,
            'mine_pct' => $minePct,
            'items' => $items,
            'competitors' => $competitors,
            'trend' => $trend ? ['id' => $trend->id, 'momentum' => $trend->momentum, 'saturation' => $trend->saturation] : null,
            'hex' => $attribute === 'colors' ? (Vocabulary::colors()[$value]['hex'] ?? null) : null,
            'score' => round($score, 1),
            'prefill' => [
                ...($attribute === 'colors' ? ['colors' => [$value]] : []),
                ...($attribute === 'material' ? ['fabric' => collect(FashionVocabulary::fabrics())->search(fn ($f, $k) => $k === $value || $f['family'] === $value) ?: null] : []),
                'notes' => $this->say("سدّ فجوة: {$label} — رائج في السوق وقليل في كتالوجك.", "Fill the gap: {$label} — common in the market, rare in your catalog."),
                'inspiration' => ['kind' => 'gap', 'id' => "{$attribute}:{$value}", 'label' => $label, 'image_url' => null],
                'pattern' => $attribute === 'pattern' ? $value : null,
                'style' => $attribute === 'style' ? $value : null,
            ],
        ];
    }

    /**
     * M5 — one look (first colour + pattern + style) covering too much of a
     * category, and rising trends that are already crowded.
     *
     * @return list<array<string, mixed>>
     */
    private function saturation(): array
    {
        $out = [];
        $live = $this->products->filter(fn (Product $p) => $p->stock > 0 && ! empty($p->features['colors']));

        foreach ($live->groupBy(fn (Product $p) => $p->category ?: '—') as $category => $items) {
            $signatures = $items->groupBy(fn (Product $p) => implode('|', [$p->features['colors'][0] ?? '', $p->features['pattern'] ?? '', $p->features['style'] ?? '']));
            foreach ($signatures as $signature => $group) {
                $share = $group->count() / $items->count() * 100;
                if ($group->count() >= 4 && $share >= 30) {
                    [$color, $pattern, $style] = explode('|', $signature);
                    $out[] = [
                        'kind' => 'catalog',
                        'category' => $category,
                        'share' => (int) round($share),
                        'products' => $group->count(),
                        'look' => collect([$this->vocabLabel('colors', $color), $this->vocabLabel('pattern', $pattern), $this->vocabLabel('style', $style)])->filter()->implode(' · '),
                    ];
                }
            }
        }

        foreach (Trend::query()->where('store_id', $this->store->id)->where('saturation', '>=', 70)->orderByDesc('saturation')->limit(3)->get() as $trend) {
            $out[] = ['kind' => 'market', 'category' => null, 'share' => $trend->saturation, 'products' => null,
                'look' => $this->locale === 'ar' ? $trend->name_ar : $trend->name_en];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * M6 — seasons in the next 120 days, the prep window and whether the
     * catalog is ready (three or more in-stock products for the season).
     *
     * @return list<array<string, mixed>>
     */
    private function seasons(): array
    {
        return SeasonalEvent::query()->forStore($this->store)
            // White Friday is a pricing season, not a design season.
            ->where(fn ($q) => $q->whereNull('season_key')->orWhere('season_key', '!=', 'white_friday'))
            ->where('starts_on', '>=', now()->toDateString())
            ->where('starts_on', '<=', now()->addDays(120)->toDateString())
            ->orderBy('starts_on')->limit(3)->get()
            ->map(function (SeasonalEvent $e) {
                $ready = $e->season_key ? $this->products->filter(fn (Product $p) => ($p->features['season'] ?? null) === $e->season_key && $p->stock > 0)->count() : 0;
                $prep = $e->starts_on->copy()->subWeeks($e->prep_weeks);
                $status = match (true) {
                    $ready >= 3 => 'ready',
                    now()->gte($prep) && $ready === 0 => 'late',
                    now()->gte($prep) => 'at_risk',
                    default => 'upcoming',
                };
                $occasion = collect(FashionVocabulary::occasions())->search(fn ($o) => $o['season'] === $e->season_key) ?: null;

                return [
                    'id' => $e->id,
                    'key' => $e->season_key,
                    'name' => $this->locale === 'ar' ? $e->name_ar : $e->name_en,
                    'starts_on' => $e->starts_on->toDateString(),
                    'prep_starts_on' => $prep->toDateString(),
                    'days_until' => (int) now()->startOfDay()->diffInDays($e->starts_on, false),
                    'ready_products' => $ready,
                    'status' => $status,
                    'palette' => $e->palette ?? [],
                    'prefill' => [
                        'occasion' => $occasion,
                        'colors' => array_slice($e->palette ?? [], 0, 3),
                        'inspiration' => ['kind' => 'season', 'id' => $e->id, 'label' => $this->locale === 'ar' ? $e->name_ar : $e->name_en, 'image_url' => null],
                        'notes' => $this->say("مجموعة {$e->name_ar}", "A {$e->name_en} collection"),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * How much the designer can see, so the page can say what is missing.
     *
     * @return array<string, mixed>
     */
    private function coverage(): array
    {
        $competitorProducts = CompetitorProduct::query()->where('store_id', $this->store->id);

        return [
            'products' => $this->products->count(),
            'with_sales' => $this->sales->count(),
            'features_pct' => $this->products->isEmpty() ? null : (int) round($this->products->filter(fn (Product $p) => ! empty($p->features['colors']))->count() / $this->products->count() * 100),
            'competitors' => Competitor::query()->where('store_id', $this->store->id)->where('is_active', true)->count(),
            'competitor_products' => (clone $competitorProducts)->count(),
            'competitor_features_pct' => ($n = (clone $competitorProducts)->count()) > 0
                ? (int) round((clone $competitorProducts)->whereNotNull('features')->get(['features'])->filter(fn ($cp) => ! empty($cp->features['colors']))->count() / $n * 100) : null,
            'last_scan_at' => Competitor::query()->where('store_id', $this->store->id)->max('last_checked_at'),
            'last_sales_period' => $this->sales->isEmpty() ? null : $this->window['l3'][1],
            'trends' => Trend::query()->where('store_id', $this->store->id)->where('status', 'rising')->count(),
        ];
    }

    /**
     * Alerts that follow from the modules, most severe first.
     *
     * @param  list<array<string, mixed>>  $bestsellers
     * @param  array{rising: list<array<string, mixed>>, fading: list<array<string, mixed>>}  $movers
     * @param  list<array<string, mixed>>  $hits
     * @param  list<array<string, mixed>>  $posts
     * @param  list<array<string, mixed>>  $gaps
     * @param  list<array<string, mixed>>  $saturation
     * @param  list<array<string, mixed>>  $seasons
     * @param  array<string, mixed>  $coverage
     * @return list<array<string, mixed>>
     */
    private function alerts(array $bestsellers, array $movers, array $hits, array $posts, array $gaps, array $saturation, array $seasons, array $coverage): array
    {
        $alerts = [];
        $add = function (string $type, string $severity, string $ar, string $en, ?array $prefill = null, ?string $href = null, ?string $image = null) use (&$alerts) {
            $alerts[] = ['type' => $type, 'severity' => $severity, 'text' => $this->say($ar, $en), 'prefill' => $prefill, 'href' => $href, 'image_url' => $image];
        };

        foreach ($movers['fading'] as $p) {
            $add('fading_product', 'warning',
                "تراجعت مبيعات «{$p['name']}» بنسبة ".abs($p['change_pct'])."% خلال آخر 3 أشهر، والمخزون {$p['stock']} قطعة.",
                "Sales of “{$p['name']}” fell ".abs($p['change_pct'])."% over the last 3 months; {$p['stock']} units in stock.",
                null, "/store/products/{$p['id']}", $p['image_url']);
        }

        foreach (collect(array_merge($bestsellers, $movers['rising']))->unique('id') as $p) {
            $months = $p['months_of_stock'] ?? null;
            if ($months !== null && $months < 1) {
                $add('low_stock', $p['stock'] <= 0 ? 'danger' : 'warning',
                    "«{$p['name']}» يبيع بقوة ({$p['units']} قطعة في 3 أشهر) والمخزون يكفي أقل من شهر.",
                    "“{$p['name']}” sells strongly ({$p['units']} units in 3 months) and stock covers less than a month.",
                    $p['prefill'], "/store/products/{$p['id']}", $p['image_url']);
            }
        }

        foreach ($movers['rising'] as $p) {
            $add('rising_product', 'info',
                "«{$p['name']}» يصعد: {$p['units']} قطعة في آخر 3 أشهر (+{$p['change_pct']}% من حصته). صمّم امتدادًا له.",
                "“{$p['name']}” is rising: {$p['units']} units in 3 months (+{$p['change_pct']}% share). Design an extension.",
                $p['prefill'], null, $p['image_url']);
        }

        foreach ($hits as $hit) {
            if (($hit['drop_pct'] ?? 0) >= 15 && $hit['similar_to']) {
                $add('competitor_price_drop', 'warning',
                    "خفّض {$hit['competitor']} سعر «{$hit['name']}» بنسبة {$hit['drop_pct']}%، وهو قريب من منتجك الرابح «{$hit['similar_to']['name']}».",
                    "{$hit['competitor']} cut the price of “{$hit['name']}” by {$hit['drop_pct']}%; it is close to your best seller “{$hit['similar_to']['name']}”.",
                    null, $hit['url'], $hit['image_url']);
            } elseif (in_array('best_seller_label', $hit['signals'], true) && in_array('new_arrival', $hit['signals'], true)) {
                $add('competitor_new_hit', 'info',
                    "أدرج {$hit['competitor']} «{$hit['name']}» ضمن الأكثر مبيعًا لديه (ادّعاء من المتجر نفسه).",
                    "{$hit['competitor']} lists “{$hit['name']}” among its best sellers (the store's own claim).",
                    $hit['prefill'], $hit['url'], $hit['image_url']);
            }
        }

        foreach ($posts as $post) {
            if ($post['times_median'] >= 3) {
                $add('viral_post', 'info',
                    "منشور لـ{$post['competitor']} حقّق تفاعلًا يعادل {$post['times_median']} أضعاف معدّله المعتاد. التفاعل ليس مبيعات، لكنه يكشف ذوق الجمهور.",
                    "A {$post['competitor']} post drew {$post['times_median']}× its usual engagement. Engagement is not sales, but it shows taste.",
                    $post['prefill'], $post['url'], $post['thumbnail_url']);
            }
        }

        foreach (array_slice($gaps, 0, 2) as $gap) {
            $add('gap_opportunity', 'info',
                $gap['market_pct'] !== null
                    ? "فرصة: «{$gap['label']}» يظهر في {$gap['market_pct']}% من منتجات المنافسين الرائجة مقابل {$gap['mine_pct']}% في كتالوجك."
                    : "توجّه صاعد غير موجود في كتالوجك: «{$gap['label']}» (زخم {$gap['trend']['momentum']}/100).",
                $gap['market_pct'] !== null
                    ? "Opportunity: “{$gap['label']}” appears in {$gap['market_pct']}% of competitor hits vs {$gap['mine_pct']}% of your catalog."
                    : "A rising trend you don't carry: “{$gap['label']}” (momentum {$gap['trend']['momentum']}/100).",
                $gap['prefill']);
        }

        foreach ($saturation as $s) {
            if ($s['kind'] === 'catalog') {
                $add('saturation', 'warning',
                    "تشبّع: {$s['share']}% من «{$s['category']}» بالشكل نفسه ({$s['look']}). لا تضف المزيد منه.",
                    "Saturation: {$s['share']}% of “{$s['category']}” share one look ({$s['look']}). Don't add more of it.");
            }
        }

        foreach ($seasons as $season) {
            if (in_array($season['status'], ['late', 'at_risk'], true)) {
                $add($season['status'] === 'late' ? 'season_late' : 'season_prep', $season['status'] === 'late' ? 'danger' : 'warning',
                    "{$season['name']} ".self::daysAr($season['days_until']).' وبدأت فترة التحضير، '.($season['ready_products'] > 0 ? "وفي كتالوجك {$season['ready_products']} منتج جاهز له فقط." : 'ولا يوجد في كتالوجك منتج جاهز له بعد.'),
                    "{$season['name']} is {$season['days_until']} days away; prep has started and you have only {$season['ready_products']} ready products.",
                    $season['prefill']);
            } elseif ($season['status'] === 'upcoming') {
                $add('season_upcoming', 'info',
                    "{$season['name']} ".self::daysAr($season['days_until']).". تبدأ فترة التحضير في {$season['prep_starts_on']}.",
                    "{$season['name']} is {$season['days_until']} days away. Prep starts on {$season['prep_starts_on']}.",
                    $season['prefill']);
            }
        }

        if ($coverage['competitors'] > 0 && $coverage['last_scan_at'] && CarbonImmutable::parse($coverage['last_scan_at'])->lt(now()->subDays(14))) {
            $add('data_stale', 'warning', 'لم يُفحص المنافسون منذ أكثر من أسبوعين؛ حدّث الفحص لتبقى القراءة دقيقة.', 'Competitors were last scanned over two weeks ago; rescan to keep this accurate.', null, '/store/competitors');
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];

        // At most two of a kind, so one busy signal does not hide the rest.
        return collect($alerts)->groupBy('type')->flatMap(fn (Collection $group) => $group->take(2))
            ->sortBy(fn ($a) => $rank[$a['severity']])->take(10)->values()->all();
    }

    /**
     * "In N days" with the Arabic plural rules: 3–10 takes أيام.
     */
    public static function daysAr(int $days): string
    {
        return match (true) {
            $days <= 0 => 'اليوم',
            $days === 1 => 'غدًا',
            $days === 2 => 'بعد يومين',
            $days <= 10 => "بعد {$days} أيام",
            default => "بعد {$days} يومًا",
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function productCard(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'category' => $p->category,
            'image_url' => $p->image_url,
            'colors' => array_values(array_filter((array) ($p->features['colors'] ?? []), 'is_string')),
            'pattern' => $p->features['pattern'] ?? null,
            'garment' => FashionVocabulary::garmentFor($p->category, $p->name),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prefillFromProduct(Product $p, string $kind): array
    {
        $fabric = collect(FashionVocabulary::fabrics())->search(fn ($f) => $f['family'] === ($p->features['material'] ?? '__')) ?: null;

        return [
            'garment' => FashionVocabulary::garmentFor($p->category, $p->name),
            'fabric' => $fabric,
            'colors' => array_slice(array_values(array_filter((array) ($p->features['colors'] ?? []), 'is_string')), 0, 3),
            'pattern' => $p->features['pattern'] ?? null,
            'style' => $p->features['style'] ?? null,
            'notes' => $this->say("امتداد لمنتجك الناجح «{$p->name}» بنفس بصمته مع تغيير مدروس.", "An extension of your best seller “{$p->name}”: same DNA, one deliberate change."),
            'inspiration' => ['kind' => $kind, 'id' => $p->id, 'label' => $p->name, 'image_url' => $p->image_url],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prefillFromCompetitor(CompetitorProduct $cp): array
    {
        return [
            'garment' => FashionVocabulary::garmentFor($cp->category, $cp->name),
            'colors' => array_slice(array_values(array_filter((array) ($cp->features['colors'] ?? []), 'is_string')), 0, 3),
            'pattern' => $cp->features['pattern'] ?? null,
            'style' => $cp->features['style'] ?? null,
            'notes' => $this->say('ردّ على منتج رائج لدى منافس: خذ الفكرة وغيّر عنصرين على الأقل (القصّة، النقشة أو اللون) بروح متجرك.',
                'Answer a competitor hit: take the idea and change at least two of silhouette, pattern or colour, in your store\'s spirit.'),
            'inspiration' => ['kind' => 'competitor', 'id' => $cp->id, 'label' => $cp->name, 'image_url' => $cp->image_url],
        ];
    }

    private function competitorCandidate(CompetitorProduct $cp): Candidate
    {
        $f = $cp->features ?? [];

        return new Candidate($cp->category, $cp->price ? (float) $cp->price : null,
            (array) ($f['colors'] ?? []), $f['pattern'] ?? null, $f['material'] ?? null, $f['style'] ?? null, $f['season'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $features
     * @return list<string>
     */
    private function values(array $features, string $attribute): array
    {
        $value = $features[$attribute] ?? null;

        return array_values(array_filter(is_array($value) ? $value : [$value], fn ($v) => is_string($v) && $v !== ''));
    }

    private function trendType(string $type): ?string
    {
        return ['color' => 'colors', 'pattern' => 'pattern', 'material' => 'material', 'style' => 'style'][$type] ?? null;
    }

    private function vocabLabel(string $attribute, ?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $group = ['colors' => 'colors', 'pattern' => 'patterns', 'material' => 'materials', 'style' => 'styles'][$attribute] ?? $attribute;

        return Vocabulary::label($group, $value, $this->locale);
    }

    private function say(string $ar, string $en): string
    {
        return $this->locale === 'ar' ? $ar : $en;
    }

    /**
     * Public image URL for a stored path.
     */
    public static function publicUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
