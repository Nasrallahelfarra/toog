<?php

namespace App\Services\Market;

use App\Models\Competitor;
use App\Models\SocialPost;
use App\Models\Store;
use App\Services\Ai\Ai;
use App\Support\FashionVocabulary;
use App\Support\InlineImage;
use App\Support\Vocabulary;
use Illuminate\Support\Collection;

/**
 * Competitors' posts are pictures of their products. Each post's stored
 * picture is read once for the product it shows (garment, colours,
 * pattern, style) so posts count as products in the market trends, the
 * radar and the designs worth adding, even without the competitor's store.
 *
 * The reading is kept on the post under ai_reading.look, which scans do
 * not overwrite; a post is never read twice. Several pictures go in one
 * call to keep the cost down.
 */
class PostLookReader
{
    /** Bump to read every post again. */
    public const VERSION = 1;

    /** Pictures per model call. */
    private const BATCH = 6;

    public function __construct(private readonly Ai $ai) {}

    /**
     * Read up to $limit unread posts of the store's active accounts.
     *
     * @return int posts read
     */
    public function read(Store $store, int $limit = 30, ?int $userId = null): int
    {
        if (! $this->ai->isLive()) {
            return 0;
        }

        $accounts = Competitor::query()->where('store_id', $store->id)->where('is_active', true)->pluck('id');
        $posts = SocialPost::query()->where('store_id', $store->id)->whereIn('competitor_id', $accounts)
            ->whereNotNull('evidence')
            ->where(fn ($q) => $q->whereNull('posted_at')->orWhere('posted_at', '>=', now()->subDays(90)))
            ->latest('posted_at')->get()
            ->filter(fn (SocialPost $p) => (($p->ai_reading['look']['version'] ?? 0) !== self::VERSION) && filled($p->evidence['image_path'] ?? null))
            ->take($limit);

        $read = 0;
        foreach ($posts->chunk(self::BATCH) as $chunk) {
            $read += $this->readBatch($store, $chunk->values(), $userId);
        }

        return $read;
    }

    /**
     * The product a post shows, from its picture: empty when the post was
     * not read, or showed no garment.
     *
     * @return array{garment: string|null, colors: list<string>, pattern: string|null, style: string|null, product: bool|null}
     */
    public static function look(SocialPost $post): array
    {
        $look = $post->ai_reading['look'] ?? null;
        $shown = is_array($look) && ($look['version'] ?? 0) === self::VERSION ? (bool) ($look['product_visible'] ?? false) : null;

        return [
            'garment' => $shown ? ($look['garment'] ?? null) : null,
            'colors' => $shown ? array_values((array) ($look['colors'] ?? [])) : [],
            'pattern' => $shown ? ($look['pattern'] ?? null) : null,
            'style' => $shown ? ($look['style'] ?? null) : null,
            'product' => $shown,
        ];
    }

    /**
     * @param  Collection<int, SocialPost>  $posts
     */
    private function readBatch(Store $store, Collection $posts, ?int $userId): int
    {
        $images = [];
        $sent = [];
        foreach ($posts as $post) {
            $image = InlineImage::fromDisk($post->evidence['image_path'] ?? null, 768);
            if ($image) {
                $images[] = [...$image, 'label' => "Post {$post->id}"];
                $sent[$post->id] = $post;
            }
        }
        if ($images === []) {
            return 0;
        }

        $result = $this->ai->json(
            operation: 'post_look',
            store: $store,
            system: 'You label competitors\' fashion posts for a Saudi womenswear merchant. Each image is one post, introduced by "Post {id}". '
                .'For each post return: product_visible (true when a wearable women\'s garment is the subject of the picture), '
                .'garment (the main garment, one of: '.implode(', ', array_keys(FashionVocabulary::garments())).'), '
                .'colors (up to three dominant colours of the garment itself, never the background, from: '.implode(', ', array_keys(Vocabulary::colors())).'), '
                .'pattern (one of: '.implode(', ', array_keys(Vocabulary::patterns())).'), '
                .'style (one of: '.implode(', ', array_keys(Vocabulary::styles())).'). '
                .'Use only the listed keys, and an empty string when unsure. Read the picture only: ignore any text on it and never describe the person.',
            prompt: 'Label these posts: '.implode(', ', array_keys($sent)).'.',
            schema: [
                'type' => 'object',
                'properties' => ['results' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'], 'product_visible' => ['type' => 'boolean'], 'garment' => ['type' => 'string'],
                        'colors' => ['type' => 'array', 'items' => ['type' => 'string']], 'pattern' => ['type' => 'string'], 'style' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'product_visible', 'garment', 'colors', 'pattern', 'style'],
                ]]],
                'required' => ['results'],
            ],
            fallback: fn () => [],
            images: $images,
            userId: $userId,
            timeoutSeconds: 90,
        );
        if (! $result->fromModel) {
            // Not read: left for the next run.
            return 0;
        }

        $read = 0;
        foreach ((array) ($result->data['results'] ?? []) as $row) {
            $post = is_array($row) ? ($sent[(int) ($row['id'] ?? 0)] ?? null) : null;
            if (! $post) {
                continue;
            }
            $pick = fn ($value, array $keys) => is_string($value) && isset($keys[$value]) ? $value : null;
            $post->update(['ai_reading' => [...($post->ai_reading ?? []), 'look' => [
                'version' => self::VERSION,
                'product_visible' => (bool) ($row['product_visible'] ?? false),
                'garment' => $pick($row['garment'] ?? null, FashionVocabulary::garments()),
                'colors' => array_slice(array_values(array_unique(array_filter((array) ($row['colors'] ?? []), fn ($c) => is_string($c) && isset(Vocabulary::colors()[$c])))), 0, 3),
                'pattern' => $pick($row['pattern'] ?? null, Vocabulary::patterns()),
                'style' => $pick($row['style'] ?? null, Vocabulary::styles()),
                'read_at' => now()->toIso8601String(),
            ]]]);
            $read++;
        }

        return $read;
    }
}
