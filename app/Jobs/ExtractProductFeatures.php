<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Ai\Ai;
use App\Support\Vocabulary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extracts a catalog product's attributes (FR-1.4): colours, pattern,
 * material, style, season, pattern complexity and typography — from its
 * image and name with Gemini, restricted to the vocabulary keys.
 *
 * Fallback (demo mode, budget reached, API error): match vocabulary labels
 * that appear in the product name, so nothing is ever invented.
 */
class ExtractProductFeatures implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Product $product, public ?int $userId = null) {}

    /**
     * @return bool whether the model (or demo mode) answered; false when the AI call failed
     */
    public function handle(Ai $ai): bool
    {
        // Queued copies are left over from the old 50-per-click batches. The
        // store-wide run (ExtractStoreFeatures) reads these products now.
        if ($this->job) {
            return false;
        }

        $result = $this->read($ai);
        if ($result['features'] !== []) {
            $this->product->features = array_merge($this->product->features ?? [], $result['features']);
            $this->product->save();
        }

        return $result['answered'];
    }

    /**
     * Read without writing, so a store run can save the product and its
     * progress together after checking that this worker still owns the step.
     *
     * @return array{features: array<string, mixed>, answered: bool}
     */
    public function read(Ai $ai): array
    {
        $product = $this->product;
        $result = $ai->json(
            operation: 'attributes',
            store: $product->store,
            system: 'You catalogue products for a Saudi e-commerce store. Describe the product using only the allowed keys. Use null when an attribute cannot be seen or inferred.',
            prompt: "Product name: {$product->name}\nCategory: ".($product->category ?? '—')."\nPrice (SAR): ".($product->price ?? '—'),
            schema: $this->schema(),
            fallback: fn () => $this->fromName($product->name),
            images: $this->image($product),
            userId: $this->userId,
        );

        $features = $this->clean($result->data);
        $answered = $result->fromModel || ! $ai->isLive();

        if ($features !== []) {
            $features['extracted_by'] = $result->fromModel ? 'ai' : 'name';
        }

        // Mark the product as read so the next run moves on even when the
        // image shows no pattern; a failed AI call leaves it for a retry.
        if ($answered) {
            $features['extracted_at'] = now()->toIso8601String();
        }

        return ['features' => $features, 'answered' => $answered];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $enum = fn (array $list) => ['type' => ['string', 'null'], 'enum' => [...array_keys($list), null]];

        return [
            'type' => 'object',
            'properties' => [
                'colors' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Vocabulary::colors())], 'maxItems' => 3],
                'pattern' => $enum(Vocabulary::patterns()),
                'material' => $enum(Vocabulary::materials()),
                'style' => $enum(Vocabulary::styles()),
                'season' => $enum(Vocabulary::seasons()),
                'pattern_complexity' => ['type' => ['string', 'null'], 'enum' => ['low', 'medium', 'high', null]],
                'typography' => ['type' => ['string', 'null'], 'enum' => ['none', 'arabic_calligraphy', 'modern_arabic', 'latin', null]],
            ],
            'required' => ['colors', 'pattern', 'material', 'style', 'season', 'pattern_complexity', 'typography'],
        ];
    }

    /**
     * Keep only valid vocabulary values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $valid = fn (?string $value, array $list) => $value !== null && array_key_exists($value, $list) ? $value : null;

        return array_filter([
            'colors' => array_values(array_intersect((array) ($data['colors'] ?? []), array_keys(Vocabulary::colors()))) ?: null,
            'pattern' => $valid($data['pattern'] ?? null, Vocabulary::patterns()),
            'material' => $valid($data['material'] ?? null, Vocabulary::materials()),
            'style' => $valid($data['style'] ?? null, Vocabulary::styles()),
            'season' => $valid($data['season'] ?? null, Vocabulary::seasons()),
            'pattern_complexity' => in_array($data['pattern_complexity'] ?? null, ['low', 'medium', 'high'], true) ? $data['pattern_complexity'] : null,
            'typography' => in_array($data['typography'] ?? null, ['none', 'arabic_calligraphy', 'modern_arabic', 'latin'], true) ? $data['typography'] : null,
        ]);
    }

    /**
     * Vocabulary labels (Arabic or English) found in the product name.
     *
     * @return array<string, mixed>
     */
    private function fromName(string $name): array
    {
        $haystack = mb_strtolower($name);
        $find = function (array $list, bool $many = false) use ($haystack) {
            $hits = [];
            foreach ($list as $key => $labels) {
                if (str_contains($haystack, mb_strtolower($labels['ar'])) || str_contains($haystack, mb_strtolower($labels['en']))) {
                    $hits[] = $key;
                }
            }

            return $many ? array_slice($hits, 0, 3) : ($hits[0] ?? null);
        };

        return [
            'colors' => $find(Vocabulary::colors(), true),
            'pattern' => $find(Vocabulary::patterns()),
            'material' => $find(Vocabulary::materials()),
            'style' => $find(Vocabulary::styles()),
            'season' => $find(Vocabulary::seasons()),
        ];
    }

    /**
     * @return list<array{mime: string, data: string}>
     */
    private function image(Product $product): array
    {
        $url = $product->image_url;

        if (! $url) {
            return [];
        }

        try {
            if (str_starts_with($url, 'http')) {
                $response = Http::timeout(15)->get($url);

                if ($response->failed() || strlen($response->body()) > 4_000_000) {
                    return [];
                }

                $mime = strtok((string) $response->header('Content-Type'), ';') ?: 'image/jpeg';

                return str_starts_with($mime, 'image/') ? [['mime' => $mime, 'data' => base64_encode($response->body())]] : [];
            }

            $disk = Storage::disk('public');

            return $disk->exists($url) ? [['mime' => $disk->mimeType($url) ?: 'image/jpeg', 'data' => base64_encode($disk->get($url))]] : [];
        } catch (Throwable) {
            return [];
        }
    }
}
