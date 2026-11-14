<?php

namespace App\Services\Designer;

use App\Support\FashionVocabulary;
use App\Support\Vocabulary;

/**
 * The image prompt for one concept, assembled in a fixed order from the
 * design sheet: framing → garment → fabric → modesty → quality. The
 * merchant's fabric photo, when there is one, is the only reference image
 * and is described as a swatch, never as a garment to copy.
 *
 * Modesty follows the brief: very modest and modest keep hair covered and
 * the cut loose or softly fitted; open is contemporary fashion (bare
 * shoulders, midi or knee lengths) — tasteful, never revealing. The model's
 * face is shown only when the merchant asks for it.
 */
class DesignerPrompt
{
    private const FRAMING = [
        'ghost' => 'A high-resolution e-commerce product photograph of the garment on an invisible ghost mannequin: front view, full length from shoulders to hem with both sleeves fully visible, natural 3D volume, no visible mannequin, body or hanger, plain light warm-grey seamless background, soft even studio lighting, subtle contact shadow.',
        'on_model' => 'A full-length e-commerce photograph of a woman wearing the garment, standing in a calm natural pose, %s; hands relaxed; plain warm-neutral studio backdrop; soft diffused light.',
        'editorial' => 'A full-length editorial fashion photograph of a woman wearing the garment in an elegant, bright interior: sheer white curtains, a slim white plinth with a sculptural gold object, a pale floor and soft natural daylight; a graceful relaxed pose, one hand lightly lifting the skirt; %s.',
        'sketch' => 'A professional fashion design illustration of the garment on a slender faceless fashion croquis in a front-facing pose%s, fine ink lines with marker and watercolour shading on off-white paper, the fabric\'s colours and print rendered faithfully.',
        'flat' => 'A technical flat drawing in fashion tech-pack style, front and back views side by side, clean black line art on pure white, seams, darts, pleats and closures shown accurately, topstitching as dashed lines, no figure, no shading, no colour fill.',
    ];

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $concept
     */
    public function build(array $brief, array $concept, bool $hasFabricPhoto): string
    {
        $garment = $brief['garment'];
        $modesty = $brief['modesty'] ?? FashionVocabulary::defaultModesty($garment);
        $covered = $modesty !== 'open';
        $render = $this->render($brief['render'] ?? 'ghost', $concept, $modesty);
        $en = fn (string $group, ?string $key) => $key ? strtolower((string) FashionVocabulary::label($group, $key, 'en', $garment)) : null;
        $colorName = fn (?string $key) => $key ? sprintf('%s (%s)', Vocabulary::label('colors', $key, 'en'), Vocabulary::colors()[$key]['hex'] ?? '') : null;
        $colors = array_values(array_filter((array) ($concept['colors'] ?? [])));
        $detailColor = $colorName($colors[1] ?? 'gold') ?? 'gold';
        $embellishments = array_values(array_filter((array) ($concept['embellishments'] ?? []), fn ($e) => $e !== 'none'));
        $showFace = (bool) ($brief['show_face'] ?? false);

        // The model: face shown or cropped at the chin; hair covered for the modest levels.
        $hair = $covered ? 'hair and neck fully covered by a plain '.($colorName($colors[0] ?? 'black') ?? 'matching').' shayla' : 'hair styled sleek and elegant';
        $person = $showFace
            ? "framed from head to toe with her face visible, a natural, calm and elegant expression, {$hair}"
            : "framed from the chin down to the feet so the face is out of frame, {$hair}";
        $framing = match ($render) {
            'on_model', 'editorial' => sprintf(self::FRAMING[$render], $person),
            'sketch' => sprintf(self::FRAMING['sketch'], $covered ? ', head and hair covered by a simple shayla' : ''),
            default => self::FRAMING[$render],
        };

        $sleeve = match ($concept['sleeve'] ?? null) {
            'sleeveless' => 'sleeveless',
            'short' => 'short sleeves',
            default => ($en('sleeve', $concept['sleeve'] ?? null) ?? 'straight').' sleeves'.($covered ? ' to the wrist' : ''),
        };
        $garmentLine = sprintf(
            'The garment: a %s with a %s silhouette, %s neckline, %s, %s, %s.',
            $en('garments', $garment) ?? 'dress',
            $en('silhouettes', $concept['silhouette'] ?? null) ?? 'relaxed',
            $en('neckline', $concept['neckline'] ?? null) ?? 'round',
            $sleeve,
            $en('length', $concept['length'] ?? null) ?? 'floor length',
            $en('closure', $concept['closure'] ?? null) ?? 'hidden snaps',
        );
        $details = $embellishments
            ? sprintf('Details: %s on the %s in %s.', implode(' and ', array_map(fn ($e) => $en('embellishment', $e), $embellishments)), $en('placement', $concept['placement'] ?? null) ?? 'neckline', $detailColor)
            : 'Details: clean, minimal finishing with no embellishment.';
        $lining = ($concept['lining'] ?? 'none') === 'full' ? 'Fully lined so nothing is see-through.' : '';
        $mood = sprintf('Mood: %s, for %s.', Vocabulary::label('styles', $concept['style'] ?? 'modern', 'en') ?? 'modern', $en('occasions', $brief['occasion'] ?? 'daily') ?? 'everyday wear');
        $notes = trim((string) ($concept['design_notes_en'] ?? ''));

        $fabric = FashionVocabulary::fabrics()[$brief['fabric'] ?? ''] ?? null;
        $read = (array) ($brief['fabric_read'] ?? []);
        if ($render === 'flat') {
            $fabricLine = '';
        } elseif ($hasFabricPhoto) {
            $scale = trim(($read['print_scale'] ?? 'medium').' print'.(! empty($read['motif_note_en']) ? ': '.$read['motif_note_en'] : ''));
            $fabricLine = 'The attached reference image is a FABRIC SWATCH photograph, not a garment and not a layout. Use it only as the textile for the main body'
                .($fabric ? ' (a '.strtolower($fabric['en']).')' : '').': reproduce its exact colours, print motifs, motif scale, texture, weave and sheen. '
                .'Do not recolour, simplify, mirror or redraw the print, and do not copy the swatch\'s framing, background, folds or lighting. '
                ."Scale the print realistically to a human-sized garment ({$scale}) and let it follow seams and drape naturally. "
                .'Trims and details use only '.$detailColor.'.';
        } else {
            $fabricLine = sprintf(
                'Fabric: %s in %s, %s, realistic texture%s.',
                $fabric ? strtolower($fabric['en']) : 'crepe',
                implode(' and ', array_filter(array_map($colorName, array_slice($colors, 0, 2)))) ?: 'black (#1C1C1E)',
                $fabric ? $fabric['drape_en'] : 'soft drape',
                ($concept['pattern'] ?? 'solid') !== 'solid' ? ', with a '.strtolower((string) Vocabulary::label('patterns', $concept['pattern'], 'en')).' pattern' : '',
            );
        }

        $modestyLine = match ($modesty) {
            'open' => 'Contemporary, elegant womenswear exactly as designed: bare shoulders or arms and a midi or knee length are fine where the design calls for them. '
                .'Tasteful, upscale commercial fashion photography: no nudity, no lingerie, no see-through fabric over the body, no suggestive pose.',
            'standard' => 'Saudi modest womenswear: no cleavage, no slits, cut-outs or sheer areas over skin; a softly fitted waist is fine.',
            default => 'Saudi modest womenswear: loose fit that does not cling at bust, waist or hips; opaque or fully lined; sleeves reach the wrist; hem reaches the ankle or floor; no slits, cut-outs or sheer areas over skin.',
        };

        $quality = 'Show the whole garment; nothing cropped at the hem or sleeves. Accurate, buildable construction with realistic seams and hems. '
            .'No text, letters, Arabic or Latin script, logos, labels, tags or watermarks anywhere. No religious symbols, flags or national emblems. '
            .($showFace && in_array($render, ['on_model', 'editorial'], true) ? 'A natural, realistic face and hands, with no distortion. ' : '')
            .($render === 'sketch' ? 'Polished fashion-illustration quality.' : ($render === 'flat' ? 'Clean technical drawing quality.' : 'Photorealistic commercial quality.'));

        return implode("\n", array_filter([$framing, $garmentLine, $details, $lining, $mood, $notes ? 'Designer notes: '.$notes : null, $fabricLine, $modestyLine, $quality]));
    }

    /**
     * At the very modest level a model is shown only in fully covered
     * designs; a midi length, a fitted mermaid line or a V neck is drawn on
     * a ghost mannequin instead. The other levels keep the chosen style.
     *
     * @param  array<string, mixed>  $concept
     */
    public function render(string $render, array $concept, string $modesty = 'strict'): string
    {
        $render = array_key_exists($render, self::FRAMING) ? $render : 'ghost';

        if ($modesty === 'strict' && in_array($render, ['on_model', 'editorial'], true) && (in_array($concept['length'] ?? null, ['midi', 'knee'], true)
            || ($concept['silhouette'] ?? null) === 'mermaid' || in_array($concept['neckline'] ?? null, ['v_shallow', 'v_neck'], true))) {
            return 'ghost';
        }

        return $render;
    }
}
