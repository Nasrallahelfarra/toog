<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Services\Market\FeatureExtractor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shopify storefronts publish their catalog at /products.json. This reads
 * that public feed (FR-2.2): new products, prices and price changes, plus
 * best sellers when the store exposes the best-selling sort.
 */
class ShopifyFetcher implements CompetitorFetcher
{
    public function __construct(private readonly FeatureExtractor $features) {}

    public function supports(Competitor $competitor): bool
    {
        return $competitor->platform === 'shopify' && filled($competitor->url);
    }

    public function fetch(Competitor $competitor): FetchResult
    {
        $base = $this->baseUrl((string) $competitor->url);

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'MojoAI-MarketMonitor/1.0 (+public catalog)'])
                ->acceptJson()
                ->get("{$base}/products.json", ['limit' => 250]);
        } catch (ConnectionException $e) {
            return FetchResult::failed($e->getMessage(), 'unreachable');
        }

        if (! $response->successful() || ! is_array($response->json('products'))) {
            return FetchResult::failed("HTTP {$response->status()} from {$base}/products.json");
        }

        $bestSellers = $this->bestSellerIds($base);
        $result = new FetchResult(FetchResult::OK);

        foreach ($response->json('products') as $item) {
            $prices = collect($item['variants'] ?? [])->pluck('price')->filter()->map(fn ($p) => (float) $p);
            $price = $prices->isEmpty() ? null : $prices->min();

            $existing = CompetitorProduct::query()
                ->where('competitor_id', $competitor->id)
                ->where('external_id', (string) $item['id'])
                ->first();

            $data = [
                'store_id' => $competitor->store_id,
                'name' => Str::limit((string) ($item['title'] ?? ''), 250, ''),
                'category' => $item['product_type'] ?: $competitor->category,
                'price' => $price,
                'image_url' => $item['images'][0]['src'] ?? null,
                'url' => isset($item['handle']) ? "{$base}/products/{$item['handle']}" : null,
                'is_best_seller' => in_array($item['id'], $bestSellers, true),
                'last_seen_at' => now(),
            ];

            if ($existing) {
                if ($price !== null && $existing->price !== null && abs((float) $existing->price - $price) > 0.009) {
                    $data['previous_price'] = $existing->price;
                    $result->priceChanges++;
                }

                $existing->update($data);
                $result->updated++;

                continue;
            }

            $tags = is_array($item['tags'] ?? null) ? implode(' ', $item['tags']) : (string) ($item['tags'] ?? '');

            CompetitorProduct::query()->create([
                ...$data,
                'competitor_id' => $competitor->id,
                'external_id' => (string) $item['id'],
                'features' => $this->features->fromText("{$data['name']} {$tags} {$data['category']}") ?: null,
                // When the product was published, so "new products" means new
                // on their store, not new to us.
                'first_seen_at' => isset($item['published_at']) ? Carbon::parse($item['published_at']) : now(),
            ]);
            $result->added++;
        }

        return $result;
    }

    /**
     * Ids of the top sellers, when the storefront allows sorting by them.
     *
     * @return list<int>
     */
    private function bestSellerIds(string $base): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()
                ->get("{$base}/collections/all/products.json", ['sort_by' => 'best-selling', 'limit' => 10]);

            return $response->successful()
                ? collect($response->json('products') ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];
        } catch (ConnectionException) {
            return [];
        }
    }

    private function baseUrl(string $url): string
    {
        $url = str_starts_with($url, 'http') ? $url : "https://{$url}";
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? $url);
    }
}
