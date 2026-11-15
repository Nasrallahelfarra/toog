<?php

namespace App\Services\Designer;

use App\Support\FashionVocabulary;
use App\Support\Vocabulary;
use Closure;

/**
 * The image prompt for one concept, assembled in a fixed order from the
 * design sheet: framing → garment → fabric → modesty → quality. The
 * merchant's fabric photo, when there is one, is described as a swatch,
 * never as a garment to copy.
 *
 * "Design like this" sends two images: Image 1 is the design to reproduce
 * (construction only, never its logos, text, person or background) and
 * Image 2 the merchant's fabric. Modesty changes to the copied design are
 * the minimum the level needs, listed one by one.
 *
 * Modesty follows the brief: very modest and modest keep hair covered and
 * the cut loose or softly fitted; open is contemporary fashion (bare
 * shoulders, midi or knee lengths) — tasteful, never revealing. The model
 * is a Saudi woman with clearly Gulf Arab features, so the merchant sees
 * the design on her own customer; her face is shown only when asked for.
 */
class DesignerPrompt
{
    /** Sent just before the design reference image. */
    public const LABEL_DESIGN = 'Image 1 — DESIGN REFERENCE: the garment to reproduce. Use it for construction only.';

    /** Sent just before the merchant's fabric swatch. */
    public const LABEL_FABRIC = 'Image 2 — FABRIC SWATCH: the merchant\'s textile. Use it for the material only.';

    /** The planner's instructions when the brief copies a design. */
    public const PLANNER_MATCH = 'MATCH MODE. Image 1 is a DESIGN REFERENCE the merchant wants reproduced in her own fabric; Image 2, when present, is her FABRIC SWATCH. '
        .'Reproduce the reference\'s design; do not reinterpret it. First fill the ref_ fields for the single main garment (if several appear, the most prominent full-length one): '
        .'ref_garment (one of the garments in reference_keys), and the closest key from reference_keys for ref_silhouette (from that garment\'s list), ref_neckline, ref_sleeve, ref_length, '
        .'ref_closure, ref_placement and ref_embellishments, ignoring the modesty level; ref_construction_en is at most 80 words a pattern cutter could work from: panels and seam lines, '
        .'yoke, gathers, pleats, tiers, layers or overlays, sleeve shape and cuff, collar, hem shape, volume, proportions, contrast parts and where each embellishment sits. '
        .'Describe construction only: never logos, brand names, monograms, text, the person, her pose or the background. ref_visible is full, upper or lower; '
        .'ref_is_garment is false when no wearable women\'s garment is shown. Concept 1 (role match) copies every reference field exactly. '
        .'Each later concept (role match_variant) keeps the same design and changes exactly one of: trim or embellishment colour, length, sleeve, closure — name it in variant_of_en. '
        .'Silhouette, neckline, seam lines and proportions never change. The merchant\'s fabric replaces the reference\'s fabric, colour and print in every concept. '
        .'Where a reference key is not allowed at the brief\'s modesty level, use the nearest allowed key and change nothing else for modesty. '
        .'Never use the competitor\'s product or brand name. design_notes_en (at most 80 words) is what this concept keeps from Image 1 and its one change.';

    private const MATCH = 'Create a NEW %s of the garment in Image 1 — not an edit of Image 1. Reproduce its design exactly: silhouette and volume, neckline and collar, '
        .'sleeve shape and cuff, length and hem shape, seam lines, panels, yokes, pleats, gathers, tiers and layers, closure, and the type, placement and density of every embellishment. '
        .'Image 1 is the source of truth for construction; the design sheet below describes it in words — where they differ, follow Image 1, except for the required changes, which always win.';

    private const GUARDRAILS = 'Take nothing else from the images: not the person, face, body, hair, head covering or pose; not the background, props, lighting or camera angle; '
        .'not the swatch\'s folds or framing. Remove every logo, brand name, monogram, label, watermark, caption, price tag and text overlay in Image 1, and never turn one into '
        .'embroidery, print or hardware; branded buttons, buckles or chains become plain ones. Do not trace Image 1\'s printed or embroidered artwork: keep each embellishment\'s '
        .'type and placement with a simple motif of your own.';

    /** Who wears the design: always recognisably a Saudi, Gulf Arab woman. */
    private const MODEL = 'a Saudi woman in her mid-twenties with distinctly Gulf Arab (Khaleeji) looks';

    /** Her face, when it is shown. */
    private const FACE = 'fair, luminous light skin, large dark brown almond-shaped eyes, full dark defined eyebrows, '
        .'soft Khaleeji-style makeup with lined eyes and nude lips';

    private const FRAMING = [
        'ghost' => 'A high-resolution e-commerce product photograph of the garment on an invisible ghost mannequin: front view, full length from shoulders to hem with both sleeves fully visible, natural 3D volume, no visible mannequin, body or hanger, plain light warm-grey seamless background, soft even studio lighting, subtle contact shadow.',
        'on_model' => 'A full-length e-commerce photograph of %1$s wearing the garment, standing in a calm natural pose, %2$s; hands relaxed; plain warm-neutral studio backdrop; soft diffused light.',
        'editorial' => 'A full-length editorial fashion photograph of %1$s wearing the garment in an elegant, bright interior: sheer white curtains, a slim white plinth with a sculptural gold object, a pale floor and soft natural daylight; a graceful relaxed pose, one hand lightly lifting the skirt; %2$s.',
        'sketch' => 'A professional fashion design illustration of the garment on a slender faceless fashion croquis in a front-facing pose%s, fine ink lines with marker and watercolour shading on off-white paper, the fabric\'s colours and print rendered faithfully.',
        'flat' => 'A technical flat drawing in fashion tech-pack style, front and back views side by side, clean black line art on pure white, seams, darts, pleats and closures shown accurately, topstitching as dashed lines, no figure, no shading, no colour fill.',
    ];

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $concept
     */
    public function build(array $brief, array $concept, bool $hasFabricPhoto, bool $hasReference = false): string
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

        // The model: a Saudi woman, face shown or cropped at the chin; hair
        // covered for the modest levels, long and dark otherwise.
        $hair = $covered ? 'hair and neck fully covered by a plain '.($colorName($colors[0] ?? 'black') ?? 'matching').' shayla' : 'long glossy dark brown hair in soft sleek waves';
        $person = $showFace
            ? 'framed from head to toe with her face visible: '.self::FACE.", a natural, calm and elegant expression, {$hair}"
            : "framed from the chin down to the feet so the face is out of frame, fair light skin, {$hair}";
        $framing = match ($render) {
            'on_model', 'editorial' => sprintf(self::FRAMING[$render], self::MODEL, $person),
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
        $fabricName = self::fabricName($brief);
        $read = (array) ($brief['fabric_read'] ?? []);
        if ($render === 'flat') {
            $fabricLine = '';
        } elseif ($hasFabricPhoto) {
            $scale = trim(($read['print_scale'] ?? 'medium').' print'.(! empty($read['motif_note_en']) ? ': '.$read['motif_note_en'] : ''));
            $fabricLine = 'The attached reference image is a FABRIC SWATCH photograph, not a garment and not a layout. Use it only as the textile for the main body'
                .($fabricName ? " ({$fabricName})" : '').': reproduce its exact colours, print motifs, motif scale, texture, weave and sheen. '
                .'Do not recolour, simplify, mirror or redraw the print, and do not copy the swatch\'s framing, background, folds or lighting. '
                ."Scale the print realistically to a human-sized garment ({$scale}) and let it follow seams and drape naturally. "
                .'Trims and details use only '.$detailColor.'.';
        } else {
            $fabricLine = sprintf(
                'Fabric: %s in %s, %s, realistic texture%s.',
                $fabricName ?? 'crepe',
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
            .($showFace && in_array($render, ['on_model', 'editorial'], true) ? 'Her features must read clearly as Saudi and Gulf Arab, not European. A natural, realistic face and hands, with no distortion. ' : '')
            .($render === 'sketch' ? 'Polished fashion-illustration quality.' : ($render === 'flat' ? 'Clean technical drawing quality.' : 'Photorealistic commercial quality.'));

        if ($hasReference) {
            $reference = (array) ($brief['reference'] ?? []);
            $construction = trim((string) ($reference['construction_en'] ?? ''));
            $variant = trim((string) ($concept['variant_of_en'] ?? ''));
            $visible = (string) ($reference['visible'] ?? 'full');
            $colorsText = implode(' and ', array_filter(array_map($colorName, array_slice($colors, 0, 2)))) ?: 'black (#1C1C1E)';
            $matchFabric = match (true) {
                $render === 'flat' => '',
                $hasFabricPhoto => 'Make it entirely in the fabric of Image 2: reproduce its exact colours, print motifs, motif scale, texture, weave and sheen on the main body; '
                    .'do not recolour, simplify or redraw the print. Replace Image 1\'s fabric, colour and print completely. '
                    ."Parts that are a contrast fabric in Image 1 stay contrast, in solid {$detailColor}. Let the fabric drape naturally while keeping the same seam lines and proportions.",
                default => sprintf('Make it in %s in %s, %s; replace Image 1\'s fabric, colour and print completely.', $fabricName ?? 'crepe', $colorsText, $fabric ? $fabric['drape_en'] : 'soft drape'),
            };

            return implode("\n", array_filter([
                sprintf(self::MATCH, match ($render) {
                    'sketch' => 'fashion design illustration',
                    'flat' => 'technical flat drawing',
                    default => 'product photograph',
                }),
                $framing,
                'Design sheet (describes Image 1): '.substr($garmentLine, strlen('The garment: ')).' '.$details.($construction !== '' ? ' Construction: '.$construction : ''),
                $variant !== '' ? "This version differs from Image 1 in one way only: {$variant}." : null,
                $this->requiredChanges($concept, $modesty, $en),
                in_array($visible, ['upper', 'lower'], true) ? "Image 1 shows only the {$visible} part; complete the rest following the design sheet, in the same style." : null,
                $matchFabric,
                $lining,
                self::GUARDRAILS,
                $modestyLine,
                $quality,
            ]));
        }

        return implode("\n", array_filter([$framing, $garmentLine, $details, $lining, $mood, $notes ? 'Designer notes: '.$notes : null, $fabricLine, $modestyLine, $quality]));
    }

    /**
     * The changes a modesty level forces on a copied design, one phrase
     * each, plus closing any slit or sheer area at the modest levels.
     *
     * @param  array<string, mixed>  $concept
     * @param  Closure(string, ?string): ?string  $en
     */
    private function requiredChanges(array $concept, string $modesty, Closure $en): ?string
    {
        $phrases = [];
        foreach ((array) ($concept['modesty_changes'] ?? []) as $change) {
            $field = (string) ($change['field'] ?? '');
            $to = $en($field === 'silhouette' ? 'silhouettes' : $field, $change['to'] ?? null);
            $phrases[] = match ($field) {
                'sleeve' => "add {$to} sleeves to the wrist in the same fabric, set into the reference's armhole and finished like its other edges",
                'neckline' => "raise the neckline to a {$to} neckline, keeping the reference's neckline trim",
                'length' => "lengthen the hem to {$to}, keeping its shape, flare and hem trim",
                'silhouette' => "ease the fit to a {$to} line that does not cling at bust, waist or hips, keeping the same seam lines",
                default => null,
            };
        }
        if ($modesty !== 'open') {
            $phrases[] = 'close any slit, cut-out or sheer area over skin with the same fabric or a lining';
        }
        $phrases = array_values(array_filter($phrases));

        return $phrases ? 'Required changes from Image 1, and nothing more: '.implode('; ', $phrases).'. Everything else stays exactly as in Image 1.' : null;
    }

    /**
     * The fabric's name for the prompt: the merchant's own fabric (in
     * English when the planner translated it, with the built-in fabric it
     * is a kind of), or the built-in one.
     *
     * @param  array<string, mixed>  $brief
     */
    public static function fabricName(array $brief): ?string
    {
        $fabric = FashionVocabulary::fabrics()[$brief['fabric'] ?? ''] ?? null;
        $custom = trim((string) ($brief['fabric_custom_en'] ?? '')) ?: trim((string) ($brief['fabric_custom'] ?? ''));

        return match (true) {
            $custom !== '' && $fabric !== null => "{$custom}, a kind of ".strtolower($fabric['en']),
            $custom !== '' => $custom,
            $fabric !== null => strtolower($fabric['en']),
            default => null,
        };
    }

    /**
     * The brief note for "design like this": the same piece in the
     * merchant's fabric, changed only where modesty requires.
     */
    public static function matchNote(string $locale): string
    {
        return $locale === 'ar'
            ? 'صمّم مثل هذه القطعة بقماشك: نفس القصّة والياقة والأكمام والطول وأماكن التطريز، مع تعديلات الحشمة اللازمة فقط، ومن دون أي شعار أو كتابة من الصورة.'
            : 'Design it like this piece, in your fabric: same cut, neckline, sleeves, length and embellishment placement, with only the modesty changes your level needs, and no logo or text from the photo.';
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
