<?php

namespace App\Services\Design;

use App\Support\Vocabulary;

/**
 * Professional edits a merchant can apply to an existing design. Each key
 * maps to one precise retouching instruction for the image model; the
 * merchant's own words and an optional recolour are added to them.
 */
class Refinements
{
    public const PRESETS = [
        'clean_background' => 'Replace the background with a clean, seamless studio backdrop in a soft warm neutral tone; leave the product itself untouched.',
        'studio_light' => 'Relight it like a professional product shoot: soft key light, gentle fill, natural shadows, crisp detail and accurate colours.',
        'sharpen_details' => 'Make fabric texture, stitching and embroidery sharper and more realistic without changing the design.',
        'simplify_pattern' => 'Simplify the pattern: reduce its density and visual noise while keeping the same motif and placement.',
        'luxury_finish' => 'Give it a premium high-end finish: refined colour grading, a subtle natural sheen on the fabric and an elegant composition.',
        'model_shot' => 'Show the product worn by a model in modest Saudi styling, face cropped or out of frame, against a neutral studio background.',
        'lifestyle' => 'Place the product in a warm contemporary Saudi home scene, such as a modern majlis, with natural window light.',
        'ad_space' => 'Recompose it as a social media ad with clean empty space for a caption on one side; do not add any text.',
    ];

    public const MAX_PRESETS = 4;

    /**
     * The edit lines, in the order the model should apply them.
     *
     * @param  list<string>  $presets
     * @return list<string>
     */
    public function lines(array $presets, ?string $color, ?string $instruction): array
    {
        $lines = array_map(fn (string $key) => self::PRESETS[$key], array_values(array_intersect(array_keys(self::PRESETS), $presets)));

        if ($color && isset(Vocabulary::colors()[$color])) {
            $lines[] = sprintf(
                'Recolour the main body of the product to %s (%s); keep the pattern, trims, shading and fabric texture realistic.',
                Vocabulary::label('colors', $color, 'en'),
                Vocabulary::colors()[$color]['hex'],
            );
        }

        if (filled($instruction)) {
            $lines[] = 'Merchant request, apply exactly: '.trim((string) $instruction);
        }

        return $lines;
    }
}
