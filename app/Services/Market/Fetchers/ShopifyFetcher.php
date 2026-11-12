<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Services\Market\CompetitorAnalysis;
use App\Services\Market\FeatureExtractor;
use App\Services\Market\PublicWebClient;
use App\Services\Market\StorefrontParser;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Public Shopify catalogs plus a bounded structured-data crawl for other sites. */
class ShopifyFetcher implements CompetitorFetcher
{
    public function __construct(
        private readonly FeatureExtractor $features,
        private readonly PublicWebClient $web,
        private readonly StorefrontParser $parser,
        private readonly CompetitorAnalysis $analysis,
        private readonly SallaFetcher $salla,
    ) {}

    public function supports(Competitor $competitor): bool
    {
        return in_array($competitor->platform, Competitor::STORE_PLATFORMS, true) && filled($competitor->url);
    }

    public function fetch(Competitor $competitor, ?Closure $progress = null): FetchResult
    {
        $progress?->__invoke('rules', []);
        $home = rtrim((string) $competitor->url, '/');
        $homePath = parse_url($home, PHP_URL_PATH) ?: '';
        $isHomepage = $homePath === '' || (bool) preg_match('~^/[a-z]{2}(?:-[a-z]{2})?$~i', $homePath);
        $origin = $this->web->absolute($home, '/');
        $robotsResponse = $this->web->get($origin.'robots.txt');
        if (! $robotsResponse->successful() && $robotsResponse->status() !== 404) {
            throw new RuntimeException('Could not verify the storefront crawl rules.');
        }
        $robots = $robotsResponse->successful() ? $robotsResponse->body() : '';
        $stage = 'storefront';
        $progress?->__invoke($stage, []);
        $sources = $warnings = [];
        $rateLimited = false;
        $read = function (string $url) use ($robots, &$sources, &$rateLimited, &$stage, $progress) {
            if ($rateLimited) {
                throw new RuntimeException('The store rate-limited this scan. Further requests were skipped.');
            }
            if (! $this->web->allowed($robots, $url)) {
                throw new RuntimeException("The storefront robots rules disallow this page: {$url}");
            }
            $response = $this->web->get($url);
            $rateLimited = $response->status() === 429;
            $sources[] = ['url' => $url, 'http_status' => $response->status()];
            $progress?->__invoke($stage, ['pages' => count($sources)]);

            return $response;
        };
        $homeResponse = $read($home);
        if (! $homeResponse->successful()) {
            return FetchResult::failed('Storefront returned HTTP '.$homeResponse->status());
        }
        // A store saved as a plain website that turns out to be on Salla is
        // read through Salla's public catalog, and remembered as Salla.
        if ($competitor->platform !== 'shopify' && SallaFetcher::detect($homeResponse->body())) {
            $competitor->update(['platform' => 'salla']);

            return $this->salla->fetch($competitor, $progress, $homeResponse->body());
        }
        $page = $this->parser->parse($homeResponse->body(), $home);
        $stage = 'products';
        $progress?->__invoke($stage, []);
        $observedAt = now()->toIso8601String();
        $best = $items = [];
        $catalogUrl = $home;
        $limited = false;

        if ($page['shopify'] || $competitor->platform === 'shopify') {
            $path = parse_url($home, PHP_URL_PATH) ?: '';
            $locale = preg_match('~^/([a-z]{2}(?:-[a-z]{2})?)(?:/|$)~i', $path, $match) ? '/'.$match[1] : '';
            $base = rtrim($origin, '/').$locale;
            $catalogUrl = $base.'/products.json?limit=100';
            $feed = $read($catalogUrl);
            if (! $feed->successful() || ! is_array($feed->json('products'))) {
                return FetchResult::failed('The public catalog is unavailable. HTTP '.$feed->status());
            }
            $items = array_slice($feed->json('products'), 0, 100);
            $progress?->__invoke($stage, ['products' => count($items)]);
            $limited = count($items) >= 100;
            foreach ($items as &$item) {
                $item['url'] = $base.'/products/'.rawurlencode($item['handle']);
                $item['source_url'] = $catalogUrl;
            }
            unset($item);
            foreach ($page['best_collections'] as $collectionUrl => $label) {
                try {
                    $collection = $read($collectionUrl);
                    if (! $collection->successful()) {
                        throw new RuntimeException('HTTP '.$collection->status());
                    }
                    $collectionPage = $this->parser->parse($collection->body(), $collectionUrl);
                    foreach ($collectionPage['product_links'] as $handle => $productUrl) {
                        $best[$handle] = ['url' => $collectionUrl, 'label' => $label, 'observed_at' => $observedAt];
                    }
                    $collectionFeed = $read(strtok($collectionUrl, '?').'/products.json?limit=24');
                    if ($collectionFeed->status() === 429) {
                        $warnings[] = ['url' => $collectionUrl, 'message' => 'The store rate-limited catalog access (HTTP 429). Remaining collection requests were skipped.'];
                        break;
                    }
                    if (! $collectionFeed->successful() || ! is_array($collectionFeed->json('products'))) {
                        throw new RuntimeException('The collection catalog could not be read.');
                    }
                    foreach (array_slice($collectionFeed->json('products'), 0, 24) as $item) {
                        $item['url'] = $base.'/products/'.rawurlencode($item['handle']);
                        $item['source_url'] = $collectionUrl;
                        $items[] = $item;
                    }
                } catch (Throwable $e) {
                    $warnings[] = ['url' => $collectionUrl, 'message' => 'Best-seller collection could not be read completely.'];
                }
            }
        } else {
            $items = $page['products'];
            foreach (array_slice($page['product_links'], 0, 4) as $productUrl) {
                try {
                    $productPage = $read($productUrl);
                    if ($productPage->successful()) {
                        $items = array_merge($items, $this->parser->parse($productPage->body(), $productUrl)['products']);
                    }
                } catch (Throwable) {
                    $warnings[] = ['url' => $productUrl, 'message' => 'Product page could not be read.'];
                }
            }
            $limited = true;
            $items = array_slice($items, 0, 100);
        }
        if (! $items) {
            return FetchResult::failed('No readable public products found. This storefront may require a separate adapter.');
        }

        $result = new FetchResult(FetchResult::OK);
        $seen = [];
        $progress?->__invoke('saving', ['products' => count(array_unique(array_column($items, 'id')))]);
        DB::transaction(function () use ($competitor, $items, &$seen, $result, $observedAt, $best, $page, $home, $isHomepage): void {
            foreach ($items as $item) {
                $id = (string) ($item['id'] ?? '');
                if ($id === '' || isset($seen[$id]) || empty($item['title'])) {
                    continue;
                }
                $seen[$id] = true;
                $variants = is_array($item['variants'] ?? null) ? $item['variants'] : [];
                $price = collect($variants)->pluck('price')->filter(fn ($p) => is_numeric($p) && $p >= 0)->map(fn ($p) => (float) $p)->min();
                $handle = $item['handle'] ?? rawurldecode(basename(parse_url($item['url'], PHP_URL_PATH) ?: ''));
                $existing = CompetitorProduct::query()->where('competitor_id', $competitor->id)->where('external_id', $id)->first();
                $tags = is_array($item['tags'] ?? null) ? implode(' ', $item['tags']) : (string) ($item['tags'] ?? '');
                $description = Str::limit(trim(strip_tags($item['body_html'] ?? '')), 1500, '');
                $data = [
                    'store_id' => $competitor->store_id,
                    'name' => Str::limit($item['title'], 250, ''),
                    'category' => ($item['product_type'] ?? null) ?: $competitor->category,
                    'price' => $price,
                    'image_url' => $item['images'][0]['src'] ?? $item['image_url'] ?? null,
                    'url' => $item['url'],
                    'is_best_seller' => isset($best[$handle]),
                    'last_seen_at' => now(),
                    'features' => $this->features->fromText($item['title'].' '.$tags.' '.$description) ?: null,
                    'evidence' => [
                        'observed_at' => $observedAt, 'source_url' => $item['source_url'] ?? $item['url'],
                        'currency' => $item['currency'] ?? $page['currency'], 'description' => $description,
                        'best_seller' => $best[$handle] ?? null,
                        'promotion' => $isHomepage && isset($page['product_links'][$handle]) ? ['url' => $home, 'label' => 'homepage_featured', 'observed_at' => $observedAt] : null,
                        'published_sales' => $item['published_sales'] ?? null,
                    ],
                ];
                if ($existing) {
                    if ($price !== null && $existing->price !== null && abs((float) $existing->price - $price) > 0.009) {
                        $data['previous_price'] = $existing->price;
                        $result->priceChanges++;
                    }
                    $existing->update($data);
                    $result->updated++;
                } else {
                    CompetitorProduct::query()->create([...$data, 'competitor_id' => $competitor->id, 'external_id' => $id, 'first_seen_at' => now()]);
                    $result->added++;
                }
            }
        });
        if (! $seen) {
            return FetchResult::failed('No readable public products found. This storefront may require a separate adapter.');
        }
        $progress?->__invoke('analysis', ['products' => count($seen)]);
        $this->analysis->build($competitor, array_keys($seen), [
            'observed_at' => $observedAt, 'home_url' => $home, 'catalog_url' => $catalogUrl,
            'sources' => $sources, 'warnings' => $warnings, 'limited' => $limited,
        ]);

        return $result;
    }
}
