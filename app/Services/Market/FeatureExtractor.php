<?php

namespace App\Services\Market;

use App\Models\CompetitorProduct;
use App\Models\Store;
use App\Services\Ai\Ai;
use App\Support\Vocabulary;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Turns competitor products into vocabulary features (colours, pattern,
 * material, style) so they can be compared with the store's catalog.
 *
 * Text matching on the title/tags runs first and costs nothing; product
 * images can then be read by Gemini (FR-2.5 visual analysis).
 */
class FeatureExtractor
{
    /**
     * Arabic and English words that map to vocabulary keys.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const WORDS = [
        'colors' => [
            'black' => ['أسود', 'اسود', 'سوداء', 'black'],
            'white' => ['أبيض', 'ابيض', 'بيضاء', 'white'],
            'ivory' => ['عاجي', 'ivory', 'cream', 'كريمي'],
            'beige' => ['بيج', 'نود', 'beige', 'nude'],
            'sand' => ['رملي', 'sand', 'camel', 'كاميل'],
            'gold' => ['ذهبي', 'gold', 'golden'],
            'brown' => ['بني', 'brown', 'chocolate', 'شوكولا'],
            'terracotta' => ['تيراكوتا', 'terracotta', 'rust'],
            'burgundy' => ['عنابي', 'burgundy', 'maroon', 'wine'],
            'dusty_pink' => ['وردي', 'pink', 'rose', 'blush'],
            'lavender' => ['لافندر', 'بنفسجي', 'lavender', 'lilac', 'purple'],
            'navy' => ['كحلي', 'navy'],
            'sky_blue' => ['سماوي', 'sky blue', 'baby blue', 'light blue'],
            'teal' => ['تركوازي', 'teal', 'turquoise'],
            'emerald' => ['زمردي', 'emerald'],
            'sage' => ['مريمي', 'مريمية', 'sage'],
            'olive' => ['زيتي', 'olive', 'khaki', 'خاكي'],
            'grey' => ['رمادي', 'grey', 'gray'],
            'red' => ['أحمر', 'احمر', 'red'],
            'mustard' => ['خردلي', 'mustard'],
        ],
        'patterns' => [
            'floral' => ['ورد', 'ورود', 'floral', 'flower'],
            'geometric' => ['هندسي', 'geometric'],
            'arabesque' => ['زخرف', 'arabesque', 'ornament'],
            'calligraphy' => ['خط عربي', 'الخط العربي', 'الثلث', 'calligraphy'],
            'stripes' => ['مخطط', 'خطوط', 'stripe'],
            'abstract' => ['تجريدي', 'abstract'],
            'embroidery' => ['تطريز', 'مطرز', 'embroider'],
            'sadu' => ['سدو', 'sadu'],
            'solid' => ['سادة', 'plain', 'solid'],
        ],
        'materials' => [
            'crepe' => ['كريب', 'crepe'],
            'chiffon' => ['شيفون', 'chiffon'],
            'linen' => ['كتان', 'linen'],
            'cotton' => ['قطن', 'cotton'],
            'silk' => ['حرير', 'silk', 'satin', 'ساتان'],
            'velvet' => ['مخمل', 'velvet'],
            'leather' => ['جلد', 'leather'],
            'ceramic' => ['سيراميك', 'ceramic', 'porcelain'],
            'wood' => ['خشب', 'wood'],
            'metal' => ['معدن', 'metal', 'brass'],
            'glass' => ['زجاج', 'glass'],
        ],
        'styles' => [
            'luxury' => ['فاخر', 'فخم', 'luxury', 'premium'],
            'minimal' => ['بسيط', 'minimal', 'basic'],
            'modern' => ['عصري', 'modern'],
            'classic' => ['كلاسيك', 'classic'],
            'heritage' => ['تراث', 'heritage', 'traditional'],
            'sporty' => ['رياضي', 'sport'],
            'bohemian' => ['بوهيمي', 'boho'],
            'playful' => ['مرح', 'playful', 'fun'],
        ],
        'seasons' => [
            'ramadan' => ['رمضان', 'ramadan'],
            'eid_fitr' => ['عيد', 'eid'],
            'national_day' => ['اليوم الوطني', 'national day'],
            'founding_day' => ['التأسيس', 'founding day'],
            'summer' => ['صيف', 'summer'],
            'winter' => ['شتاء', 'شتوي', 'winter'],
            'white_friday' => ['الجمعة البيضاء', 'white friday', 'black friday'],
            'back_to_school' => ['مدارس', 'school'],
            'wedding' => ['عرس', 'زفاف', 'wedding'],
        ],
    ];

    public function __construct(private readonly Ai $ai) {}

    /**
     * Features from free text (title, tags, product type).
     *
     * @return array<string, mixed>
     */
    public function fromText(string $text): array
    {
        $text = mb_strtolower($text);
        $match = fn (string $group) => collect(self::WORDS[$group])
            ->filter(fn (array $words) => collect($words)->contains(fn ($w) => $this->mentions($text, mb_strtolower($w))))
            ->keys();

        return array_filter([
            'colors' => $match('colors')->take(3)->values()->all(),
            'pattern' => $match('patterns')->first(),
            'material' => $match('materials')->first(),
            'style' => $match('styles')->first(),
            'season' => $match('seasons')->first(),
        ]);
    }

    /**
     * Arabic words match inside longer words (attached prefixes such as
     * "ال" / "و"); English words must start a word, and short ones must
     * also end it ("red" must not match "embroidered").
     */
    private function mentions(string $text, string $word): bool
    {
        if (preg_match('/\p{Arabic}/u', $word)) {
            return str_contains($text, $word);
        }

        $pattern = '/\b'.preg_quote($word, '/').(mb_strlen($word) <= 4 ? '\b' : '').'/u';

        return (bool) preg_match($pattern, $text);
    }

    /**
     * Fill missing features of competitor products from their images with
     * Gemini; falls back to text matching (demo mode, no image, failure).
     *
     * @return int number of products updated
     */
    public function enrich(int $storeId, int $limit = 10): int
    {
        $products = CompetitorProduct::query()
            ->where('store_id', $storeId)
            ->where(fn ($q) => $q->whereNull('features')->orWhere('features', '[]')->orWhere('features', '{}'))
            ->latest('first_seen_at')
            ->limit($limit)
            ->get();

        $store = Store::query()->find($storeId);

        foreach ($products as $product) {
            $fallback = fn () => $this->fromText($product->name.' '.($product->category ?? ''));
            $image = $this->download($product->image_url);

            $features = $image && $this->ai->isLive()
                ? $this->ai->json(
                    operation: 'trend_vision',
                    store: $store,
                    system: 'You label e-commerce product photos for a Saudi market trend tracker. Use only the allowed values.',
                    prompt: "Product title: {$product->name}\nDescribe the product's dominant colours (max 3), surface pattern, material and style.",
                    schema: $this->schema(),
                    fallback: $fallback,
                    images: [$image],
                )->data
                : $fallback();

            $product->update(['features' => array_filter($features) ?: ['colors' => []]]);
        }

        return $products->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $enum = fn (array $list) => ['type' => 'string', 'enum' => array_keys($list)];

        return [
            'type' => 'object',
            'properties' => [
                'colors' => ['type' => 'array', 'items' => $enum(Vocabulary::colors())],
                'pattern' => $enum(Vocabulary::patterns()),
                'material' => $enum(Vocabulary::materials()),
                'style' => $enum(Vocabulary::styles()),
            ],
            'required' => ['colors', 'pattern', 'style'],
        ];
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    private function download(?string $url): ?array
    {
        if (! $url || ! str_starts_with($url, 'http')) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);

            if (! $response->successful() || strlen($response->body()) > 3_000_000) {
                return null;
            }

            $mime = strtok((string) $response->header('Content-Type'), ';') ?: 'image/jpeg';

            return str_starts_with($mime, 'image/') ? ['mime' => $mime, 'data' => base64_encode($response->body())] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
