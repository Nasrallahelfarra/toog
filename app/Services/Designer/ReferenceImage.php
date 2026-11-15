<?php

namespace App\Services\Designer;

use App\Models\CompetitorProduct;
use App\Models\SocialPost;
use App\Models\Store;
use App\Services\Market\PublicWebClient;
use App\Support\InlineImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The picture of the piece a "design like this" collection reproduces,
 * copied next to the collection so every redraw uses the same image.
 *
 * The piece is looked up from the store's own rows by kind and id; a URL
 * sent by the browser is never fetched. A post's stored copy is used
 * first; remote images go through the guarded public web client.
 */
class ReferenceImage
{
    public function __construct(private readonly PublicWebClient $web) {}

    /**
     * @param  array<string, mixed>  $inspiration  the brief's inspiration (kind, id)
     * @return array{path: string, brand: string|null}|null
     */
    public function resolve(Store $store, array $inspiration): ?array
    {
        $id = (int) ($inspiration['id'] ?? 0);
        [$stored, $url, $brand] = match ($inspiration['kind'] ?? null) {
            'competitor' => $this->product($store, $id),
            'social' => $this->post($store, $id),
            default => [null, null, null],
        };

        $bytes = $this->stored($stored) ?? $this->download($url);
        $jpeg = $bytes !== null ? InlineImage::jpeg($bytes, 1024) : null;
        if ($jpeg === null) {
            return null;
        }

        $path = "design-sources/{$store->id}/references/".Str::uuid().'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return ['path' => $path, 'brand' => $brand];
    }

    /**
     * @return array{0: null, 1: string|null, 2: string|null}
     */
    private function product(Store $store, int $id): array
    {
        $product = CompetitorProduct::query()->where('store_id', $store->id)->with('competitor:id,name')->find($id);

        return [null, $product?->image_url, $product?->competitor?->name];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function post(Store $store, int $id): array
    {
        $post = SocialPost::query()->where('store_id', $store->id)->with('competitor:id,name')->find($id);

        return [$post?->evidence['image_path'] ?? null, $post?->thumbnail_url, $post?->competitor?->name];
    }

    private function stored(?string $path): ?string
    {
        $disk = Storage::disk('public');

        return $path && $disk->exists($path) && $disk->size($path) <= InlineImage::MAX_BYTES ? (string) $disk->get($path) : null;
    }

    private function download(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $url = str_starts_with($url, '//') ? 'https:'.$url : $url;

        try {
            $response = $this->web->get($url, headers: ['Accept' => 'image/webp,image/jpeg,image/png;q=0.9,*/*;q=0.5']);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable) {
            // Blocked, unreachable or too large: the brief falls back to "inspired".
            return null;
        }
    }
}
