<?php

namespace App\Services\Market;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\Store;
use App\Support\FashionVocabulary;
use App\Support\Vocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The competitor radar: what earns competitors engagement right now, which
 * product or idea is behind it, what drives it, which ideas work at more
 * than one brand, and the competitor designs worth adding.
 *
 * Engagement is compared within each account only (a post against its own
 * account's usual), never across platforms or accounts, and it is interest,
 * not sales. Giveaways and collaborations are discounted; posts too young
 * to judge are marked early.
 */
class CompetitorRadar
{
    public const WINDOWS = [7, 30, 90];

    /** Half-life (days) of the recency weight for each window. */
    private const HALF_LIFE = [7 => 7, 30 => 14, 90 => 30];

    /** Weight of posts whose engagement is inflated or partial. */
    private const DISCOUNT = ['giveaway' => 0.5, 'collab' => 0.6, 'comments_only' => 0.8];

    private string $locale = 'ar';

    public function __construct(
        private readonly CompetitorSignals $signals,
        private readonly PostTagger $tagger,
        private readonly CompetitorAssortment $assortment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Store $store, int $days = 30, ?string $locale = null): array
    {
        $this->locale = $locale ?? app()->getLocale();
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;
        $competitors = Competitor::query()->where('store_id', $store->id)->where('is_active', true)->get();
        $active = $competitors->pluck('name', 'id');
        $social = $competitors->filter(fn (Competitor $c) => $c->isSocial());

        // The baseline pool is 90 days, whatever window is shown.
        $pool = SocialPost::query()->where('store_id', $store->id)->whereIn('competitor_id', $social->pluck('id'))
            ->whereNotNull('posted_at')->where('posted_at', '>=', now()->subDays(90))->get();
        $lifts = $this->signals->lifts($pool);
        $codes = $this->productCodes($store, $active);
        $posts = $pool->filter(fn (SocialPost $p) => $p->posted_at->gte(now()->subDays($days)))
            ->map(fn (SocialPost $p) => $this->post($p, $lifts[$p->id] ?? null, $active, $days, $codes))
            ->values();

        $trending = $posts->filter(fn (array $p) => $p['lift'] !== null && $p['lift'] >= 1.5 && ! $p['stale'])
            ->sortByDesc('score')->take(12)->values();
        $assortment = $this->assortment->build($store, $active, $posts->all(), $this->locale);
        $ideas = $this->ideas($posts, $assortment['recommendations']);
        $drivers = $this->drivers($posts);
        $coverage = $this->coverage($store, $competitors, $pool, $posts, $days);

        return [
            'days' => $days,
            'hero' => $this->hero($ideas, $trending, $assortment['recommendations']),
            'posts' => $trending->all(),
            'drivers' => $drivers,
            'ideas' => $ideas,
            'recommendations' => $assortment['recommendations'],
            'activity' => $assortment['activity'],
            'analysis' => $assortment['analysis'],
            'alerts' => $this->alerts($store, $posts, $ideas, $assortment, $coverage),
            'advice' => $this->advice($trending, $drivers, $ideas, $assortment['recommendations'], $coverage),
            'coverage' => $coverage,
        ];
    }

    /**
     * One post with its lift, tags, recency-weighted trend score and look.
     *
     * @param  array{lift: float|null, baseline: int, metric: string}|null  $lift
     * @param  Collection<int|string, string>  $active
     * @param  array<string, array<string, CompetitorProduct>>  $codes  brand → product code → product
     * @return array<string, mixed>
     */
    private function post(SocialPost $p, ?array $lift, Collection $active, int $days, array $codes): array
    {
        $tags = $this->tagger->tag($p);
        $name = (string) ($active[$p->competitor_id] ?? '—');
        $brand = CompetitorSignals::brand($name);
        // The exact product, when the post names its code and the same
        // brand's store carries it: the strongest link we can make.
        $product = collect($tags['codes'])->map(fn (string $code) => $codes[$brand][mb_strtolower($code)] ?? null)->filter()->first();
        if ($product) {
            $features = CompetitorSignals::features($product);
            // The product's own name is more precise than the caption.
            $tags['garment'] = FashionVocabulary::garmentFor($product->category, $product->name) ?? $tags['garment'];
            $tags['colors'] = $tags['colors'] ?: array_values(array_filter((array) ($features['colors'] ?? []), 'is_string'));
            $tags['pattern'] ??= $features['pattern'] ?? null;
            $tags['style'] ??= $features['style'] ?? null;
        }
        $age = max(0, (int) $p->posted_at->diffInDays(now()));
        $observed = isset($p->evidence['observed_at']) ? CarbonImmutable::parse($p->evidence['observed_at']) : null;
        // Engagement still growing when read: a lower bound, shown as early.
        $early = $observed && $p->posted_at->diffInHours($observed) < 72;
        $stale = $early && $observed->lt(now()->subDays(3));
        $l = $lift['lift'] ?? null;
        $n = $lift['baseline'] ?? 0;
        $q = collect($tags['flags'])->map(fn (string $f) => self::DISCOUNT[$f] ?? 1)->min() ?? 1;
        $score = $l !== null && $l > 0
            ? log(min($l, 8), 2) * ($n / ($n + 4)) * 0.5 ** ($age / self::HALF_LIFE[$days]) * $q
            : 0;
        $color = $tags['colors'][0] ?? null;

        return [
            'id' => $p->id,
            'competitor' => $name,
            'competitor_id' => $p->competitor_id,
            'brand' => $brand,
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price !== null ? (float) $product->price : null,
                'currency' => $product->evidence['currency'] ?? null,
                'image_url' => $product->image_url,
                'url' => $product->url,
                'best_seller' => $product->is_best_seller,
                'published_sales' => is_array($product->evidence['published_sales'] ?? null) ? ($product->evidence['published_sales']['text'] ?? null) : null,
                'competitor_id' => $product->competitor_id,
            ] : null,
            'platform' => $p->platform,
            'url' => $p->url,
            'image_url' => self::image($p),
            'caption' => mb_substr(trim((string) $p->caption), 0, 220),
            'posted_at' => $p->posted_at->toDateString(),
            'age_days' => $age,
            'likes' => $p->likes,
            'comments' => $p->comments,
            'shares' => $p->shares,
            'views' => $p->views,
            'lift' => $l,
            'baseline' => $n,
            'metric' => $lift['metric'] ?? null,
            'small_sample' => $n < CompetitorSignals::BASELINE,
            'tier' => match (true) {
                $l === null => null,
                $l >= 3 => 'exceptional',
                $l >= 2 => 'strong',
                $l >= 1.5 => 'above',
                default => null,
            },
            'early' => $early,
            'stale' => $stale,
            'score' => round($score, 3),
            'tags' => $tags,
            'look' => $tags['garment'] && $color ? implode('|', [$tags['garment'], $color, $tags['pattern'] ?? '']) : null,
            'prefill' => [
                'garment' => $tags['garment'],
                'colors' => array_slice($tags['colors'], 0, 3),
                'pattern' => $tags['pattern'],
                'style' => $tags['style'],
                'occasion' => $this->occasion($tags['occasion']),
                'notes' => $this->say('مستوحى من منشور حقق تفاعلًا فوق معتاد صاحبه: خذ الفكرة وغيّر عنصرين على الأقل.',
                    'Inspired by a post that beat its account\'s usual engagement: take the idea and change at least two elements.'),
                'inspiration' => ['kind' => 'social', 'id' => $p->id, 'label' => $name, 'image_url' => self::image($p)],
            ],
        ];
    }

    /**
     * Store products of every active competitor by product code (SKU, or
     * the store's own id), grouped by brand, so a post naming a code links
     * to that product.
     *
     * @param  Collection<int|string, string>  $active
     * @return array<string, array<string, CompetitorProduct>>
     */
    private function productCodes(Store $store, Collection $active): array
    {
        $out = [];
        CompetitorProduct::query()->where('store_id', $store->id)->whereIn('competitor_id', $active->keys())
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '>=', now()->subDays(45)))
            ->get()
            ->each(function (CompetitorProduct $cp) use (&$out, $active) {
                $brand = CompetitorSignals::brand((string) ($active[$cp->competitor_id] ?? ''));
                foreach (array_filter([$cp->evidence['sku'] ?? null, $cp->external_id]) as $code) {
                    $out[$brand][mb_strtolower((string) $code)] ??= $cp;
                }
            });

        return $out;
    }

    /**
     * What drives engagement: for each tag, within each account, the median
     * lift of posts with it over posts without it; the effect is the
     * weighted median across accounts. Small or split results are marked,
     * and an effect that vanishes within one format is "likely the format".
     *
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return list<array<string, mixed>>
     */
    private function drivers(Collection $posts): array
    {
        $measured = $posts->filter(fn (array $p) => $p['lift'] !== null && ! $p['small_sample'] && ! $p['stale']);
        if ($measured->count() < 5) {
            return [];
        }

        $tagsOf = function (array $p): array {
            $t = $p['tags'];
            $product = in_array('giveaway', $t['flags'], true) ? [] : array_filter([
                $t['garment'] ? "garment:{$t['garment']}" : null,
                ...array_map(fn ($c) => "color:{$c}", array_slice($t['colors'], 0, 2)),
                $t['pattern'] ? "pattern:{$t['pattern']}" : null,
                $t['occasion'] ? "occasion:{$t['occasion']}" : null,
            ]);

            return [...$product, "format:{$t['format']}", ...array_map(fn ($h) => "hook:{$h}", $t['hooks'])];
        };

        $keys = $measured->flatMap($tagsOf)->unique()->values();
        $out = [];

        foreach ($keys as $key) {
            $effect = $this->effect($measured, $key, $tagsOf);
            if (! $effect) {
                continue;
            }
            // Is it the product, or just that it was a video?
            if (! str_starts_with($key, 'format:')) {
                $videos = $measured->filter(fn (array $p) => $p['tags']['format'] === 'video');
                $within = $videos->count() >= 5 ? $this->effect($videos, $key, $tagsOf) : null;
                $effect['likely_format'] = $within !== null && abs(log($within['effect'])) < 0.1 && abs(log($effect['effect'])) >= 0.14;
            }
            [$dimension, $value] = explode(':', $key, 2);
            $out[] = ['dimension' => $dimension, 'key' => $value, ...$effect];
        }

        return collect($out)
            ->filter(fn (array $d) => $d['effect'] >= 1.15 || $d['effect'] <= 0.87)
            ->sortByDesc(fn (array $d) => abs(log($d['effect'])))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return array{effect: float, posts: int, brands: int, accounts: int, agree: int, conflicting: bool, likely_format: bool}|null
     */
    private function effect(Collection $posts, string $key, callable $tagsOf): ?array
    {
        $tagged = $posts->filter(fn (array $p) => in_array($key, $tagsOf($p), true));
        // A tag on (almost) every post explains nothing.
        if ($tagged->count() < 5 || $tagged->count() > $posts->count() * 0.8) {
            return null;
        }

        $ratios = [];
        foreach ($posts->groupBy('competitor_id') as $group) {
            $with = $group->filter(fn (array $p) => in_array($key, $tagsOf($p), true));
            $without = $group->reject(fn (array $p) => in_array($key, $tagsOf($p), true));
            if ($with->count() < 2 || $without->count() < 2) {
                continue;
            }
            $base = (float) $without->median('lift');
            if ($base > 0) {
                $ratios[] = ['r' => (float) $with->median('lift') / $base, 'w' => min($with->count(), $without->count()), 'brand' => $group->first()['brand']];
            }
        }
        $brands = count(array_unique(array_column($ratios, 'brand')));
        if ($ratios === [] || $brands < 2) {
            return null;
        }

        usort($ratios, fn ($a, $b) => $a['r'] <=> $b['r']);
        $half = array_sum(array_column($ratios, 'w')) / 2;
        $acc = 0;
        $effect = $ratios[0]['r'];
        foreach ($ratios as $r) {
            $acc += $r['w'];
            if ($acc >= $half) {
                $effect = $r['r'];
                break;
            }
        }
        $agree = count(array_filter($ratios, fn ($r) => ($r['r'] >= 1) === ($effect >= 1)));

        return [
            'effect' => round($effect, 2),
            'posts' => $tagged->count(),
            'brands' => $brands,
            'accounts' => count($ratios),
            'agree' => $agree,
            'conflicting' => $agree / count($ratios) < 2 / 3,
            'likely_format' => false,
        ];
    }

    /**
     * The same look (garment + colour + pattern) working at two or more
     * brands, from strong posts and store demand. One brand on two
     * platforms is a weaker echo.
     *
     * @param  Collection<int, array<string, mixed>>  $posts
     * @param  list<array<string, mixed>>  $recommendations
     * @return list<array<string, mixed>>
     */
    private function ideas(Collection $posts, array $recommendations): array
    {
        $entries = $posts->filter(fn (array $p) => $p['look'] && $p['lift'] !== null && $p['lift'] >= 1.5)
            ->map(fn (array $p) => ['look' => $p['look'], 'brand' => $p['brand'], 'platform' => $p['platform'], 'lift' => $p['lift'], 'image' => $p['image_url'], 'kind' => 'post', 'id' => $p['id']])
            ->concat(collect($recommendations)->map(fn (array $r) => ['look' => $r['look'], 'brand' => CompetitorSignals::brand($r['competitor']),
                'platform' => $r['platform'], 'lift' => null, 'image' => $r['image_url'], 'kind' => $r['kind'], 'id' => $r['id']]));

        return $entries->groupBy('look')
            ->map(function (Collection $group, string $look) use ($recommendations) {
                $brands = $group->pluck('brand')->unique();
                $platforms = $group->pluck('platform')->filter()->unique();
                if ($brands->count() < 2 && $platforms->count() < 2) {
                    return null;
                }
                [$garment, $color, $pattern] = array_pad(explode('|', $look), 3, '');
                $lifts = $group->pluck('lift')->filter();
                $rec = collect($recommendations)->firstWhere('look', $look);

                return [
                    'look' => $look,
                    'label' => trim(implode(' ', array_filter([
                        FashionVocabulary::label('garments', $garment, $this->locale),
                        Vocabulary::label('colors', $color, $this->locale),
                        $pattern && $pattern !== 'solid' ? Vocabulary::label('patterns', $pattern, $this->locale) : null,
                    ]))),
                    'garment' => $garment,
                    'brands' => $brands->count(),
                    'platforms' => $platforms->count(),
                    'cross_platform_only' => $brands->count() < 2,
                    'posts' => $group->where('kind', 'post')->count(),
                    'products' => $group->where('kind', '!=', 'post')->count(),
                    'avg_lift' => $lifts->isNotEmpty() ? round($lifts->avg(), 1) : null,
                    'images' => $group->pluck('image')->filter()->unique()->take(3)->values()->all(),
                    'in_catalog' => $rec ? $rec['novelty'] === 'close' : null,
                    'post_ids' => $group->where('kind', 'post')->pluck('id')->values()->all(),
                    // What "design like this" opens in the studio.
                    'inspire' => ($first = $group->first()) ? ($first['kind'] === 'post' ? 'post:' : 'product:').$first['id'] : null,
                    'prefill' => [
                        'garment' => $garment,
                        'colors' => [$color],
                        'pattern' => $pattern ?: null,
                        'notes' => $this->say('فكرة تنجح عند أكثر من منافس: صمّمها بروح متجرك.', 'An idea working at more than one competitor: design it in your own style.'),
                        'inspiration' => ['kind' => 'gap', 'id' => 'colors:'.$color, 'label' => '', 'image_url' => $group->pluck('image')->filter()->first()],
                    ],
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $i) => [$i['brands'], $i['avg_lift'] ?? 0])
            ->take(6)
            ->values()
            ->map(fn (array $i) => [...$i, 'prefill' => [...$i['prefill'], 'inspiration' => [...$i['prefill']['inspiration'], 'label' => $i['label']]]])
            ->all();
    }

    /**
     * The top answer: an idea at two or more brands, else the strongest
     * post on a full baseline, else the top design worth adding.
     *
     * @param  list<array<string, mixed>>  $ideas
     * @param  Collection<int, array<string, mixed>>  $trending
     * @param  list<array<string, mixed>>  $recommendations
     * @return array<string, mixed>|null
     */
    private function hero(array $ideas, Collection $trending, array $recommendations): ?array
    {
        $idea = collect($ideas)->first(fn (array $i) => $i['brands'] >= 2);
        if ($idea) {
            return ['kind' => 'idea', 'idea' => $idea];
        }
        $post = $trending->first(fn (array $p) => ! $p['small_sample'] && $p['lift'] >= 1.5);
        if ($post) {
            return ['kind' => 'post', 'post' => $post];
        }

        return $recommendations ? ['kind' => 'recommendation', 'recommendation' => $recommendations[0]] : null;
    }

    /**
     * @param  Collection<int, Competitor>  $competitors
     * @param  Collection<int, SocialPost>  $pool
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return array<string, mixed>
     */
    private function coverage(Store $store, Collection $competitors, Collection $pool, Collection $posts, int $days): array
    {
        $social = $competitors->filter(fn (Competitor $c) => $c->isSocial());
        $eligible = $posts->filter(fn (array $p) => $p['lift'] !== null);
        $brands = $eligible->pluck('brand')->unique()->count();
        $thin = $social->filter(fn (Competitor $c) => $pool->where('competitor_id', $c->id)->count() < CompetitorSignals::BASELINE)
            ->map(fn (Competitor $c) => ['id' => $c->id, 'name' => $c->name, 'posts' => $pool->where('competitor_id', $c->id)->count()])->values()->all();
        $lastSocial = $social->max('last_checked_at');
        $lastStore = $competitors->reject(fn (Competitor $c) => $c->isSocial())->max('last_checked_at');

        return [
            'days' => $days,
            'social_accounts' => $social->count(),
            'store_competitors' => $competitors->count() - $social->count(),
            'posts' => $posts->count(),
            'eligible' => $eligible->count(),
            'brands' => $brands,
            'confidence' => match (true) {
                $eligible->count() >= 10 && $brands >= 2 => 'high',
                $eligible->count() >= 5 => 'medium',
                default => 'low',
            },
            'thin_accounts' => $thin,
            'hidden_likes' => $pool->contains(fn (SocialPost $p) => ! empty($p->evidence['likes_hidden'])),
            'with_images' => $pool->filter(fn (SocialPost $p) => ! empty($p->evidence['image_path']))->count(),
            // Seeded posts carry no scan evidence.
            'demo' => $pool->isNotEmpty() && $pool->every(fn (SocialPost $p) => empty($p->evidence)),
            'apify' => filled(config('mojo.apify.token')),
            'last_social_scan' => $lastSocial ? CarbonImmutable::parse($lastSocial)->toIso8601String() : null,
            'last_store_scan' => $lastStore ? CarbonImmutable::parse($lastStore)->toIso8601String() : null,
            'wider' => $eligible->count() < 5 && $days < 90 ? ($days === 7 ? 30 : 90) : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $posts
     * @param  list<array<string, mixed>>  $ideas
     * @param  array<string, mixed>  $assortment
     * @param  array<string, mixed>  $coverage
     * @return list<array<string, mixed>>
     */
    private function alerts(Store $store, Collection $posts, array $ideas, array $assortment, array $coverage): array
    {
        $alerts = [];
        $add = function (string $type, string $severity, string $ar, string $en, array $extra = []) use (&$alerts) {
            $alerts[] = ['type' => $type, 'severity' => $severity, 'text' => $this->say($ar, $en), 'focus' => null, 'href' => null, 'image_url' => null, 'prefill' => null, ...$extra];
        };

        foreach ($posts->filter(fn (array $p) => $p['lift'] >= 3 && ! $p['small_sample'] && $p['age_days'] <= 7 && ! in_array('giveaway', $p['tags']['flags'], true)) as $p) {
            $add('exceptional_post', 'warning', "منشور لـ{$p['competitor']} تجاوز معتاده {$p['lift']}× خلال آخر 7 أيام.",
                "A {$p['competitor']} post reached {$p['lift']}× its usual within the last 7 days.",
                ['focus' => "post:{$p['id']}", 'image_url' => $p['image_url'], 'prefill' => $p['prefill']]);
        }
        foreach (array_filter($ideas, fn (array $i) => $i['brands'] >= 2) as $i) {
            $add('shared_idea', 'info', "«{$i['label']}» ينجح عند {$i['brands']} منافسين".($i['in_catalog'] === false || $i['in_catalog'] === null ? ' وليس في كتالوجك مثله.' : '.'),
                "“{$i['label']}” works at {$i['brands']} competitors".($i['in_catalog'] ? '.' : ' and is not in your catalog.'),
                ['image_url' => $i['images'][0] ?? null, 'prefill' => $i['prefill']]);
        }
        foreach (array_filter($assortment['recommendations'], fn (array $r) => $r['verb'] === 'add_now') as $r) {
            $add('add_design', 'info', "تصميم يستحق الإضافة: «{$r['name']}» لدى {$r['competitor']}.", "A design worth adding: “{$r['name']}” at {$r['competitor']}.",
                ['focus' => "product:{$r['id']}", 'image_url' => $r['image_url'], 'prefill' => $r['prefill']]);
        }

        // Moves on what the merchant sells best.
        $myTop = Product::query()->where('store_id', $store->id)->orderByDesc('units_sold')->limit(5)->get()
            ->map(fn (Product $p) => FashionVocabulary::garmentFor($p->category, $p->name))->filter()->unique()->all();
        foreach ($assortment['activity'] as $a) {
            $garment = FashionVocabulary::garmentFor($a['name']);
            if (($a['drop_pct'] ?? 0) >= 15 && in_array($garment, $myTop, true)) {
                $add('price_cut', 'warning', "خفّض {$a['competitor']} سعر «{$a['name']}» {$a['drop_pct']}% — من نفس نوع منتجاتك الأكثر مبيعًا.",
                    "{$a['competitor']} cut “{$a['name']}” by {$a['drop_pct']}% — the same garment as your best sellers.",
                    ['focus' => "product:{$a['id']}", 'image_url' => $a['image_url'], 'href' => $a['url']]);
            }
        }
        foreach ($assortment['analysis']['competitors'] as $c) {
            if (($c['new_30d'] ?? 0) >= 5 && array_intersect(array_keys($c['mix']), $myTop)) {
                $add('assortment_push', 'info', "أضاف {$c['name']} {$c['new_30d']} منتجات جديدة خلال 30 يومًا في فئات تبيعها.",
                    "{$c['name']} added {$c['new_30d']} new products in 30 days in categories you sell.", ['href' => "/store/competitors?competitor={$c['id']}"]);
            }
        }
        foreach ($posts->filter(fn (array $p) => $p['lift'] >= 1.5 && array_intersect(['restock', 'sold_out'], $p['tags']['hooks'])) as $p) {
            $add('demand_claim', 'info', "{$p['competitor']} يقول إن منتجًا «نفد» أو «رجع توفّره» في منشور فوق معتاده — ادعاء من الحساب نفسه.",
                "{$p['competitor']} says a product sold out or is back, in a post above its usual — the account's own claim.",
                ['focus' => "post:{$p['id']}", 'image_url' => $p['image_url']]);
        }
        if ($coverage['social_accounts'] > 0 && $coverage['last_social_scan'] && CarbonImmutable::parse($coverage['last_social_scan'])->lt(now()->subDays(3))) {
            $add('stale_social', 'warning', 'آخر فحص لحسابات التواصل قبل أكثر من 3 أيام؛ افحصها لتبقى القراءة حديثة.', 'Social accounts were last scanned over 3 days ago; rescan to keep this current.', ['href' => '/store/competitors?tab=accounts']);
        }
        if ($coverage['thin_accounts'] !== []) {
            $add('thin_accounts', 'info', count($coverage['thin_accounts']).' حسابات لديها أقل من 5 منشورات؛ لا نرتّبها حتى تكفي بياناتها.',
                count($coverage['thin_accounts']).' accounts have fewer than 5 posts; they are not ranked until there is enough data.', ['href' => '/store/competitors?tab=accounts']);
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];

        return collect($alerts)->groupBy('type')->flatMap(fn (Collection $g) => $g->take(2))
            ->sortBy(fn (array $a) => $rank[$a['severity']])->take(8)->values()->all();
    }

    /**
     * Up to five grounded recommendations for the page, from the strongest
     * finding of each section.
     *
     * @param  Collection<int, array<string, mixed>>  $trending
     * @param  list<array<string, mixed>>  $drivers
     * @param  list<array<string, mixed>>  $ideas
     * @param  list<array<string, mixed>>  $recommendations
     * @param  array<string, mixed>  $coverage
     * @return list<array<string, mixed>>
     */
    private function advice(Collection $trending, array $drivers, array $ideas, array $recommendations, array $coverage): array
    {
        $out = [];

        if ($r = $recommendations[0] ?? null) {
            $out[] = ['action' => 'add', 'title' => $this->say('أضف تصميمًا مثبتًا', 'Add a proven design'), 'body' => $r['text'],
                'focus' => "product:{$r['id']}", 'prefill' => $r['prefill'], 'image_url' => $r['image_url']];
        }
        if ($i = collect($ideas)->first(fn (array $x) => $x['brands'] >= 2)) {
            $out[] = ['action' => 'design', 'title' => $this->say("«{$i['label']}» فكرة مشتركة", "“{$i['label']}” is a shared idea"),
                'body' => $this->say("تنجح عند {$i['brands']} منافسين".($i['avg_lift'] ? " بمتوسط {$i['avg_lift']}× معتادهم" : '').'. صمّم نسختك بلمسة تميّزك.',
                    "It works at {$i['brands']} competitors".($i['avg_lift'] ? " at {$i['avg_lift']}× their usual on average" : '').'. Design your own take.'),
                'focus' => null, 'prefill' => $i['prefill'], 'image_url' => $i['images'][0] ?? null];
        }
        $lift = collect($drivers)->first(fn (array $d) => $d['effect'] > 1 && ! $d['conflicting'] && ! $d['likely_format']);
        if ($lift) {
            $label = $this->tagLabel($lift['dimension'], $lift['key']);
            $content = in_array($lift['dimension'], ['format', 'hook'], true);
            $out[] = ['action' => $content ? 'content' : 'design', 'title' => $this->say("«{$label}» يرفع التفاعل", "“{$label}” lifts engagement"),
                'body' => $this->say("منشوراتهم التي فيها «{$label}» تحقق {$lift['effect']}× ما دونها، عند {$lift['agree']} من {$lift['accounts']} حسابات. ".($content ? 'جرّبه في محتواك.' : 'فكّر في إدخاله بتصاميمك.'),
                    "Their posts with “{$label}” get {$lift['effect']}× the others, at {$lift['agree']} of {$lift['accounts']} accounts. ".($content ? 'Try it in your content.' : 'Consider it in your designs.')),
                'focus' => null, 'prefill' => null, 'image_url' => null];
        }
        $weak = collect($drivers)->first(fn (array $d) => $d['effect'] < 1 && ! $d['conflicting']);
        if ($weak) {
            $label = $this->tagLabel($weak['dimension'], $weak['key']);
            $out[] = ['action' => 'watch', 'title' => $this->say("«{$label}» يُضعف التفاعل عندهم", "“{$label}” weakens their engagement"),
                'body' => $this->say("منشوراتهم التي فيها «{$label}» تحقق {$weak['effect']}× ما دونها. لا تعتمد عليه وحده.", "Their posts with “{$label}” get {$weak['effect']}× the others. Don't rely on it alone."),
                'focus' => null, 'prefill' => null, 'image_url' => null];
        }
        if ($coverage['social_accounts'] === 0) {
            $out[] = ['action' => 'watch', 'title' => $this->say('أضف حسابات التواصل', 'Add social accounts'),
                'body' => $this->say('الرادار يرى متاجرهم فقط؛ أضف حسابات إنستغرام أو تيك توك لتعرف أي منشور وأي فكرة تجذب التفاعل.', 'The radar only sees their stores; add Instagram or TikTok accounts to learn which posts and ideas engage.'),
                'focus' => null, 'prefill' => null, 'image_url' => null, 'href' => '/store/competitors?tab=accounts'];
        } elseif ($p = $trending->first()) {
            $out[] = ['action' => 'design', 'title' => $this->say('ادرس أقوى منشور الآن', 'Study the strongest post now'),
                'body' => $this->say("منشور لـ{$p['competitor']} عند {$p['lift']}× معتاده. اقرأه بالذكاء الاصطناعي لتعرف المنتج ولماذا نجح.", "A {$p['competitor']} post at {$p['lift']}× its usual. Read it with AI to see the product and why it worked."),
                'focus' => "post:{$p['id']}", 'prefill' => $p['prefill'], 'image_url' => $p['image_url']];
        }

        return array_slice($out, 0, 5);
    }

    public function tagLabel(string $dimension, string $key): string
    {
        $hooks = [
            'offer' => ['عرض أو خصم', 'Offer or discount'], 'new_arrival' => ['وصل حديثًا', 'New arrival'], 'limited' => ['كمية محدودة', 'Limited'],
            'restock' => ['رجع توفّره', 'Back in stock'], 'sold_out' => ['نفد', 'Sold out'], 'styling' => ['تنسيق ولوك', 'Styling'],
            'try_on' => ['تجربة قياس', 'Try-on'], 'price_shown' => ['السعر ظاهر', 'Price shown'], 'details' => ['تفاصيل وخامة', 'Details & fabric'],
            'review' => ['رأي عميلة', 'Customer review'], 'collab' => ['تعاون', 'Collaboration'], 'giveaway' => ['مسابقة أو سحب', 'Giveaway'],
            'call_to_order' => ['دعوة للطلب', 'Call to order'],
        ];
        $formats = ['video' => ['فيديو', 'Video'], 'carousel' => ['ألبوم صور', 'Carousel'], 'image' => ['صورة', 'Image'], 'text' => ['نص', 'Text']];
        $occasions = ['graduation' => ['تخرج', 'Graduation'], 'summer' => ['صيف', 'Summer'], 'winter' => ['شتاء', 'Winter']];
        $i = $this->locale === 'ar' ? 0 : 1;

        return match ($dimension) {
            'hook' => $hooks[$key][$i] ?? $key,
            'format' => $formats[$key][$i] ?? $key,
            'garment' => (string) FashionVocabulary::label('garments', $key, $this->locale),
            'color' => (string) Vocabulary::label('colors', $key, $this->locale),
            'pattern' => (string) Vocabulary::label('patterns', $key, $this->locale),
            'occasion' => $occasions[$key][$i] ?? (FashionVocabulary::occasions()[$key][$this->locale === 'ar' ? 'ar' : 'en'] ?? (Vocabulary::label('seasons', $key, $this->locale) ?? $key)),
            default => $key,
        };
    }

    private function occasion(?string $key): ?string
    {
        if (! $key) {
            return null;
        }
        if (isset(FashionVocabulary::occasions()[$key])) {
            return $key;
        }

        return collect(FashionVocabulary::occasions())->search(fn (array $o) => $o['season'] === $key) ?: null;
    }

    /**
     * The copied post image (Instagram links expire), else the remote one.
     */
    public static function image(SocialPost $p): ?string
    {
        $path = $p->evidence['image_path'] ?? null;

        return $path ? Storage::disk('public')->url($path) : $p->thumbnail_url;
    }

    private function say(string $ar, string $en): string
    {
        return $this->locale === 'ar' ? $ar : $en;
    }
}
