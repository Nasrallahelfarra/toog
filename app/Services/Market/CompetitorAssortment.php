<?php

namespace App\Services\Market;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\Product;
use App\Models\SeasonalEvent;
use App\Models\Store;
use App\Models\Trend;
use App\Services\Evaluation\Candidate;
use App\Support\FashionVocabulary;
use App\Support\Vocabulary;
use Illuminate\Support\Collection;

/**
 * The competitors' stores against the merchant's own catalog: the best
 * competitor designs worth adding (with "you already have something like
 * it" and the price against the merchant's own band), the activity that
 * is not demand (price cuts, new arrivals), and the assortment analysis
 * per competitor. List prices and units only — never revenue.
 */
class CompetitorAssortment
{
    /** Price-band verdicts outside these multiples of p25 / p75. */
    private const BELOW = 0.9;

    private const ABOVE = 1.1;

    private string $locale = 'ar';

    /** @var Collection<int, Product> */
    private Collection $mine;

    public function __construct(private readonly CompetitorSignals $signals) {}

    /**
     * @param  Collection<int|string, string>  $active  competitor names by id
     * @param  list<array<string, mixed>>  $socialLooks  trending social posts with a look (from the radar)
     * @return array{recommendations: list<array<string, mixed>>, activity: list<array<string, mixed>>, analysis: array<string, mixed>}
     */
    public function build(Store $store, Collection $active, array $socialLooks, string $locale): array
    {
        $this->locale = $locale;
        $this->mine = Product::query()->where('store_id', $store->id)->get();
        $hits = $this->signals->storeHits($store, $active);

        return [
            'recommendations' => $this->recommendations($store, $hits, $socialLooks, $active),
            'activity' => $this->activity($hits, $active),
            'analysis' => $this->analysis($store, $active),
        ];
    }

    /**
     * Section G: competitor designs worth adding. Each needs a demand signal
     * (a best-seller label, published sales, or a post at 2× its usual) and
     * a second, independent one (the same look at another brand, or a
     * second kind of signal).
     *
     * @param  Collection<int, array<string, mixed>>  $hits
     * @param  list<array<string, mixed>>  $socialLooks
     * @param  Collection<int|string, string>  $active
     * @return list<array<string, mixed>>
     */
    private function recommendations(Store $store, Collection $hits, array $socialLooks, Collection $active): array
    {
        // Every brand behind each look, from store demand and strong posts.
        $brandsByLook = [];
        $socialByLook = [];
        foreach ($hits as $hit) {
            if ($hit['look'] && CompetitorSignals::isDemand($hit['signals'])) {
                $brandsByLook[$hit['look']][CompetitorSignals::brand((string) ($active[$hit['product']->competitor_id] ?? ''))] = true;
            }
        }
        // Posts that name the exact product (by its code).
        $socialByProduct = [];
        foreach ($socialLooks as $post) {
            if (($post['product']['id'] ?? null) && $post['lift'] !== null && $post['baseline'] >= CompetitorSignals::BASELINE) {
                $socialByProduct[$post['product']['id']] = max($socialByProduct[$post['product']['id']] ?? 0, $post['lift']);
            }
        }
        foreach ($socialLooks as $post) {
            if ($post['look'] && $post['lift'] !== null && $post['lift'] >= 1.5) {
                $brandsByLook[$post['look']][$post['brand']] = true;
                $socialByLook[$post['look']] = max($socialByLook[$post['look']] ?? 0, $post['baseline'] >= CompetitorSignals::BASELINE ? $post['lift'] : 0);
            }
        }

        $rising = Trend::query()->where('store_id', $store->id)->where('status', 'rising')->get();
        $seasons = SeasonalEvent::query()->forStore($store)->whereBetween('starts_on', [now()->toDateString(), now()->addDays(120)->toDateString()])
            ->pluck('season_key')->filter()->all();
        $myGarments = $this->mine->map(fn (Product $p) => FashionVocabulary::garmentFor($p->category, $p->name))->filter()->unique()->all();
        $featuresKnown = $this->mine->isNotEmpty()
            && $this->mine->filter(fn (Product $p) => ! empty($p->features['colors']))->count() / $this->mine->count() >= 0.5;

        // No image is fine: the card shows the garment instead.
        $candidates = $hits->filter(fn ($h) => $h['look'])
            ->map(fn (array $h) => ['kind' => 'product', 'hit' => $h, 'look' => $h['look']])
            ->values();
        // Social looks with no store product behind them still count.
        foreach ($socialLooks as $post) {
            if ($post['look'] && $post['lift'] >= 2 && $post['baseline'] >= CompetitorSignals::BASELINE
                && ! $candidates->contains(fn ($c) => $c['look'] === $post['look'])) {
                $candidates->push(['kind' => 'post', 'post' => $post, 'look' => $post['look']]);
            }
        }

        return $candidates
            ->map(function (array $c) use ($brandsByLook, $socialByLook, $socialByProduct, $rising, $seasons, $myGarments, $featuresKnown, $active) {
                $look = $c['look'];
                [$garment, $color, $pattern] = array_pad(explode('|', $look), 3, '');
                $brands = count($brandsByLook[$look] ?? []);
                $signals = $c['kind'] === 'product' ? $c['hit']['signals'] : [];
                $social = (float) max($socialByLook[$look] ?? 0, $c['kind'] === 'post' ? $c['post']['lift'] : ($socialByProduct[$c['hit']['product']->id] ?? 0));
                $demand = CompetitorSignals::isDemand($signals) || $social >= 2;
                $kinds = count(array_unique([...array_map(fn ($s) => $s === 'homepage_featured' ? 'placement' : $s, $signals), ...($social >= 1.5 ? ['engagement'] : [])]));
                if (! $demand || ($brands < 2 && $kinds < 2)) {
                    return null;
                }

                $score = (in_array('best_seller_label', $signals, true) ? 3 : 0)
                    + (in_array('published_sales', $signals, true) ? 3 : 0)
                    + (in_array('homepage_featured', $signals, true) && count($signals) >= 2 ? 1 : 0)
                    + (in_array('new_arrival', $signals, true) ? 0.5 : 0)
                    + ($social >= 3 ? 3 : ($social >= 2 ? 2 : ($social >= 1.5 ? 1 : 0)))
                    + ($brands >= 3 ? 3 : ($brands === 2 ? 2 : 0))
                    + ($rising->contains(fn (Trend $t) => in_array($t->value, [$color, $pattern], true)) ? 1 : 0);

                $item = $c['kind'] === 'product' ? $this->productItem($c['hit'], $active) : $this->postItem($c['post']);
                if ($social >= 1.5 && ! in_array('engagement', $item['signals'], true)) {
                    $item['signals'][] = 'engagement';
                }
                if ($item['season'] && in_array($item['season'], $seasons, true)) {
                    $score++;
                }

                $match = $this->closest($garment, $item['candidate'], $featuresKnown);
                $newGarment = ! in_array($garment, $myGarments, true);
                $novelty = match ($match['status']) {
                    'similar' => 0.0,
                    'close' => 0.5,
                    'unknown' => 0.8,
                    default => 1.0,
                };
                $band = $this->band($garment);
                $position = $this->position($item['price'], $item['currency'], $item['platform'], $band);

                $verb = match (true) {
                    $match['status'] === 'similar' && $match['product'] && $match['product']->stock <= 0 => 'restock',
                    $match['status'] === 'similar' => null,
                    $position['status'] !== null && $position['status'] !== 'within' => 'price_fit',
                    $brands < 2 || $newGarment => 'test_small',
                    $score * $novelty >= 8 && $position['status'] === 'within' => 'add_now',
                    default => 'watch',
                };
                if ($verb === null) {
                    return null;
                }

                return [
                    ...array_diff_key($item, ['candidate' => true]),
                    'look' => $look,
                    'garment' => $garment,
                    'score' => round($score * ($novelty ?: 1) * ($newGarment ? 0.7 : 1), 1),
                    'brands' => max(1, $brands),
                    'social_lift' => $social >= 1.5 ? round($social, 1) : null,
                    'novelty' => $match['status'],
                    'similar' => $match['product'] ? ['id' => $match['product']->id, 'name' => $match['product']->name, 'image_url' => $match['product']->image_url,
                        'price' => $match['product']->price !== null ? (float) $match['product']->price : null, 'differs' => $match['differs']] : null,
                    'new_garment' => $newGarment,
                    'band' => $band,
                    'position' => $position,
                    'verb' => $verb,
                    'text' => $this->wording($verb, $item, $brands, $match, $band, $position, $garment, $color, $pattern),
                    'prefill' => $this->prefill($item, $garment, $color, $pattern),
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->take(24)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $hit
     * @param  Collection<int|string, string>  $active
     * @return array<string, mixed>
     */
    private function productItem(array $hit, Collection $active): array
    {
        /** @var CompetitorProduct $cp */
        $cp = $hit['product'];
        $features = CompetitorSignals::features($cp);

        return [
            'kind' => 'product',
            'id' => $cp->id,
            'name' => $cp->name,
            'competitor' => $active[$cp->competitor_id] ?? '—',
            'competitor_id' => $cp->competitor_id,
            'platform' => $cp->competitor?->platform,
            'image_url' => $cp->image_url,
            'url' => $cp->url,
            'price' => $cp->price !== null ? (float) $cp->price : null,
            'previous_price' => $cp->previous_price !== null ? (float) $cp->previous_price : null,
            'currency' => $cp->evidence['currency'] ?? null,
            'signals' => $hit['signals'],
            'published_sales' => is_array($cp->evidence['published_sales'] ?? null) ? ($cp->evidence['published_sales']['text'] ?? null) : ($cp->evidence['published_sales'] ?? null),
            'drop_pct' => $hit['drop_pct'],
            'watched' => $cp->watched_at !== null,
            'season' => $features['season'] ?? null,
            'colors' => array_values(array_filter((array) ($features['colors'] ?? []), 'is_string')),
            'pattern' => $features['pattern'] ?? null,
            'style' => $features['style'] ?? null,
            'first_seen_at' => $cp->first_seen_at?->toDateString(),
            'candidate' => new Candidate($cp->category, $cp->price !== null ? (float) $cp->price : null, (array) ($features['colors'] ?? []),
                $features['pattern'] ?? null, $features['material'] ?? null, $features['style'] ?? null, $features['season'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function postItem(array $post): array
    {
        return [
            'kind' => 'post',
            'id' => $post['id'],
            'name' => $post['caption'] !== '' ? mb_substr($post['caption'], 0, 80) : $post['competitor'],
            'competitor' => $post['competitor'],
            'competitor_id' => $post['competitor_id'],
            'platform' => $post['platform'],
            'image_url' => $post['image_url'],
            'url' => $post['url'],
            'price' => null,
            'previous_price' => null,
            'currency' => null,
            'signals' => ['engagement'],
            'published_sales' => null,
            'drop_pct' => null,
            'watched' => false,
            'season' => $post['tags']['occasion'] ?? null,
            'colors' => $post['tags']['colors'],
            'pattern' => $post['tags']['pattern'],
            'style' => $post['tags']['style'],
            'first_seen_at' => $post['posted_at'],
            'candidate' => new Candidate(null, null, $post['tags']['colors'], $post['tags']['pattern'], null, $post['tags']['style'], null),
        ];
    }

    /**
     * The merchant's closest live product of the same garment: similar
     * (≥ 0.55), close (0.35–0.55) or none. No claim when attributes are
     * missing on either side.
     *
     * @return array{status: string, product: Product|null, differs: string|null}
     */
    private function closest(string $garment, Candidate $theirs, bool $featuresKnown): array
    {
        if (! $featuresKnown || $theirs->colors === []) {
            return ['status' => 'unknown', 'product' => null, 'differs' => null];
        }

        // Out-of-stock products count too: a similar one sold out means "restock".
        $live = $this->mine->filter(fn (Product $p) => FashionVocabulary::garmentFor($p->category, $p->name) === $garment && ! empty($p->features['colors']));
        if ($live->isEmpty()) {
            return ['status' => 'none', 'product' => null, 'differs' => null];
        }

        $best = $live->map(fn (Product $p) => [$p, Candidate::fromProduct($p)->similarity($theirs)])->sortByDesc(fn ($r) => $r[1])->first();
        [$product, $similarity] = $best;
        $mine = Candidate::fromProduct($product);
        $differs = match (true) {
            array_intersect($mine->colors, $theirs->colors) === [] => 'colors',
            $mine->pattern !== $theirs->pattern => 'pattern',
            $mine->style !== $theirs->style => 'style',
            default => null,
        };

        return match (true) {
            $similarity >= 0.55 => ['status' => 'similar', 'product' => $product, 'differs' => null],
            $similarity >= 0.35 => ['status' => 'close', 'product' => $product, 'differs' => $differs],
            default => ['status' => 'none', 'product' => null, 'differs' => null],
        };
    }

    /**
     * The merchant's own price band for a garment: p25–p75 of their live
     * products (the whole catalog when fewer than three).
     *
     * @return array{min: float, max: float}|null
     */
    public function band(string $garment): ?array
    {
        $prices = fn (Collection $products) => $products->pluck('price')->filter(fn ($p) => $p !== null && (float) $p > 0)->map(fn ($p) => (float) $p)->sort()->values();
        $own = $prices($this->mine->filter(fn (Product $p) => FashionVocabulary::garmentFor($p->category, $p->name) === $garment));
        $list = $own->count() >= 3 ? $own : $prices($this->mine);
        if ($list->count() < 3) {
            return null;
        }
        $q = fn (float $p) => $list[(int) floor(($list->count() - 1) * $p)];

        return ['min' => round($q(0.25)), 'max' => round($q(0.75))];
    }

    /**
     * Where a competitor price sits against the band, in the same currency
     * only (Saudi stores without a stated currency are in riyals).
     *
     * @param  array{min: float, max: float}|null  $band
     * @return array{status: string|null, pct: int|null}
     */
    private function position(?float $price, ?string $currency, ?string $platform, ?array $band): array
    {
        $sar = $currency === 'SAR' || ($currency === null && in_array($platform, ['salla', 'zid'], true));
        if ($price === null || ! $band || ! $sar) {
            return ['status' => null, 'pct' => null];
        }

        return match (true) {
            $price < $band['min'] * self::BELOW => ['status' => 'below', 'pct' => (int) round(($band['min'] - $price) / $band['min'] * 100)],
            $price > $band['max'] * self::ABOVE => ['status' => 'above', 'pct' => (int) round(($price - $band['max']) / $band['max'] * 100)],
            default => ['status' => 'within', 'pct' => null],
        };
    }

    /**
     * The recommendation sentence; every number comes from the item.
     *
     * @param  array<string, mixed>  $item
     * @param  array{status: string, product: Product|null, differs: string|null}  $match
     * @param  array{min: float, max: float}|null  $band
     * @param  array{status: string|null, pct: int|null}  $position
     */
    private function wording(string $verb, array $item, int $brands, array $match, ?array $band, array $position, string $garment, string $color, string $pattern): string
    {
        $ar = $this->locale === 'ar';
        $look = trim(implode(' ', array_filter([
            FashionVocabulary::label('garments', $garment, $this->locale),
            Vocabulary::label('colors', $color, $this->locale),
            $pattern && $pattern !== 'solid' ? Vocabulary::label('patterns', $pattern, $this->locale) : null,
        ])));
        $why = [];
        if (in_array('best_seller_label', $item['signals'], true)) {
            $why[] = $ar ? "يضعه {$item['competitor']} ضمن الأكثر مبيعًا (ادّعاء المتجر)" : "{$item['competitor']} lists it as a best seller (the store's claim)";
        }
        if (! empty($item['published_sales'])) {
            $why[] = $ar ? "يعرض المتجر «{$item['published_sales']}»" : "the store shows “{$item['published_sales']}”";
        }
        if (in_array('engagement', $item['signals'], true)) {
            $why[] = $ar ? "منشور لـ{$item['competitor']} حقق تفاعلًا فوق معتاده" : "a {$item['competitor']} post beat its usual engagement";
        }
        if ($brands >= 2) {
            $why[] = $ar ? "وتكرر عند {$brands} منافسين" : "and it repeats at {$brands} competitors";
        }
        $have = match ($match['status']) {
            'none' => $ar ? 'وليس في كتالوجك مثله' : 'and you have nothing like it',
            'close' => $ar ? "وأقرب منتج لك «{$match['product']?->name}»" : "and your closest is “{$match['product']?->name}”",
            'similar' => $ar ? "ولديك مشابه «{$match['product']?->name}» نفد مخزونه" : "and your similar “{$match['product']?->name}” is out of stock",
            default => '',
        };
        $price = $item['price'] !== null && $band
            ? match ($position['status']) {
                'within' => $ar ? " سعره {$item['price']} ر.س ضمن نطاق أسعارك ({$band['min']}–{$band['max']})." : " Its price {$item['price']} SAR is within your band ({$band['min']}–{$band['max']}).",
                'above' => $ar ? " سعره أعلى من نطاقك بـ{$position['pct']}%." : " Its price is {$position['pct']}% above your band.",
                'below' => $ar ? " سعره أقل من نطاقك بـ{$position['pct']}%." : " Its price is {$position['pct']}% below your band.",
                default => '',
            } : '';
        $lead = [
            'add_now' => $ar ? 'أضف الآن' : 'Add now',
            'price_fit' => $ar ? 'قدّمه ضمن نطاق سعرك' : 'Offer it within your price band',
            'test_small' => $ar ? 'جرّب كمية صغيرة' : 'Test a small run',
            'restock' => $ar ? 'أعد توفيره' : 'Restock it',
            'watch' => $ar ? 'راقب' : 'Watch',
        ][$verb];
        $tail = $verb === 'restock' ? '' : ($ar ? ' ميّزه بتغيير القصّة أو موضع التفاصيل.' : ' Set it apart by changing the cut or where the details sit.');

        return "{$lead}: {$look} — ".implode($ar ? '، ' : ', ', $why).($have !== '' ? ($ar ? '، ' : ', ').$have : '').'.'.$price.$tail;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function prefill(array $item, string $garment, string $color, string $pattern): array
    {
        return [
            'garment' => $garment,
            'colors' => array_slice(array_values(array_unique([$color, ...$item['colors']])), 0, 3),
            'pattern' => $pattern ?: null,
            'style' => $item['style'],
            'notes' => $this->locale === 'ar'
                ? 'مستوحى من تصميم رائج لدى منافس: خذ الفكرة وغيّر عنصرين على الأقل (القصّة، النقشة أو اللون) بروح متجرك.'
                : 'Inspired by a competitor hit: take the idea and change at least two of cut, pattern or colour, in your store\'s spirit.',
            'inspiration' => ['kind' => $item['kind'] === 'post' ? 'social' : 'competitor', 'id' => $item['id'], 'label' => $item['name'], 'image_url' => $item['image_url']],
        ];
    }

    /**
     * Price cuts and new arrivals on their own: activity, not demand.
     *
     * @param  Collection<int, array<string, mixed>>  $hits
     * @param  Collection<int|string, string>  $active
     * @return list<array<string, mixed>>
     */
    private function activity(Collection $hits, Collection $active): array
    {
        return $hits->filter(fn (array $h) => ! CompetitorSignals::isDemand($h['signals']))
            ->take(10)
            ->map(fn (array $h) => [
                'id' => $h['product']->id,
                'name' => $h['product']->name,
                'competitor' => $active[$h['product']->competitor_id] ?? '—',
                'image_url' => $h['product']->image_url,
                'url' => $h['product']->url,
                'price' => $h['product']->price !== null ? (float) $h['product']->price : null,
                'previous_price' => $h['product']->previous_price !== null ? (float) $h['product']->previous_price : null,
                'currency' => $h['product']->evidence['currency'] ?? null,
                'signals' => $h['signals'],
                'drop_pct' => $h['drop_pct'],
                'watched' => $h['product']->watched_at !== null,
                'first_seen_at' => $h['product']->first_seen_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Section H: each store competitor against the merchant — garment mix,
     * top colours, price bands, new-arrival pace and price cuts.
     *
     * @param  Collection<int|string, string>  $active
     * @return array<string, mixed>
     */
    private function analysis(Store $store, Collection $active): array
    {
        $stores = Competitor::query()->where('store_id', $store->id)->where('is_active', true)
            ->whereNotIn('platform', Competitor::SOCIAL_PLATFORMS)->get();
        $products = CompetitorProduct::query()->where('store_id', $store->id)->whereIn('competitor_id', $stores->pluck('id'))
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '>=', now()->subDays(45)))->get();
        // Shares among products whose garment is known.
        $mix = function (Collection $items, callable $garment) {
            $known = $items->map($garment)->filter();

            return $known->countBy()->map(fn (int $n) => (int) round($n / max(1, $known->count()) * 100))->sortDesc()->all();
        };
        $colorsOf = fn ($p) => $p instanceof CompetitorProduct ? (CompetitorSignals::features($p)['colors'] ?? []) : ($p->features['colors'] ?? []);
        $colors = fn (Collection $items) => $items->flatMap(fn ($p) => array_slice((array) $colorsOf($p), 0, 1))->filter(fn ($c) => is_string($c))
            ->countBy()->sortDesc()->keys()->take(3)->values()->all();
        $classified = fn (Collection $items, callable $garment) => $items->isEmpty() ? null : (int) round($items->filter(fn ($p) => $garment($p))->count() / $items->count() * 100);
        $myMix = $mix($this->mine, fn (Product $p) => FashionVocabulary::garmentFor($p->category, $p->name));
        $myColors = $colors($this->mine);

        $rows = $stores->map(function (Competitor $c) use ($products, $mix, $colors, $myMix, $classified) {
            $items = $products->where('competitor_id', $c->id);
            $priced = $items->filter(fn (CompetitorProduct $p) => $p->price !== null && (float) $p->price > 0
                && (($p->evidence['currency'] ?? null) === 'SAR' || (($p->evidence['currency'] ?? null) === null && in_array($c->platform, ['salla', 'zid'], true))))
                ->map(fn (CompetitorProduct $p) => (float) $p->price)->sort()->values();
            $q = fn (float $p) => $priced[(int) floor(($priced->count() - 1) * $p)];
            $first = $items->min('first_seen_at');
            $spanDays = $first ? (int) $first->diffInDays(now()) : 0;
            $fresh = $first ? $items->filter(fn (CompetitorProduct $p) => $p->first_seen_at && $p->first_seen_at->gte(now()->subDays(30)) && $p->first_seen_at->gt($first->copy()->addDay())) : collect();
            $cuts = $items->filter(fn (CompetitorProduct $p) => $p->previous_price && $p->price && (float) $p->previous_price > 0
                && ((float) $p->previous_price - (float) $p->price) / (float) $p->previous_price >= 0.10);
            $garmentMix = $mix($items, fn (CompetitorProduct $p) => FashionVocabulary::garmentFor($p->category, $p->name));

            return [
                'id' => $c->id,
                'name' => $c->name,
                'platform' => $c->platform,
                'products' => $items->count(),
                'mix' => $garmentMix,
                'classified_pct' => $classified($items, fn (CompetitorProduct $p) => FashionVocabulary::garmentFor($p->category, $p->name)),
                // Garments they carry that the merchant does not.
                'missing' => array_values(array_diff(array_keys($garmentMix), array_keys($myMix))),
                'colors' => $colors($items),
                'price' => $priced->count() >= 5 ? ['p25' => round($q(0.25)), 'median' => round($q(0.5)), 'p75' => round($q(0.75)), 'min' => round($priced->first()), 'max' => round($priced->last())] : null,
                // New-arrival pace needs scans spanning two weeks.
                'new_30d' => $spanDays >= 14 ? $fresh->count() : null,
                'new_weeks' => $spanDays >= 14 ? collect(range(3, 0))->map(fn (int $w) => $fresh->filter(fn (CompetitorProduct $p) => $p->first_seen_at->between(now()->subWeeks($w + 1), now()->subWeeks($w)))->count())->values()->all() : null,
                'cuts' => $cuts->count(),
                'cut_median' => $cuts->isNotEmpty() ? (int) round($cuts->map(fn (CompetitorProduct $p) => ((float) $p->previous_price - (float) $p->price) / (float) $p->previous_price * 100)->median()) : null,
                'signal_share' => $items->isNotEmpty() ? (int) round($items->filter(fn (CompetitorProduct $p) => $p->is_best_seller || ! empty($p->evidence['promotion']) || ! empty($p->evidence['published_sales']))->count() / $items->count() * 100) : null,
                'last_checked_at' => $c->last_checked_at?->toIso8601String(),
            ];
        })->sortByDesc('products')->values()->all();

        $marketMix = $mix($products, fn (CompetitorProduct $p) => FashionVocabulary::garmentFor($p->category, $p->name));

        return [
            'competitors' => $rows,
            'mine' => [
                'products' => $this->mine->count(),
                'mix' => $myMix,
                'classified_pct' => $classified($this->mine, fn (Product $p) => FashionVocabulary::garmentFor($p->category, $p->name)),
                'colors' => $myColors,
                'bands' => collect(array_keys($myMix))->mapWithKeys(fn (string $g) => [$g => $this->band($g)])->filter()->all(),
            ],
            'market' => [
                'products' => $products->count(),
                'mix' => $marketMix,
                'classified_pct' => $classified($products, fn (CompetitorProduct $p) => FashionVocabulary::garmentFor($p->category, $p->name)),
                'colors' => $colors($products),
                'missing' => array_values(array_diff(array_keys($marketMix), array_keys($myMix))),
                'colors_missing' => array_values(array_diff($colors($products), $this->mine->flatMap(fn (Product $p) => (array) ($p->features['colors'] ?? []))->unique()->all())),
            ],
        ];
    }
}
