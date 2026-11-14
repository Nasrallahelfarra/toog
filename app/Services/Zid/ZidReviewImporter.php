<?php

namespace App\Services\Zid;

use App\Models\CustomerReview;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Arr;

/**
 * Imports the store's own customer reviews from Zid (docs.zid.sa "List
 * Reviews", newest first, up to MAX_PAGES pages).
 *
 * A review keeps Zid's id, so a re-import updates it, and an edited
 * comment is analysed again. Spam and rating-only reviews are skipped and
 * no customer name is stored. Once Zid has answered, the store's seeded
 * sample reviews are removed: they were never written by its customers.
 */
class ZidReviewImporter
{
    public const MAX_PAGES = 20;

    public function __construct(private readonly ZidMapper $mapper) {}

    /**
     * @return array{fetched: int, created: int, updated: int, skipped: int}
     *
     * @throws ZidApiException
     * @throws ZidRateLimited
     */
    public function import(Store $store): array
    {
        $client = new ZidClient($store);
        $client->refreshIfNeeded();
        $catalog = ZidMapper::catalogKeys(Product::query()->where('store_id', $store->id)->whereNotNull('external_id')->pluck('id', 'external_id'));
        $stats = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        $seen = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $client->reviewPage($page);

            foreach ($result['items'] as $item) {
                $stats['fetched']++;
                $row = $this->row($item, $catalog);
                if ($row === null || isset($seen[$row['external_id']])) {
                    $stats['skipped']++;

                    continue;
                }
                $seen[$row['external_id']] = true;

                $review = CustomerReview::query()->firstOrNew(['store_id' => $store->id, 'source' => 'store', 'external_id' => $row['external_id']]);
                $edited = $review->exists && trim($review->text) !== $row['text'];
                $stats[$review->exists ? 'updated' : 'created']++;
                $review->fill([
                    ...$row,
                    ...($edited ? ['sentiment' => null, 'sentiment_score' => null, 'topics' => null, 'analyzed_at' => null] : []),
                ])->save();
            }

            if (! $result['has_next']) {
                break;
            }
        }

        CustomerReview::query()->where('store_id', $store->id)->whereNull('external_id')->delete();

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $catalog
     * @return array<string, mixed>|null
     */
    private function row(array $item, array $catalog): ?array
    {
        $id = ZidMapper::key($item['id'] ?? null);
        $text = trim((string) (is_string($item['comment'] ?? null) ? $item['comment'] : ''));
        $status = is_string($item['status'] ?? null) ? mb_strtolower($item['status']) : null;
        if ($id === null || $text === '' || $status === 'spam') {
            return null;
        }

        $product = is_array($item['product'] ?? null) ? $item['product'] : [];
        $name = $product['name'] ?? null;
        $name = is_array($name) ? ($name['ar'] ?? $name['en'] ?? Arr::first($name)) : $name;
        $productKey = ZidMapper::key($product['id'] ?? null);

        return [
            'external_id' => $id,
            'text' => mb_substr($text, 0, 5000),
            'rating' => is_numeric($item['rating'] ?? null) ? max(1, min(5, (int) $item['rating'])) : null,
            'language' => preg_match('/\p{Arabic}/u', $text) ? 'ar' : 'en',
            'product_name' => is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null,
            'product_id' => $productKey !== null ? ($catalog[$productKey] ?? null) : null,
            'reviewed_at' => $this->mapper->placedAt($item['created_at'] ?? null),
            'evidence' => array_filter([
                'status' => $status,
                'verified_purchase' => ($product['bought_this_item'] ?? false) === true ? true : null,
                'images' => count(array_filter((array) ($item['images'] ?? []))) ?: null,
                'replied' => ! empty($item['reply']) ? true : null,
            ], fn ($v) => $v !== null),
        ];
    }
}
