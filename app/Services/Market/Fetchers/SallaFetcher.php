<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Services\Market\CompetitorAnalysis;
use App\Services\Market\FeatureExtractor;
use App\Services\Market\PublicWebClient;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Public catalogs of Salla stores, read through the same public storefront
 * API the store's own pages call in the shopper's browser (identified by
 * the store id printed in the page). Brings each product's price, the
 * store's own "before" price when on sale, the purchase count the store
 * chooses to publish, membership of its "best sellers" category (the
 * store's claim) and whether the homepage features it.
 */
class SallaFetcher implements CompetitorFetcher
{
    private const API = 'https://api.salla.dev/store/v1/products';

    /** Catalog pages read per scan (the API returns 15 products a page). */
    private const PAGES = 8;

    /** Category slugs that mean "best sellers". */
    private const BEST = ['الأكثر-مبيع', 'الاكثر-مبيع', 'الأكثر-طلب', 'الاكثر-طلب', 'الأكثر-شعبية', 'best-seller', 'bestseller', 'best-selling', 'top-selling', 'most-popular'];

    public function __construct(
        private readonly FeatureExtractor $features,
        private readonly PublicWebClient $web,
        private readonly CompetitorAnalysis $analysis,
    ) {}

    public function supports(Competitor $competitor): bool
    {
        return $competitor->platform === 'salla' && filled($competitor->url);
    }

    /**
     * True when a storefront page is a Salla store.
     */
    public static function detect(string $html): bool
    {
        return str_contains($html, 'cdn.salla') && (bool) preg_match('/"store":\{"id":\d+/', $html);
    }

    public function fetch(Competitor $competitor, ?Closure $progress = null, ?string $homeHtml = null): FetchResult
    {
        $progress?->__invoke('rules', []);
        $home = rtrim((string) $competitor->url, '/');
        $origin = $this->web->absolute($home, '/');
        $robots = $this->web->get($origin.'robots.txt');
        if (! $robots->successful() && $robots->status() !== 404) {
            throw new RuntimeException('Could not verify the storefront crawl rules.');
        }
        if ($robots->successful() && ! $this->web->allowed($robots->body(), $home)) {
            return FetchResult::failed('The storefront robots rules disallow this page.', 'robots');
        }

        $progress?->__invoke('storefront', []);
        $sources = [];
        if ($homeHtml === null) {
            $response = $this->web->get($home);
            $sources[] = ['url' => $home, 'http_status' => $response->status()];
            if (! $response->successful()) {
                return FetchResult::failed('Storefront returned HTTP '.$response->status());
            }
            $homeHtml = $response->body();
        }
        if (! preg_match('/"store":\{"id":(\d+)/', $homeHtml, $m)) {
            return FetchResult::failed('This does not look like a Salla store: no store id on the page.', 'unsupported');
        }
        $storeId = $m[1];
        $headers = ['Store-Identifier' => $storeId, 'Accept' => 'application/json', 'Accept-Language' => str_contains($home, '/en') ? 'en' : 'ar'];
        $observedAt = now()->toIso8601String();
        $warnings = [];

        // Products the homepage shows, and the store's "best sellers" categories.
        preg_match_all('~/p(\d{5,})(?=["\'/?#])~', $homeHtml, $featured);
        $featured = array_flip(array_unique($featured[1]));
        preg_match_all('~href="([^"]+/c(\d+))"~u', $homeHtml, $links, PREG_SET_ORDER);
        $bestCategories = collect($links)
            ->filter(fn (array $l) => Str::contains(mb_strtolower(rawurldecode($l[1])), self::BEST))
            ->mapWithKeys(fn (array $l) => [$l[2] => rawurldecode($l[1])])
            ->all();

        $progress?->__invoke('products', []);
        $items = [];
        $url = self::API.'?per_page=15';
        $limited = false;
        for ($page = 0; $page < self::PAGES && $url; $page++) {
            $response = $this->web->get($url, 0, $headers);
            $sources[] = ['url' => $url, 'http_status' => $response->status()];
            if ($response->status() === 429) {
                $warnings[] = ['url' => $url, 'message' => 'The store rate-limited catalog access (HTTP 429). Remaining pages were skipped.'];
                break;
            }
            if (! $response->successful() || ! is_array($response->json('data'))) {
                if ($page === 0) {
                    return FetchResult::failed('The public Salla catalog is unavailable. HTTP '.$response->status());
                }
                break;
            }
            foreach ($response->json('data') as $item) {
                $items[(string) ($item['id'] ?? '')] = $item;
            }
            $progress?->__invoke('products', ['products' => count($items)]);
            $url = $response->json('cursor.next');
            $limited = (bool) $url;
        }

        $best = [];
        foreach ($bestCategories as $categoryId => $categoryUrl) {
            try {
                $response = $this->web->get(self::API.'?per_page=15&source=categories&source_value[]='.$categoryId, 0, $headers);
                $sources[] = ['url' => $categoryUrl, 'http_status' => $response->status()];
                foreach ((array) $response->json('data') as $item) {
                    $id = (string) ($item['id'] ?? '');
                    $best[$id] = ['url' => $categoryUrl, 'label' => 'best_seller_category', 'observed_at' => $observedAt];
                    $items[$id] ??= $item;
                }
            } catch (Throwable) {
                $warnings[] = ['url' => $categoryUrl, 'message' => 'Best-seller category could not be read completely.'];
            }
        }
        unset($items['']);
        if (! $items) {
            return FetchResult::failed('No readable public products found in this Salla store.');
        }

        $result = new FetchResult(FetchResult::OK);
        $progress?->__invoke('saving', ['products' => count($items)]);
        DB::transaction(function () use ($competitor, $items, $result, $observedAt, $best, $featured, $home): void {
            foreach ($items as $id => $item) {
                $id = (string) $id;
                if (empty($item['name'])) {
                    continue;
                }
                $onSale = ! empty($item['is_on_sale']) && is_numeric($item['sale_price'] ?? null);
                $price = $onSale ? (float) $item['sale_price'] : (is_numeric($item['price'] ?? null) ? (float) $item['price'] : null);
                $regular = is_numeric($item['regular_price'] ?? null) ? (float) $item['regular_price'] : null;
                $sold = ! empty($item['is_hidded_quantity']) ? null : (is_numeric($item['sold_quantity'] ?? null) ? (int) $item['sold_quantity'] : null);
                $category = is_array($item['category'] ?? null) ? ($item['category']['name'] ?? null) : null;
                $existing = CompetitorProduct::query()->where('competitor_id', $competitor->id)->where('external_id', $id)->first();
                $data = [
                    'store_id' => $competitor->store_id,
                    'name' => Str::limit((string) $item['name'], 250, ''),
                    'category' => $category ?: $competitor->category,
                    'price' => $price,
                    'image_url' => $item['image']['url'] ?? null,
                    'url' => $item['url'] ?? null,
                    'is_best_seller' => isset($best[$id]),
                    'last_seen_at' => now(),
                    'features' => $this->features->fromText(trim($item['name'].' '.$category.' '.($item['subtitle'] ?? ''))) ?: null,
                    'evidence' => [
                        'observed_at' => $observedAt, 'source_url' => $item['url'] ?? $home, 'source' => 'salla',
                        // Posts name products by this code ("كود المنتج: 11134").
                        'sku' => filled($item['sku'] ?? null) ? (string) $item['sku'] : null,
                        'currency' => $item['currency'] ?? 'SAR',
                        'best_seller' => $best[$id] ?? null,
                        'promotion' => isset($featured[$id]) ? ['url' => $home, 'label' => 'homepage_featured', 'observed_at' => $observedAt] : null,
                        // The purchase count the store chooses to show, verbatim.
                        'published_sales' => $sold && $sold > 0 ? [
                            'text' => str_contains($home, '/en') ? "Purchased {$sold} times" : 'تم شراؤه '.match (true) {
                                $sold === 1 => 'مرة واحدة',
                                $sold === 2 => 'مرتين',
                                $sold <= 10 => "{$sold} مرات",
                                default => "{$sold} مرة",
                            },
                            'url' => $item['url'] ?? $home, 'count' => $sold,
                        ] : null,
                        'on_sale' => $onSale,
                        'promotion_title' => filled($item['promotion_title'] ?? null) ? $item['promotion_title'] : null,
                        'out_of_stock' => (bool) ($item['is_out_of_stock'] ?? false),
                        'rating' => is_array($item['rating'] ?? null) ? ($item['rating']['stars'] ?? null) : null,
                    ],
                ];
                // The store's own "before" price while on sale is a price cut it shows.
                if ($onSale && $regular && $price !== null && $regular > $price) {
                    $data['previous_price'] = $regular;
                } elseif ($existing && $price !== null && $existing->price !== null && abs((float) $existing->price - $price) > 0.009) {
                    $data['previous_price'] = $existing->price;
                    $result->priceChanges++;
                }
                if ($existing) {
                    $existing->update($data);
                    $result->updated++;
                } else {
                    CompetitorProduct::query()->create([...$data, 'competitor_id' => $competitor->id, 'external_id' => $id, 'first_seen_at' => now()]);
                    $result->added++;
                }
            }
        });

        $progress?->__invoke('analysis', ['products' => count($items)]);
        $this->analysis->build($competitor, array_map('strval', array_keys($items)), [
            'observed_at' => $observedAt, 'home_url' => $home, 'catalog_url' => self::API,
            'sources' => $sources, 'warnings' => $warnings, 'limited' => $limited,
        ]);

        return $result;
    }
}
