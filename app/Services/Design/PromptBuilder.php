<?php

namespace App\Services\Design;

use App\Models\DesignRequest;
use App\Models\Store;
use App\Support\Vocabulary;

/**
 * Writes the image-model prompt for one design variant: output framing,
 * product attributes, brand identity (FR-4.6), Saudi taste and cultural
 * rules, and — when a reference image is supplied — strict preservation
 * of the product's shape and print area (FR-4.2).
 */
class PromptBuilder
{
    private const FRAMING = [
        'product_photo' => 'a high-resolution studio product photograph on a soft, warm neutral backdrop, even diffused light',
        'print_file' => 'flat, print-ready artwork of the print/pattern only: front view, crisp clean edges, plain solid background, no mockup, no shadows, no perspective',
        'mockup_white' => 'a product mockup on a pure white (#FFFFFF) background, centred, with a soft natural contact shadow',
        'mockup_model' => 'an on-model mockup in modest Saudi styling, face cropped or out of frame, neutral studio background',
        'lifestyle' => 'a lifestyle scene in a warm, contemporary Saudi home (majlis or modern living space), natural window light',
        'social' => 'a social-media creative: bold but on-brand composition with clean negative space for a caption',
    ];

    /**
     * @param  array{key: string, colors: list<string>, pattern: string|null, material: string|null, style: string|null, placement: string}  $variant
     * @param  array{name?: string|null, category?: string|null, season?: string|null}  $product
     */
    public function build(DesignRequest $request, Store $store, array $variant, array $product, bool $hasSource): string
    {
        if ($request->type === DesignRequest::REFINEMENT) {
            return implode("\n", [
                'Retouch the supplied image, an existing design from this store, into '.(self::FRAMING[$request->output_type] ?? self::FRAMING['product_photo']).'.',
                'Work like a professional retoucher and apply ONLY these edits:',
                ...array_map(fn (string $line) => '- '.$line, array_filter(array_map('trim', explode("\n", $request->prompt)))),
                'Keep the product identity, silhouette, proportions, construction, pattern placement, brand marks and every detail not mentioned above exactly as they are.',
                'Saudi market: elegant, modest and culturally respectful. No added text, logos or watermarks. Photorealistic commercial e-commerce quality.',
            ]);
        }
        if ($request->product_recommendation_id) {
            // The recommendation redesigns the product; the photo stays as it
            // is unless the merchant picked another presentation in the studio.
            $photo = $request->output_type === 'product_photo'
                ? 'Keep the photograph itself exactly as it is: the same background, lighting, camera angle, framing, and the same model or mannequin, pose and styling.'
                : 'Present the redesigned product as '.(self::FRAMING[$request->output_type] ?? self::FRAMING['product_photo']).'.';

            return implode("\n", [
                'Redesign the product shown in the supplied reference image, the exact catalog product, by applying the following expert design recommendation, as reviewed by the merchant:',
                trim($request->prompt),
                'The recommendation changes the design of the product itself (its shape, structure and details). Apply it fully, even where it changes the silhouette, cut, neckline, sleeves, length or construction.',
                'Keep the brand marks and every design detail the recommendation does not mention.',
                $photo,
                'Saudi market: modest and culturally respectful. Do not add unrelated trends, motifs, text or watermarks. Photorealistic commercial e-commerce quality.',
            ]);
        }
        $brand = $store->brand_guide ?? [];
        $palette = collect($variant['colors'])
            ->map(fn ($key) => sprintf('%s %s', Vocabulary::label('colors', $key, 'en'), Vocabulary::colors()[$key]['hex'] ?? ''))
            ->implode(', ');

        $lines = [
            'Create '.(self::FRAMING[$request->output_type] ?? self::FRAMING['product_photo']).'.',
            'Product: '.trim(($product['name'] ?? $request->title ?? 'a new product').' — category: '.($product['category'] ?? $request->category ?? 'general')).'.',
            'Colour palette: '.$palette.'.',
            'Pattern: '.Vocabulary::label('patterns', $variant['pattern'] ?? 'solid', 'en').'; placement: '.$variant['placement'].'.',
        ];

        if ($variant['material']) {
            $lines[] = 'Material: '.Vocabulary::label('materials', $variant['material'], 'en').', with realistic texture and drape.';
        }

        if ($variant['style']) {
            $lines[] = 'Style: '.Vocabulary::label('styles', $variant['style'], 'en').'.';
        }

        if (! empty($product['season']) && $product['season'] !== 'all_year') {
            $lines[] = 'Occasion: '.Vocabulary::label('seasons', $product['season'], 'en').'.';
        }

        if (! empty($brand['colors']) || ! empty($brand['style_en'])) {
            $lines[] = 'Brand identity: '.trim(($brand['style_en'] ?? '').' Brand colours '.implode(' ', $brand['colors'] ?? [])).'.';
        }

        if ($hasSource) {
            $lines[] = 'Use the reference image as the exact product: keep its silhouette, proportions, seams, construction and print area unchanged; change only colours, pattern and print.';
        }

        $lines[] = 'Direction from the merchant: '.trim($request->prompt);
        $lines[] = 'Saudi market: elegant, modest and culturally respectful; no religious symbols used decoratively, no alcohol, no revealing styling, no incorrect Arabic text.';
        $lines[] = 'Photorealistic commercial e-commerce quality. No watermark, no logos, no added text.';

        return implode("\n", $lines);
    }
}
