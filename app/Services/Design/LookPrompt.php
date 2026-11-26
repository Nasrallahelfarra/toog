<?php

namespace App\Services\Design;

use App\Models\DesignRequest;
use App\Models\DesignRequestImage;
use App\Models\Store;
use App\Services\Ai\Ai;
use App\Services\Designer\DesignerPrompt;
use App\Support\InlineImage;
use App\Support\Vocabulary;
use Illuminate\Support\Collection;

/**
 * The free idea as a look: the merchant's hand sketches and fabric photos
 * made into a photograph of the garment, worn or shown as she chose.
 *
 * Two steps, as a person would: first read the pictures (the planner
 * writes down the cut from the sketch and the fabric from its photos), then
 * photograph the garment, with every picture labelled by its role. Images
 * of one look differ only in pose and angle: the cut and the fabric never.
 */
class LookPrompt
{
    /** What each role tells the image model, in the order they are sent. */
    public const LABELS = [
        'sketch_front' => "FRONT SKETCH — the designer's hand drawing of the garment's front. Use it for construction and proportions only.",
        'sketch_back' => 'BACK SKETCH — the same garment seen from behind. Use it for the back construction only.',
        'fabric' => 'FABRIC SWATCH — a close-up photo of the textile the whole garment is made of. Use it for the material only.',
        'detail' => 'DETAIL — a close-up of an embellishment or finishing the garment must show. Use it for that detail only.',
    ];

    /** The pose of each image of a look; nothing else changes between them. */
    public const POSES = [
        'standing straight, facing the camera, arms relaxed at her sides',
        'a graceful three-quarter turn towards the camera',
        'walking slowly mid-step, the skirt moving naturally',
        'standing with one hand lightly holding the side of the skirt',
    ];

    private const BACKGROUNDS = [
        'studio' => 'a plain warm-neutral seamless studio backdrop',
        'white' => 'a pure white seamless background',
        'majlis' => 'an elegant contemporary Saudi majlis interior with soft natural window light, softly out of focus',
        'outdoor' => 'a soft outdoor setting in warm golden-hour light, the background gently blurred',
    ];

    public function __construct(private readonly Ai $ai) {}

    /**
     * The planner's reading of the pictures and the merchant's words, in
     * English for the image model, plus the store vocabulary for the
     * quality gate. Falls back to her words alone.
     *
     * @param  Collection<int, DesignRequestImage>  $images
     * @return array<string, mixed>
     */
    public function plan(DesignRequest $request, Store $store, Collection $images): array
    {
        $inline = [];
        $order = [];
        foreach ($images as $image) {
            if ($data = InlineImage::fromDisk($image->path, 1024)) {
                $inline[] = $data;
                $order[] = 'Image '.count($inline).': '.str_replace('_', ' ', $image->role);
            }
        }

        $words = trim($request->prompt);
        $fallback = fn () => [
            'garment_en' => 'a garment', 'title_ar' => '', 'construction_en' => $words, 'back_en' => '', 'fabric_en' => '',
            'intent_en' => $words, 'modesty_changes' => [], 'colors' => [], 'pattern' => null, 'material' => null, 'style' => null,
        ];

        $result = $this->ai->json(
            'design_look_plan',
            $store,
            'You are a senior fashion technical designer for a Saudi womenswear store. You read hand sketches like technical flats and describe fabrics precisely, '
                .'in short factual English for an image model. You never invent details the pictures and the words do not support.',
            implode("\n", [
                'Pictures, in order: '.($order ? implode('; ', $order) : 'none').'.',
                'The merchant wrote (Arabic or English): «'.$words.'»',
                'Return:',
                '- garment_en: what the garment is, in a few words (e.g. "long fitted evening dress").',
                '- title_ar: the same as a short Arabic product title, 2–5 words (e.g. "فستان سهرة طويل بأكمام منفوخة").',
                '- construction_en: at most 80 words describing the FRONT as sketched: silhouette and fit, neckline, sleeves and cuffs, waist, length and hem, seams and panels. Follow the sketch; where there is no sketch, follow her words.',
                '- back_en: at most 40 words on the back as sketched, or "" when there is no back sketch.',
                '- fabric_en: at most 60 words describing the fabric photos: 2–4 base colours with hex, the motif in words and its scale on a body in cm, sheen, sequins or beads and their density, sheer or opaque. "" when there is no fabric photo; then take the fabric from her words.',
                '- intent_en: her request in one English sentence.',
                '- modesty_changes: the smallest changes needed for modest Saudi styling when the sketch is revealing (e.g. "line the sheer bodice"), else [].',
                '- colors (1–3), pattern, material, style: the closest keys from these lists — colors: '.implode(', ', array_keys(Vocabulary::colors()))
                    .'; pattern: '.implode(', ', array_keys(Vocabulary::patterns())).'; material: '.implode(', ', array_keys(Vocabulary::materials()))
                    .'; style: '.implode(', ', array_keys(Vocabulary::styles())).'.',
            ]),
            [
                'type' => 'object',
                'properties' => [
                    'garment_en' => ['type' => 'string'],
                    'title_ar' => ['type' => 'string'],
                    'construction_en' => ['type' => 'string'],
                    'back_en' => ['type' => 'string'],
                    'fabric_en' => ['type' => 'string'],
                    'intent_en' => ['type' => 'string'],
                    'modesty_changes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    // No enums: Gemini caps schema complexity. The keys are listed in the prompt and checked after.
                    'colors' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'pattern' => ['type' => 'string'],
                    'material' => ['type' => 'string'],
                    'style' => ['type' => 'string'],
                ],
                'required' => ['garment_en', 'title_ar', 'construction_en', 'back_en', 'fabric_en', 'intent_en', 'modesty_changes', 'colors', 'pattern', 'material', 'style'],
            ],
            $fallback,
            $inline,
            $request->user_id,
        );

        $plan = [...$fallback(), ...$result->data, 'from_model' => $result->fromModel];
        // Only known vocabulary reaches the quality gate.
        $plan['colors'] = array_values(array_slice(array_intersect((array) $plan['colors'], array_keys(Vocabulary::colors())), 0, 3));
        foreach (['pattern' => Vocabulary::patterns(), 'material' => Vocabulary::materials(), 'style' => Vocabulary::styles()] as $key => $list) {
            $plan[$key] = is_string($plan[$key]) && isset($list[$plan[$key]]) ? $plan[$key] : null;
        }
        $plan['modesty_changes'] = array_values(array_filter((array) $plan['modesty_changes'], 'is_string'));

        return $plan;
    }

    /**
     * The pictures in the order they are sent, each with its label. The back
     * view starts from the front already made, so both show one garment.
     *
     * @param  Collection<int, DesignRequestImage>  $images
     * @return list<array{path: string, label: string}>
     */
    public function references(Collection $images, string $view, ?string $frontPath = null): array
    {
        $refs = [];
        if ($view === 'back' && $frontPath) {
            $refs[] = ['path' => $frontPath, 'label' => 'THE GARMENT, already photographed from the front: the same dress, the same fabric and the same model. Show exactly this, from behind.'];
        }

        $count = ['fabric' => 0];
        $fabrics = $images->where('role', 'fabric')->count();
        foreach ($images as $image) {
            // The back view works from the front already made and the back sketch.
            if ($view === 'back' && $image->role === 'sketch_front' && $images->contains('role', 'sketch_back')) {
                continue;
            }
            $label = self::LABELS[$image->role];
            if ($image->role === 'fabric') {
                $count['fabric']++;
                $label = str_replace('FABRIC SWATCH', sprintf('FABRIC SWATCH %d of %d', $count['fabric'], $fabrics), $label);
            }
            $refs[] = ['path' => $image->path, 'label' => $label];
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  Collection<int, DesignRequestImage>  $images
     */
    public function prompt(DesignRequest $request, array $plan, Collection $images, string $view, int $variant): string
    {
        $presentation = $request->presentation ?? [];
        $mode = $presentation['mode'] ?? 'model';
        $hasSketch = $images->contains(fn ($i) => str_starts_with($i->role, 'sketch_'));
        $hasFabric = $images->contains('role', 'fabric');
        $garment = trim((string) ($plan['garment_en'] ?? '')) ?: 'the garment';
        $background = self::BACKGROUNDS[$presentation['background'] ?? 'studio'] ?? self::BACKGROUNDS['studio'];
        $pose = $view === 'back' ? 'seen from directly behind, standing straight' : self::POSES[$variant % count(self::POSES)];

        $subject = match ($mode) {
            'mannequin' => "the {$garment} on an invisible ghost mannequin, full length from shoulders to hem with both sleeves fully visible, natural 3D volume, no visible mannequin, body or hanger",
            'flat' => "the {$garment} laid flat and neatly styled on a clean surface, seen from directly above, the whole garment in frame",
            default => DesignerPrompt::MODEL.' wearing the '.$garment.', '.$pose.', '.match ($presentation['face'] ?? 'cropped') {
                'shown' => 'her face visible: '.DesignerPrompt::FACE.', dark hair in a neat low bun',
                'hijab' => 'wearing a neatly wrapped plain hijab in a colour that complements the dress, her face visible: '.DesignerPrompt::FACE,
                default => 'framed from the chin down so her face is out of the frame',
            }.'; the whole garment visible from neckline to hem, sleeves uncropped',
        };

        $lines = ['Create a NEW photorealistic e-commerce photograph of '.$subject.'.'];

        if ($hasSketch) {
            $lines[] = 'GARMENT — the sketches are a technical flat and the source of truth for construction: '.trim((string) ($plan['construction_en'] ?? ''))
                .' Follow the silhouette, proportions, neckline depth, sleeve volume and cuffs, waist and hip fit, length, hem and seam lines exactly as drawn. '
                .'The drawing is only a plan: render no pen or marker lines, no paper and no stick figure, and none of the marker\'s colour appears on the garment.';
            if ($view === 'back') {
                $lines[] = 'BACK: '.(trim((string) ($plan['back_en'] ?? '')) ?: 'as in the back sketch').'.';
            }
        } else {
            $lines[] = 'GARMENT: '.(trim((string) ($plan['construction_en'] ?? '')) ?: trim($request->prompt)).'.';
        }

        if ($hasFabric) {
            $lines[] = 'FABRIC — the swatch photos are the source of truth for the material; the entire garment is this fabric: '.trim((string) ($plan['fabric_en'] ?? ''))
                .' Reproduce its exact colours, motif and the motif\'s scale on the body, sheen, and sequin or bead density and sparkle; the pattern flows continuously across seams and follows the drape. '
                .'Do not recolour, simplify, enlarge, tile or mirror it, and do not copy the swatch\'s folds, framing or light. If the fabric is sheer, back it with an opaque lining in a matching skin-neutral tone.';
        } elseif (trim((string) ($plan['fabric_en'] ?? '')) !== '') {
            $lines[] = 'FABRIC: '.trim((string) $plan['fabric_en']).'.';
        }

        $lines[] = 'MERCHANT INTENT: '.(trim((string) ($plan['intent_en'] ?? '')) ?: trim($request->prompt));
        $lines[] = 'Her own words (for meaning only, never to be written in the image): «'.trim($request->prompt).'»';

        $changes = array_values(array_filter((array) ($plan['modesty_changes'] ?? []), 'is_string'));
        $lines[] = $changes
            ? 'Modest Saudi styling: make only these changes, and nothing more: '.implode('; ', $changes).'. Everything else exactly as sketched.'
            : 'Modest Saudi styling, as the design already is.';

        $lines[] = 'SETTING: '.$background.'; soft diffused key light from 45° with gentle fill so the fabric\'s texture and sparkle read clearly without blowing out; 85 mm lens at eye level.';
        $lines[] = 'QUALITY: a finished, well-made garment with realistic seams and hem; a plain photograph with no text, logos, labels or watermark; no belts, trims, beads, jewellery or accessories the sketch does not show.';

        return implode("\n", $lines);
    }
}
