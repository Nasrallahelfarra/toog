<?php

namespace App\Services\Designer;

use App\Models\CompetitorProduct;
use App\Models\Design;
use App\Models\DesignCollection;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Ai\Ai;
use App\Services\Ai\AiException;
use App\Services\Evaluation\Candidate;
use App\Services\Evaluation\EvaluationEngine;
use App\Services\Evaluation\StoreContext;
use App\Services\Market\CompetitorRadar;
use App\Support\FashionVocabulary;
use App\Support\InlineImage;
use App\Support\Vocabulary;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The AI fashion designer. A brief (fabric, garment, occasion, how bold)
 * becomes a small collection: each concept has a role (safe bet, trend
 * twist, competitor gap, signature), a full design sheet, the evidence it
 * rests on, production estimates worked out in code, an image drawn with
 * the merchant's fabric as the reference, and a fit score from the
 * evaluation engine. Numbers in the evidence come from the database by
 * id; the model only chooses which facts a concept rests on.
 *
 * "Design like this" (a competitor piece with match = close) reproduces
 * the piece instead: the planner sees its picture, records its
 * construction, and every concept keeps it — the first as it is, the
 * others with one change each; modesty changes are the nearest allowed
 * keys, recorded one by one.
 */
class CollectionDesigner
{
    /** Stored as the collection's error when the picture shows no garment. */
    public const NOT_A_GARMENT = 'reference_not_garment';

    public function __construct(
        private readonly Ai $ai,
        private readonly DesignerInsights $insights,
        private readonly DesignerPrompt $prompts,
        private readonly EvaluationEngine $engine,
        private readonly ReferenceImage $references,
    ) {}

    /**
     * Whether a brief copies a competitor piece (rather than taking it as
     * a loose inspiration).
     *
     * @param  array<string, mixed>  $brief
     */
    public static function matches(array $brief): bool
    {
        return ($brief['inspiration']['match'] ?? null) === 'close'
            && in_array($brief['inspiration']['kind'] ?? null, ['competitor', 'social'], true);
    }

    /**
     * Plan the collection (when not planned yet) and draw every concept
     * that has no image, or only the one at $only.
     */
    public function run(DesignCollection $collection, ?int $only = null): void
    {
        $collection->update(['status' => 'processing', 'error' => null]);
        $store = $collection->store;

        try {
            if (empty($collection->concepts)) {
                $this->plan($collection, $store);
            }

            $this->draw($collection, $store, $only);
        } catch (Throwable $e) {
            report($e);
            $collection->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->notify($collection, failed: true);

            return;
        }

        $concepts = $collection->fresh()->concepts ?? [];
        $ready = collect($concepts)->where('status', 'ready')->count();
        $collection->update([
            'status' => $ready > 0 ? 'completed' : 'failed',
            'error' => $ready > 0 ? null : (collect($concepts)->pluck('error')->filter()->first() ?? 'No design could be drawn.'),
        ]);

        if ($only === null) {
            $this->notify($collection->fresh(), failed: $ready === 0);
        }
    }

    /**
     * Read the fabric, gather the evidence, and write the concepts.
     */
    private function plan(DesignCollection $collection, Store $store): void
    {
        $brief = $collection->brief;
        $insights = $this->insights->build($store, 'en');
        $packet = $this->evidence($insights);

        // "Design like this": the original piece is copied once, next to the
        // collection; without it the brief honestly falls back to inspiration.
        $match = self::matches($brief);
        if ($match && ! $this->hasReference($collection)) {
            $found = $this->references->resolve($store, $brief['inspiration']);
            if ($found) {
                $collection->update(['reference_image_path' => $found['path']]);
                $brief['inspiration']['brand'] = $found['brand'];
            } else {
                $brief['inspiration']['match'] = 'inspired';
                $brief['reference_missing'] = true;
                $brief['notes'] = self::withoutCopyNote($brief['notes'] ?? null);
                $match = false;
            }
        }
        if ($match) {
            $original = $this->inspirationEvidence($store, $brief['inspiration']);
            $packet = [...$original, ...$packet];
            // Kept on every plan, a retry included, so the brand never reaches the names.
            $brand = collect($original)->first()['competitor'] ?? null;
            if (blank($brief['inspiration']['brand'] ?? null) && filled($brand) && $brand !== '—') {
                $brief['inspiration']['brand'] = $brand;
            }
        }

        if ($collection->fabric_image_path) {
            $brief['fabric_read'] = $this->readFabric($collection, $store);
            // A copied design takes its colours from the merchant's fabric.
            if (($match || empty($brief['colors'])) && ! empty($brief['fabric_read']['colors'])) {
                $brief['colors'] = $brief['fabric_read']['colors'];
            }
        }
        $brief['colors'] = array_values(array_slice($brief['colors'] ?? [], 0, 4)) ?: $this->dnaColors($insights);

        $roles = $match
            ? FashionVocabulary::matchRoles((int) $brief['count'])
            : FashionVocabulary::roleMix((int) $brief['creativity'], (int) $brief['count'], $insights['competitor_hits'] !== []);
        $garment = $brief['garment'];
        $brief['modesty'] ??= FashionVocabulary::defaultModesty($garment);
        $allowed = $this->allowed($garment, $brief['modesty']);

        $roleRules = $match ? DesignerPrompt::PLANNER_MATCH.' ' : 'Design a small, sellable collection for the brief, one concept per role, in the given order. '
            .'safe_bet keeps the DNA of the store\'s own best sellers in the new fabric with at most one change; '
            .'trend_twist adds one rising-trend element to a proven base; competitor_gap offers a combination that works for competitors but the store lacks, '
            .'borrowing attribute combinations and never one competitor product\'s look (change at least two of silhouette, pattern and colour); '
            .'signature is the store\'s own bold or heritage-inspired piece. '
            .'The merchant\'s fabric is the main fabric of every concept, so make concepts differ in cut and details, not in fabric. ';

        $result = $this->ai->json(
            operation: 'designer_collection',
            store: $store,
            system: 'You are the head designer of a Saudi modest-womenswear label, designing for this online store. '
                .$roleRules
                .'Every concept must be buildable by a Saudi workshop and follow the brief\'s modesty level: strict is fully covered and loose; '
                .'standard allows a softly fitted waist and midi lengths; open is contemporary fashion where bare shoulders or arms, one-shoulder or '
                .'off-the-shoulder necklines, corset bodices and midi or knee lengths are welcome — always tasteful and elegant. '
                .'When the brief names the merchant\'s store category, design for that section of their store. '
                .'Use only the keys listed under allowed for every coded field. '
                .'Any claim about sales, competitors or trends must cite evidence ids from the packet in evidence_ids; never invent numbers, sales or revenue. '
                .'A competitor best-seller label is the merchant\'s own claim, and engagement is not sales. '
                .'Write names, pitches, rationale and risks in natural Arabic (Modern Standard, Saudi-friendly) and in English. '
                .($match ? '' : 'design_notes_en is an English note of at most 40 words for the illustrator. ')
                .'When brief.fabric.custom_name is set, fabric_en is its English name; otherwise leave fabric_en empty.',
            prompt: json_encode([
                'brief' => $this->briefForModel($brief),
                'roles' => $roles,
                'allowed' => $allowed,
                ...($match ? ['reference_keys' => $this->referenceKeys()] : []),
                'evidence' => array_values(array_map(fn ($e) => Arr::except($e, ['claim_ar', 'claim_en', 'href', 'image_url', 'ref']), $packet)),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            schema: $this->schema(count($roles), $match),
            fallback: fn () => $this->fallback($brief, $roles, $insights),
            images: $match ? $this->plannerImages($collection) : [],
            userId: $collection->user_id,
            timeoutSeconds: 120,
        );

        $data = $result->data;
        $raw = array_values(array_filter((array) ($data['concepts'] ?? []), 'is_array'));
        if ($raw === []) {
            // Keep what the model read of the piece; only the concepts are templated.
            $fallback = $this->fallback($brief, $roles, $insights);
            $raw = $fallback['concepts'];
            $data += $fallback;
        }

        // A copy needs the model's reading of the piece. Without it (a failed
        // call, an empty answer) the brief falls back to inspiration rather
        // than copying the picture against an invented design sheet.
        if ($match && $this->ai->isLive() && (! $result->fromModel || ! is_string($data['ref_garment'] ?? null))) {
            $brief['inspiration']['match'] = 'inspired';
            $brief['reference_unread'] = true;
            $brief['notes'] = self::withoutCopyNote($brief['notes'] ?? null);
            $match = false;
            $roles = FashionVocabulary::roleMix((int) $brief['creativity'], (int) $brief['count'], $insights['competitor_hits'] !== []);
            $data = $this->fallback($brief, $roles, $insights);
            $raw = $data['concepts'];
        }

        $reference = null;
        if ($match) {
            if (($data['ref_is_garment'] ?? true) === false) {
                // A code the studio translates for the viewer.
                throw new RuntimeException(self::NOT_A_GARMENT);
            }
            // The picture decides what the piece is.
            $seen = $data['ref_garment'] ?? null;
            if (is_string($seen) && isset(FashionVocabulary::garments()[$seen]) && $seen !== $garment) {
                $brief['garment'] = $garment = $seen;
                $allowed = $this->allowed($garment, $brief['modesty']);
            }
            $brief['reference'] = $reference = $this->reference($data, $garment);
        }
        if (filled($brief['fabric_custom'] ?? null) && is_string($data['fabric_en'] ?? null) && trim($data['fabric_en']) !== '') {
            $brief['fabric_custom_en'] = mb_substr(trim($data['fabric_en']), 0, 80);
        }

        $concepts = [];
        foreach ($roles as $i => $role) {
            $concepts[] = $this->clean($raw[$i] ?? $this->fallback($brief, [$role], $insights)['concepts'][0], $role, $i, $brief, $packet, $allowed, $store, $reference);
        }
        // Copies of one piece keep its cut; other collections differ in cut.
        if (! $match) {
            $concepts = $this->diversify($concepts, $garment, $allowed);
        }

        $collection->update([
            'brief' => $brief,
            'title_ar' => $this->text($data['title_ar'] ?? null) ?: $this->fallback($brief, $roles, $insights)['title_ar'],
            'title_en' => $this->text($data['title_en'] ?? null) ?: $this->fallback($brief, $roles, $insights)['title_en'],
            'story_ar' => $this->text($data['story_ar'] ?? null),
            'story_en' => $this->text($data['story_en'] ?? null),
            'concepts' => $concepts,
            'from_model' => $result->fromModel,
            'cost' => (float) $collection->cost + $result->cost,
        ]);
    }

    /**
     * Draw each concept in turn, saving after each one so the studio can
     * show them as they arrive.
     */
    private function draw(DesignCollection $collection, Store $store, ?int $only): void
    {
        $brief = $collection->brief;
        $ctx = new StoreContext($store);

        foreach (array_keys($collection->concepts ?? []) as $i) {
            $concept = $collection->fresh()->concepts[$i];
            if (($only !== null && $only !== $i) || ($only === null && ($concept['status'] ?? null) === 'ready')) {
                continue;
            }

            $this->updateConcept($collection, $i, ['status' => 'drawing', 'error' => null]);
            $render = $this->prompts->render($brief['render'] ?? 'ghost', $concept, $brief['modesty'] ?? 'strict');
            $useFabric = $collection->fabric_image_path && $render !== 'flat' && Storage::disk('public')->exists($collection->fabric_image_path);
            $copy = self::matches($brief) && $this->hasReference($collection);
            if ($copy && $useFabric && InlineImage::fromDisk((string) $collection->fabric_image_path) === null) {
                // Not JPEG, PNG or WebP, or too large: the fabric type describes it instead.
                $useFabric = false;
            }
            $prompt = $this->prompts->build($brief, $concept, (bool) $useFabric, $copy);
            // Copying a piece: Image 1 is the piece, Image 2 the merchant's fabric.
            $references = $copy ? [
                ['path' => (string) $collection->reference_image_path, 'label' => DesignerPrompt::LABEL_DESIGN],
                ...($useFabric ? [['path' => (string) $collection->fabric_image_path, 'label' => DesignerPrompt::LABEL_FABRIC]] : []),
            ] : [];

            try {
                $image = $this->ai->image('designer_render', $store, $prompt, ! $copy && $useFabric ? $collection->fabric_image_path : null,
                    FashionVocabulary::renders()[$render]['ratio'], $collection->user_id, $references);
            } catch (AiException $e) {
                $this->updateConcept($collection, $i, ['status' => 'failed', 'error' => $e->getMessage()]);

                continue;
            }

            $score = $this->engine->score(new Candidate(
                $concept['category'], $concept['price']['mid'] ?? null, $concept['colors'], $concept['pattern'],
                FashionVocabulary::fabrics()[$brief['fabric'] ?? '']['family'] ?? null, $concept['style'], $concept['season'],
            ), $ctx);

            $previous = isset($concept['design_id']) ? Design::query()->whereKey((int) $concept['design_id'])->first() : null;
            $design = Design::query()->create([
                'store_id' => $store->id,
                'design_collection_id' => $collection->id,
                'user_id' => $collection->user_id,
                'title' => $concept['name_ar'] ?: $concept['name_en'],
                'image_path' => $image->path,
                'output_type' => ['ghost' => 'mockup_white', 'on_model' => 'mockup_model'][$render] ?? 'product_photo',
                'aspect_ratio' => FashionVocabulary::renders()[$render]['ratio'],
                'palette' => array_map(fn ($key) => Vocabulary::colors()[$key]['hex'] ?? $key, $concept['colors']),
                'tags' => array_values(array_filter(['designer', $brief['garment'], 'role:'.$concept['role'], $concept['category']])),
                'status' => 'draft',
                'score' => $score['score'],
                'provider' => $image->provider,
                'model' => $image->model,
                'cost' => $image->cost,
                'prompt' => $prompt,
            ]);
            // A redraw replaces the earlier image in the library too.
            $previous?->delete();

            $this->updateConcept($collection, $i, [
                'status' => 'ready',
                'render' => $render,
                'design_id' => $design->id,
                'image_path' => $image->path,
                'score' => $score['score'],
                'recommendation' => $score['recommendation'],
            ]);
            $collection->update(['cost' => (float) $collection->fresh()->cost + $image->cost]);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateConcept(DesignCollection $collection, int $i, array $changes): void
    {
        $concepts = $collection->fresh()->concepts ?? [];
        $concepts[$i] = [...$concepts[$i], ...$changes];
        $collection->update(['concepts' => $concepts]);
    }

    /**
     * Read the swatch: colours, print, sheen, drape. A visual estimate the
     * merchant can override; the declared fabric type always wins.
     *
     * @return array<string, mixed>|null
     */
    private function readFabric(DesignCollection $collection, Store $store): ?array
    {
        $disk = Storage::disk('public');
        if (! $disk->exists((string) $collection->fabric_image_path)) {
            return null;
        }

        $result = $this->ai->json(
            operation: 'designer_fabric',
            store: $store,
            system: 'You are a textile expert. Describe the fabric swatch in the photo for a fashion designer. '
                .'colors are up to three keys, most dominant first, chosen only from: '.implode(', ', array_keys(Vocabulary::colors())).'. '
                .'pattern is one key from: '.implode(', ', array_keys(Vocabulary::patterns())).'. '
                .'print_scale is small, medium or large; sheen is matte, soft or high; opacity is opaque, semi or sheer; drape is fluid, medium or crisp. '
                .'fabric_guess is one key from: '.implode(', ', array_keys(FashionVocabulary::fabrics())).', with confidence from 0 to 1. '
                .'motif_note_en describes the print in at most 15 words.',
            prompt: 'Describe this fabric swatch.',
            schema: ['type' => 'object', 'properties' => [
                'colors' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string']],
                'pattern' => ['type' => 'string'], 'print_scale' => ['type' => 'string'], 'sheen' => ['type' => 'string'],
                'opacity' => ['type' => 'string'], 'drape' => ['type' => 'string'], 'fabric_guess' => ['type' => 'string'],
                'confidence' => ['type' => 'number'], 'motif_note_en' => ['type' => 'string'],
            ], 'required' => ['colors', 'pattern', 'print_scale', 'sheen', 'opacity', 'drape', 'fabric_guess', 'confidence', 'motif_note_en']],
            fallback: fn () => [],
            images: [['mime' => $disk->mimeType($collection->fabric_image_path) ?: 'image/jpeg', 'data' => base64_encode($disk->get($collection->fabric_image_path))]],
            userId: $collection->user_id,
            timeoutSeconds: 60,
        );

        if (! $result->fromModel) {
            return null;
        }
        $collection->update(['cost' => (float) $collection->cost + $result->cost]);
        $d = $result->data;
        $pick = fn ($v, array $allowed) => is_string($v) && in_array($v, $allowed, true) ? $v : null;

        return [
            'colors' => array_values(array_filter((array) ($d['colors'] ?? []), fn ($c) => is_string($c) && isset(Vocabulary::colors()[$c]))),
            'pattern' => $pick($d['pattern'] ?? null, array_keys(Vocabulary::patterns())),
            'print_scale' => $pick($d['print_scale'] ?? null, ['small', 'medium', 'large']),
            'sheen' => $pick($d['sheen'] ?? null, ['matte', 'soft', 'high']),
            'opacity' => $pick($d['opacity'] ?? null, ['opaque', 'semi', 'sheer']),
            'drape' => $pick($d['drape'] ?? null, ['fluid', 'medium', 'crisp']),
            'fabric_guess' => $pick($d['fabric_guess'] ?? null, array_keys(FashionVocabulary::fabrics())),
            'confidence' => is_numeric($d['confidence'] ?? null) ? round(max(0, min(1, (float) $d['confidence'])), 2) : null,
            'motif_note_en' => mb_substr($this->text($d['motif_note_en'] ?? null), 0, 120),
        ];
    }

    /**
     * Evidence the model may cite, each with a stable id. Numbers are
     * copied from the insights, so a concept's "why" is never invented.
     *
     * @param  array<string, mixed>  $insights
     * @return array<string, array<string, mixed>>
     */
    private function evidence(array $insights): array
    {
        $out = [];
        $put = function (string $id, string $kind, array $facts, string $claimAr, string $claimEn, ?string $href = null, ?string $image = null, ?string $caveat = null) use (&$out) {
            $out[$id] = ['id' => $id, 'kind' => $kind, ...$facts, 'claim_ar' => $claimAr, 'claim_en' => $claimEn, 'href' => $href, 'image_url' => $image, 'caveat' => $caveat];
        };

        foreach ($insights['bestsellers'] as $p) {
            $put("P:{$p['id']}", 'my_bestseller', ['name' => $p['name'], 'units_3m' => $p['units'], 'orders_3m' => $p['orders'], 'colors' => $p['colors'], 'pattern' => $p['pattern']],
                "«{$p['name']}» من الأكثر مبيعًا عندك: {$p['units']} قطعة في آخر 3 أشهر.", "“{$p['name']}” is one of your best sellers: {$p['units']} units in the last 3 months.",
                "/store/products/{$p['id']}", $p['image_url']);
        }
        foreach ($insights['rising'] as $p) {
            $put("R:{$p['id']}", 'my_rising', ['name' => $p['name'], 'units_3m' => $p['units'], 'share_change_pct' => $p['change_pct']],
                "«{$p['name']}» يصعد: +{$p['change_pct']}% من حصته في المبيعات.", "“{$p['name']}” is rising: +{$p['change_pct']}% share of sales.",
                "/store/products/{$p['id']}", $p['image_url']);
        }
        foreach ($insights['dna'] as $d) {
            $label = Vocabulary::label($this->vocabGroup($d['attribute']), $d['value'], 'ar') ?? $d['value'];
            $labelEn = Vocabulary::label($this->vocabGroup($d['attribute']), $d['value'], 'en') ?? $d['value'];
            $put("D:{$d['attribute']}:{$d['value']}", 'dna', ['attribute' => $d['attribute'], 'value' => $d['value'], 'sales_index' => $d['index'], 'products' => $d['products']],
                "«{$label}» من بصمتك الرابحة: يبيع {$d['index']}% من حصته في الكتالوج ({$d['products']} منتجات).", "“{$labelEn}” is part of your winning DNA: sells at {$d['index']}% of its catalog share ({$d['products']} products).");
        }
        foreach ($insights['competitor_hits'] as $h) {
            $put("C:{$h['id']}", 'competitor_hit', ['competitor' => $h['competitor'], 'name' => $h['name'], 'signals' => $h['signals'], 'colors' => $h['colors'], 'pattern' => $h['pattern'], 'style' => $h['style']],
                "«{$h['name']}» لدى {$h['competitor']}: ".$this->signalText($h['signals'], 'ar').'.', "“{$h['name']}” at {$h['competitor']}: ".$this->signalText($h['signals'], 'en').'.',
                $h['url'], $h['image_url'], in_array('best_seller_label', $h['signals'], true) ? 'merchant_claim' : null);
        }
        foreach ($insights['social'] as $s) {
            $put("S:{$s['id']}", 'social_post', ['competitor' => $s['competitor'], 'times_usual_engagement' => $s['times_median'], 'colors' => $s['colors'], 'pattern' => $s['pattern']],
                "منشور لـ{$s['competitor']} بتفاعل {$s['times_median']}× معدّله المعتاد.", "A {$s['competitor']} post at {$s['times_median']}× its usual engagement.",
                $s['url'], $s['thumbnail_url'], 'engagement_not_sales');
        }
        foreach ($insights['gaps'] as $g) {
            $labelAr = Vocabulary::label($this->vocabGroup($g['attribute']), $g['value'], 'ar') ?? $g['label'];
            $put("G:{$g['attribute']}:{$g['value']}", 'gap', ['attribute' => $g['attribute'], 'value' => $g['value'], 'market_pct' => $g['market_pct'], 'mine_pct' => $g['mine_pct'], 'source' => $g['source']],
                $g['market_pct'] !== null ? "«{$labelAr}» في {$g['market_pct']}% من المنتجات الرائجة لدى المنافسين مقابل {$g['mine_pct']}% عندك." : "«{$labelAr}» توجّه صاعد غير موجود في كتالوجك.",
                $g['market_pct'] !== null ? "“{$g['label']}” is in {$g['market_pct']}% of competitor hits vs {$g['mine_pct']}% of yours." : "“{$g['label']}” is a rising trend you don't carry.");
        }
        foreach ($insights['seasons'] as $e) {
            $put("E:{$e['id']}", 'season', ['season' => $e['key'], 'days_until' => $e['days_until'], 'ready_products' => $e['ready_products'], 'palette' => $e['palette']],
                "{$e['name']} ".DesignerInsights::daysAr($e['days_until']).'.', "{$e['name']} in {$e['days_until']} days.");
        }
        foreach ($insights['fading'] as $p) {
            $put("F:{$p['id']}", 'avoid', ['name' => $p['name'], 'share_change_pct' => $p['change_pct'], 'colors' => $p['colors'], 'pattern' => $p['pattern']],
                "«{$p['name']}» يتراجع ({$p['change_pct']}%).", "“{$p['name']}” is fading ({$p['change_pct']}%).", "/store/products/{$p['id']}", $p['image_url']);
        }

        return $out;
    }

    /**
     * Keep only allowed keys, attach evidence, production estimates and a
     * price band, and fill anything missing with sensible defaults.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $brief
     * @param  array<string, array<string, mixed>>  $packet
     * @param  array<string, list<string>>  $allowed
     * @return array<string, mixed>
     */
    private function clean(array $raw, string $role, int $index, array $brief, array $packet, array $allowed, Store $store, ?array $reference = null): array
    {
        $garment = $brief['garment'];
        $pick = fn ($value, string $field, string $default) => is_string($value) && in_array($value, $allowed[$field], true) ? $value : $default;
        $defaults = $this->defaults($garment, $role, $index);
        $fabric = FashionVocabulary::fabrics()[$brief['fabric'] ?? ''] ?? null;

        $colors = array_values(array_unique(array_filter((array) ($raw['colors'] ?? []), fn ($c) => is_string($c) && in_array($c, $allowed['colors'], true))));
        $colors = array_slice($colors ?: ($brief['colors'] ?: ['black']), 0, 3);
        $embellishments = array_values(array_unique(array_filter((array) ($raw['embellishments'] ?? []), fn ($e) => is_string($e) && in_array($e, $allowed['embellishment'], true))));
        $embellishments = array_slice(array_values(array_diff($embellishments ?: [$defaults['embellishment']], count($embellishments) > 1 ? ['none'] : [])), 0, 2);

        $ids = array_values(array_unique(array_filter((array) ($raw['evidence_ids'] ?? []), fn ($id) => is_string($id) && isset($packet[$id]))));
        if ($brief['inspiration']['evidence_id'] ?? null) {
            // Every copy of a piece rests on it; otherwise the first concept does.
            $ids = array_values(array_unique([...($index === 0 || $reference !== null ? [$brief['inspiration']['evidence_id']] : []), ...$ids]));
            $ids = array_values(array_filter($ids, fn ($id) => isset($packet[$id])));
        }
        $evidence = array_map(fn (string $id) => Arr::only($packet[$id], ['id', 'kind', 'claim_ar', 'claim_en', 'href', 'image_url', 'caveat']), array_slice($ids, 0, 4));

        $concept = [
            'index' => $index,
            'role' => $role,
            'source' => $this->source($role, $evidence),
            'name_ar' => $this->text($raw['name_ar'] ?? null) ?: $defaults['name_ar'],
            'name_en' => $this->text($raw['name_en'] ?? null) ?: $defaults['name_en'],
            'pitch_ar' => $this->text($raw['pitch_ar'] ?? null),
            'pitch_en' => $this->text($raw['pitch_en'] ?? null),
            'silhouette' => $pick($raw['silhouette'] ?? null, 'silhouette', $defaults['silhouette']),
            'neckline' => $pick($raw['neckline'] ?? null, 'neckline', $defaults['neckline']),
            'sleeve' => $pick($raw['sleeve'] ?? null, 'sleeve', $defaults['sleeve']),
            'length' => $pick($raw['length'] ?? null, 'length', $garment === 'kimono' ? 'ankle' : 'floor'),
            'closure' => $pick($raw['closure'] ?? null, 'closure', $defaults['closure']),
            'embellishments' => $embellishments,
            'placement' => $pick($raw['placement'] ?? null, 'placement', 'cuffs'),
            'colors' => $colors,
            'pattern' => $pick($raw['pattern'] ?? null, 'pattern', $brief['fabric_read']['pattern'] ?? 'solid'),
            'style' => $pick($raw['style'] ?? null, 'style', $defaults['style']),
            'fabric' => $brief['fabric'] ?? null,
            'lining' => ($fabric['sheer'] ?? false) ? 'full' : (in_array($garment, ['abaya', 'kimono', 'prayer_set'], true) ? 'none' : 'partial'),
            'rationale_ar' => $this->text($raw['rationale_ar'] ?? null),
            'rationale_en' => $this->text($raw['rationale_en'] ?? null),
            'risk_ar' => $this->text($raw['risk_ar'] ?? null),
            'risk_en' => $this->text($raw['risk_en'] ?? null),
            'finishing_ar' => $this->text($raw['finishing_ar'] ?? null),
            'finishing_en' => $this->text($raw['finishing_en'] ?? null),
            'design_notes_en' => mb_substr($this->text($raw['design_notes_en'] ?? null), 0, 300),
            'evidence' => $evidence,
            // The store section the merchant picked, else the one matching the garment.
            'category' => $brief['category'] ?? $this->category($store, $garment),
            'season' => FashionVocabulary::occasions()[$brief['occasion'] ?? 'daily']['season'] ?? 'all_year',
            'status' => 'pending',
            'error' => null,
        ];

        if ($reference !== null) {
            $modesty = $brief['modesty'] ?? 'strict';
            $concept = $this->followReference($concept, $raw, $reference, $index, $modesty, $allowed);
            // Measured against the piece: every key the level does not allow is
            // changed to the nearest allowed one (or the variant's own allowed
            // choice) and recorded, so the prompt names each change.
            $concept['modesty_changes'] = [];
            foreach (['neckline', 'sleeve', 'length', 'silhouette'] as $field) {
                $original = $reference[$field];
                if (in_array($original, $allowed[$field], true)) {
                    continue;
                }
                if ($concept[$field] === $original || ! in_array($concept[$field], $allowed[$field], true)) {
                    $concept[$field] = FashionVocabulary::nearestAllowed($field, $original, $modesty, $allowed[$field]);
                }
                $concept['modesty_changes'][] = ['field' => $field, 'from' => $original, 'to' => $concept[$field]];
            }
            $concept['variant_of_en'] = $index === 0 ? '' : mb_substr($this->text($raw['variant_of_en'] ?? null), 0, 200);
            $concept['design_notes_en'] = mb_substr($this->text($raw['design_notes_en'] ?? null), 0, 600);
            $concept = $this->withoutBrand($concept, $brief['inspiration']['brand'] ?? null, $defaults);
        } else {
            // Anything the modesty level leaves out falls back to a covered choice.
            $forbidden = FashionVocabulary::forbidden($brief['modesty'] ?? 'strict');
            $safe = ['neckline' => 'round', 'sleeve' => 'straight', 'length' => $garment === 'coord_set' ? 'ankle' : 'floor',
                'silhouette' => collect($allowed['silhouette'])->first() ?? $concept['silhouette']];
            foreach ($safe as $field => $fallback) {
                if (in_array($concept[$field], $forbidden[$field] ?? [], true)) {
                    $concept[$field] = $fallback;
                }
            }
            // Tunic length belongs to co-ord sets.
            if ($concept['length'] === 'tunic' && $garment !== 'coord_set' && ($brief['modesty'] ?? 'strict') === 'strict') {
                $concept['length'] = 'floor';
            }
        }

        return [
            ...$concept,
            'production' => FashionVocabulary::production($garment, $concept),
            'price' => $this->priceBand($store, $concept['category'], $brief['tier'] ?? null),
        ];
    }

    /**
     * Every pair of concepts should differ in cut: repeat silhouettes or
     * sleeves are moved to an unused option for the garment.
     *
     * @param  list<array<string, mixed>>  $concepts
     * @param  array<string, list<string>>  $allowed
     * @return list<array<string, mixed>>
     */
    private function diversify(array $concepts, string $garment, array $allowed): array
    {
        // Only within what the modesty level allows.
        $silhouettes = $allowed['silhouette'];
        $sleeves = $allowed['sleeve'];
        $usedS = [];
        $usedV = [];

        foreach ($concepts as $i => $c) {
            if (in_array($c['silhouette'], $usedS, true) && count($silhouettes) > count($usedS)) {
                $c['silhouette'] = collect($silhouettes)->first(fn ($s) => ! in_array($s, $usedS, true));
                $c['production'] = FashionVocabulary::production($garment, $c);
            }
            if (in_array($c['sleeve'], $usedV, true)) {
                $c['sleeve'] = collect($sleeves)->first(fn ($s) => ! in_array($s, $usedV, true)) ?? $c['sleeve'];
            }
            $usedS[] = $c['silhouette'];
            $usedV[] = $c['sleeve'];
            $concepts[$i] = $c;
        }

        return $concepts;
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     */
    private function source(string $role, array $evidence): string
    {
        $kinds = array_column($evidence, 'kind');

        return match (true) {
            in_array($role, ['match', 'match_variant'], true) => 'competitors',
            $kinds === [] => 'creative',
            $role === 'competitor_gap' && array_intersect($kinds, ['gap', 'competitor_hit', 'social_post']) !== [] => 'competitors',
            array_intersect($kinds, ['my_bestseller', 'my_rising', 'dna']) !== [] && $role === 'safe_bet' => 'own_sales',
            in_array('gap', $kinds, true) || in_array('competitor_hit', $kinds, true) => 'competitors',
            in_array('season', $kinds, true) && $role !== 'safe_bet' => 'season',
            array_intersect($kinds, ['my_bestseller', 'my_rising', 'dna']) !== [] => 'own_sales',
            default => $role === 'signature' ? 'creative' : 'trend',
        };
    }

    /**
     * The store's own price range for the garment's category: P25–P75 by
     * default, or the band for the chosen tier.
     *
     * @return array{min: float, max: float, mid: float}|null
     */
    private function priceBand(Store $store, string $category, ?string $tier): ?array
    {
        $prices = Product::query()->where('store_id', $store->id)->where('category', $category)->where('price', '>', 0)
            ->pluck('price')->map(fn ($p) => (float) $p)->sort()->values();
        if ($prices->count() < 3) {
            $prices = Product::query()->where('store_id', $store->id)->where('price', '>', 0)->pluck('price')->map(fn ($p) => (float) $p)->sort()->values();
        }
        if ($prices->count() < 3) {
            return null;
        }

        $q = fn (float $p) => $prices[(int) floor(($prices->count() - 1) * $p)];
        [$min, $max] = match ($tier) {
            'economy' => [$q(0), $q(0.25)],
            'premium' => [$q(0.75), $q(0.9)],
            'luxury' => [$q(0.9), $q(1)],
            default => [$q(0.25), $q(0.75)],
        };

        return ['min' => round($min), 'max' => round($max), 'mid' => round(($min + $max) / 2)];
    }

    private function category(Store $store, string $garment): string
    {
        $words = FashionVocabulary::garments()[$garment]['store_words'] ?? [];
        $categories = Product::query()->where('store_id', $store->id)->whereNotNull('category')->distinct()->pluck('category');

        return $categories->first(fn (string $c) => collect($words)->contains(fn ($w) => str_contains(mb_strtolower($c), mb_strtolower($w))))
            ?? FashionVocabulary::garments()[$garment]['ar'];
    }

    /**
     * @return array<string, list<string>>
     */
    private function allowed(string $garment, string $modesty = 'strict'): array
    {
        $details = FashionVocabulary::details();
        $forbidden = FashionVocabulary::forbidden($modesty);
        $keep = fn (array $keys, string $field) => array_values(array_diff($keys, $forbidden[$field] ?? []));

        return [
            'silhouette' => $keep(array_keys(FashionVocabulary::silhouettes()[$garment] ?? []), 'silhouette'),
            'neckline' => $keep(array_keys($details['neckline']), 'neckline'),
            'sleeve' => $keep(array_keys($details['sleeve']), 'sleeve'),
            'length' => $keep(array_keys($details['length']), 'length'),
            'closure' => array_keys($details['closure']),
            'embellishment' => array_keys($details['embellishment']),
            'placement' => array_keys($details['placement']),
            'colors' => array_keys(Vocabulary::colors()),
            // Script on a garment is garbled by the image model and may carry religious text.
            'pattern' => array_values(array_diff(array_keys(Vocabulary::patterns()), ['calligraphy'])),
            'style' => array_keys(Vocabulary::styles()),
        ];
    }

    /**
     * Gemini caps response-schema complexity: flat string fields, no enums
     * (the prompt lists the allowed keys and clean() drops anything else).
     *
     * @return array<string, mixed>
     */
    public function schema(int $count, bool $match = false): array
    {
        $string = ['type' => 'string'];
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $fields = ['role', 'name_ar', 'name_en', 'pitch_ar', 'pitch_en', 'silhouette', 'neckline', 'sleeve', 'length', 'closure',
            'placement', 'pattern', 'style', 'rationale_ar', 'rationale_en', 'risk_ar', 'risk_en', 'finishing_ar', 'finishing_en', 'design_notes_en',
            ...($match ? ['variant_of_en'] : [])];
        // What the picture shows, flat at the top level (Gemini's schema limits).
        $reference = $match ? [
            ...array_fill_keys(['ref_garment', 'ref_silhouette', 'ref_neckline', 'ref_sleeve', 'ref_length', 'ref_closure', 'ref_placement', 'ref_construction_en', 'ref_visible'], $string),
            'ref_embellishments' => $strings, 'ref_is_garment' => ['type' => 'boolean'],
        ] : [];

        return [
            'type' => 'object',
            'properties' => [
                'title_ar' => $string, 'title_en' => $string, 'story_ar' => $string, 'story_en' => $string, 'fabric_en' => $string,
                ...$reference,
                'concepts' => ['type' => 'array', 'maxItems' => $count, 'items' => [
                    'type' => 'object',
                    'properties' => [
                        ...array_fill_keys($fields, $string),
                        'colors' => $strings, 'embellishments' => $strings, 'evidence_ids' => $strings,
                    ],
                    'required' => [...$fields, 'colors', 'embellishments', 'evidence_ids'],
                ]],
            ],
            'required' => ['title_ar', 'title_en', 'story_ar', 'story_en', 'concepts', ...array_keys($reference)],
        ];
    }

    /**
     * The construction keys the planner may use to describe the piece as it
     * is, before any modesty change: every garment and its silhouettes.
     *
     * @return array<string, mixed>
     */
    private function referenceKeys(): array
    {
        $details = FashionVocabulary::details();

        return [
            'garments' => array_keys(FashionVocabulary::garments()),
            'silhouettes_by_garment' => array_map('array_keys', FashionVocabulary::silhouettes()),
            ...array_map('array_keys', Arr::only($details, ['neckline', 'sleeve', 'length', 'closure', 'placement', 'embellishment'])),
        ];
    }

    /**
     * Image 1 (the piece) and Image 2 (the fabric, when there is one) for
     * the planner, labelled so the instructions can name them.
     *
     * @return list<array{mime: string, data: string, label: string}>
     */
    private function plannerImages(DesignCollection $collection): array
    {
        $images = [];
        if ($design = InlineImage::fromDisk($collection->reference_image_path, 1024)) {
            $images[] = [...$design, 'label' => DesignerPrompt::LABEL_DESIGN];
        }
        if ($images !== [] && $collection->fabric_image_path && ($fabric = InlineImage::fromDisk($collection->fabric_image_path, 1024))) {
            $images[] = [...$fabric, 'label' => DesignerPrompt::LABEL_FABRIC];
        }

        return $images;
    }

    private function hasReference(DesignCollection $collection): bool
    {
        return filled($collection->reference_image_path) && Storage::disk('public')->exists((string) $collection->reference_image_path);
    }

    /**
     * The piece as the planner read it, every key checked against the full
     * vocabulary (modesty is applied per concept, change by change).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function reference(array $data, string $garment): array
    {
        $details = FashionVocabulary::details();
        $d = $this->defaults($garment, 'match', 0);
        $pick = fn ($value, array $keys, string $default) => is_string($value) && array_key_exists($value, $keys) ? $value : $default;
        $embellishments = array_values(array_unique(array_filter((array) ($data['ref_embellishments'] ?? []),
            fn ($e) => is_string($e) && isset($details['embellishment'][$e]))));
        if (count($embellishments) > 1) {
            $embellishments = array_values(array_diff($embellishments, ['none']));
        }

        return [
            'silhouette' => $pick($data['ref_silhouette'] ?? null, FashionVocabulary::silhouettes()[$garment] ?? [], $d['silhouette']),
            'neckline' => $pick($data['ref_neckline'] ?? null, $details['neckline'], $d['neckline']),
            'sleeve' => $pick($data['ref_sleeve'] ?? null, $details['sleeve'], $d['sleeve']),
            'length' => $pick($data['ref_length'] ?? null, $details['length'], $garment === 'kimono' ? 'ankle' : 'floor'),
            'closure' => $pick($data['ref_closure'] ?? null, $details['closure'], $d['closure']),
            'placement' => $pick($data['ref_placement'] ?? null, $details['placement'], $d['placement']),
            'embellishments' => array_slice($embellishments ?: [$d['embellishment']], 0, 3),
            'construction_en' => mb_substr($this->text($data['ref_construction_en'] ?? null), 0, 700),
            'visible' => in_array($data['ref_visible'] ?? null, ['upper', 'lower'], true) ? $data['ref_visible'] : 'full',
        ];
    }

    /**
     * A copy keeps the piece's construction. The first concept is the piece
     * as it is; each later one may change one of length, sleeve, closure
     * or embellishment, in that order of preference, and nothing else. The
     * planner's own modesty fix (the nearest allowed key) is not a change:
     * it is applied, and recorded, by clean().
     *
     * @param  array<string, mixed>  $concept
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $reference
     * @param  array<string, list<string>>  $allowed
     * @return array<string, mixed>
     */
    private function followReference(array $concept, array $raw, array $reference, int $index, string $modesty, array $allowed): array
    {
        foreach (['silhouette', 'neckline', 'sleeve', 'length', 'closure', 'placement', 'embellishments'] as $field) {
            $concept[$field] = $reference[$field];
        }
        if ($index === 0) {
            return $concept;
        }

        $details = FashionVocabulary::details();
        foreach (['length', 'sleeve', 'closure'] as $field) {
            $value = $raw[$field] ?? null;
            $base = $field === 'closure' ? $reference[$field] : FashionVocabulary::nearestAllowed($field, $reference[$field], $modesty, $allowed[$field]);
            if (is_string($value) && $value !== $reference[$field] && $value !== $base && in_array($value, $allowed[$field], true)) {
                $concept[$field] = $value;

                return $concept;
            }
        }
        $embellishments = array_values(array_unique(array_filter((array) ($raw['embellishments'] ?? []), fn ($e) => is_string($e) && isset($details['embellishment'][$e]))));
        if ($embellishments !== [] && $embellishments !== $reference['embellishments']) {
            $concept['embellishments'] = array_slice($embellishments, 0, 3);
        }

        return $concept;
    }

    /**
     * The competitor's name never ends up in the merchant's own design: as a
     * whole word only ("ريم" stays in "كريمي"), and a name left empty takes
     * the role's default.
     *
     * @param  array<string, mixed>  $concept
     * @param  array<string, string>  $defaults
     * @return array<string, mixed>
     */
    private function withoutBrand(array $concept, ?string $brand, array $defaults): array
    {
        $brand = trim((string) $brand);
        if (mb_strlen($brand) < 2) {
            return $concept;
        }
        foreach (['name_ar', 'name_en', 'pitch_ar', 'pitch_en'] as $field) {
            $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($brand, '/').'(?![\p{L}\p{N}])/iu', '', (string) $concept[$field]) ?? (string) $concept[$field];
            $concept[$field] = trim((string) preg_replace(['/\s{2,}/u', '/\s+([،,.:؛])/u', '/^[\s،,.:؛\-–—]+|[\s،,:؛\-–—]+$/u'], [' ', '$1', ''], $text));
        }
        foreach (['name_ar', 'name_en'] as $field) {
            $concept[$field] = $concept[$field] !== '' ? $concept[$field] : $defaults[$field];
        }

        return $concept;
    }

    /**
     * The brief's notes without the automatic "design like this" note, for
     * a brief that no longer copies the piece.
     */
    public static function withoutCopyNote(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' || in_array($notes, [DesignerPrompt::matchNote('ar'), DesignerPrompt::matchNote('en')], true) ? null : $notes;
    }

    /**
     * The original piece as evidence, so every copy shows what it rests on.
     *
     * @param  array<string, mixed>  $inspiration
     * @return array<string, array<string, mixed>>
     */
    private function inspirationEvidence(Store $store, array $inspiration): array
    {
        $id = (int) ($inspiration['id'] ?? 0);

        if (($inspiration['kind'] ?? null) === 'competitor' && ($p = CompetitorProduct::query()->where('store_id', $store->id)->with('competitor:id,name')->find($id))) {
            $brand = $p->competitor?->name ?? '—';

            return ["C:{$p->id}" => ['id' => "C:{$p->id}", 'kind' => 'competitor_hit', 'competitor' => $brand, 'name' => $p->name,
                'claim_ar' => "التصميم الأصلي: «{$p->name}» لدى {$brand}.", 'claim_en' => "The original design: “{$p->name}” at {$brand}.",
                'href' => $p->url, 'image_url' => $p->image_url, 'caveat' => null]];
        }
        if (($inspiration['kind'] ?? null) === 'social' && ($post = SocialPost::query()->where('store_id', $store->id)->with('competitor:id,name')->find($id))) {
            $brand = $post->competitor?->name ?? '—';

            return ["S:{$post->id}" => ['id' => "S:{$post->id}", 'kind' => 'social_post', 'competitor' => $brand,
                'claim_ar' => "التصميم الأصلي: منشور لـ{$brand}.", 'claim_en' => "The original design: a {$brand} post.",
                'href' => $post->url, 'image_url' => CompetitorRadar::image($post), 'caveat' => 'engagement_not_sales']];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    private function briefForModel(array $brief): array
    {
        $fabric = FashionVocabulary::fabrics()[$brief['fabric'] ?? ''] ?? null;
        $custom = filled($brief['fabric_custom'] ?? null) ? ['custom_name' => $brief['fabric_custom'], 'like' => $fabric['en'] ?? null] : [];

        return [
            'garment' => $brief['garment'],
            'garment_label' => FashionVocabulary::label('garments', $brief['garment'], 'en'),
            'fabric' => $fabric || $custom
                ? [...$custom, 'key' => $brief['fabric'] ?? null, 'name' => $fabric['en'] ?? null, 'sheer' => $fabric['sheer'] ?? false, 'drape' => $fabric['drape_en'] ?? null]
                : null,
            'fabric_photo' => $brief['fabric_read'] ?? null,
            'colors' => $brief['colors'] ?? [],
            'occasion' => $brief['occasion'] ?? 'daily',
            'age' => $brief['age'] ?? 'adult',
            'modesty' => $brief['modesty'] ?? 'strict',
            'store_category' => $brief['category'] ?? null,
            'creativity_1_to_5' => (int) $brief['creativity'],
            'price_tier' => $brief['tier'] ?? 'mid',
            'merchant_notes' => $brief['notes'] ?? null,
            'inspiration' => $brief['inspiration'] ?? null,
        ];
    }

    /**
     * Deterministic collection for demo mode or when the model fails:
     * built from the brief, the store's DNA and the roles.
     *
     * @param  array<string, mixed>  $brief
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $insights
     * @return array<string, mixed>
     */
    private function fallback(array $brief, array $roles, array $insights): array
    {
        $garment = $brief['garment'];
        $g = FashionVocabulary::garments()[$garment];
        $fabric = FashionVocabulary::fabrics()[$brief['fabric'] ?? ''] ?? null;
        // The merchant's own fabric names the collection when there is one.
        $fabricAr = trim((string) ($brief['fabric_custom'] ?? '')) ?: ($fabric['ar'] ?? null);
        $fabricEn = trim((string) ($brief['fabric_custom_en'] ?? '')) ?: (trim((string) ($brief['fabric_custom'] ?? '')) ?: ($fabric['en'] ?? null));
        $colors = ($brief['colors'] ?? []) ?: $this->dnaColors($insights);
        $trend = collect($insights['gaps'])->firstWhere('attribute', 'pattern') ?? collect($insights['gaps'])->first();
        $gap = collect($insights['gaps'])->firstWhere('source', 'competitors');
        $best = $insights['bestsellers'][0] ?? null;
        $season = $insights['seasons'][0] ?? null;

        $concepts = array_map(function (string $role, int $i) use ($garment, $g, $fabric, $fabricAr, $fabricEn, $colors, $trend, $gap, $best, $season) {
            $d = $this->defaults($garment, $role, $i);
            $silAr = FashionVocabulary::label('silhouettes', $d['silhouette'], 'ar', $garment);
            $silEn = FashionVocabulary::label('silhouettes', $d['silhouette'], 'en', $garment);
            $inAr = $fabricAr ? " من {$fabricAr}" : '';
            $inEn = $fabricEn ? " in {$fabricEn}" : '';
            $pattern = $role === 'trend_twist' && $trend && $trend['attribute'] === 'pattern' ? $trend['value'] : null;
            $roleColors = match ($role) {
                'competitor_gap' => $gap && $gap['attribute'] === 'colors' ? [$gap['value'], ...array_slice($colors, 0, 1)] : $colors,
                'signature' => [...array_slice($colors, 0, 1), 'gold'],
                default => $colors,
            };
            $evidence = array_values(array_filter([
                $role === 'safe_bet' && $best ? "P:{$best['id']}" : null,
                $role === 'trend_twist' && $trend ? "G:{$trend['attribute']}:{$trend['value']}" : null,
                $role === 'competitor_gap' && $gap ? "G:{$gap['attribute']}:{$gap['value']}" : null,
                $role !== 'safe_bet' && $season ? "E:{$season['id']}" : null,
            ]));

            return [
                'role' => $role,
                'name_ar' => "{$g['ar']} {$silAr}{$inAr}",
                'name_en' => "{$silEn} {$g['en']}{$inEn}",
                'pitch_ar' => match ($role) {
                    'safe_bet' => 'قصّة مجرّبة بنفس بصمة منتجاتك الرابحة، بقماشك الجديد.',
                    'trend_twist' => 'أساس مضمون مع لمسة واحدة من توجّه صاعد في السوق.',
                    'competitor_gap' => 'تركيبة تنجح لدى المنافسين وغير موجودة في كتالوجك.',
                    'match' => 'نفس تصميم القطعة الأصلية، بقماشك.',
                    'match_variant' => 'نفس التصميم بتغيير واحد مدروس.',
                    default => 'قطعة توقيع جريئة تميّز متجرك.',
                },
                'pitch_en' => match ($role) {
                    'safe_bet' => 'A proven cut with your best sellers\' DNA, in the new fabric.',
                    'trend_twist' => 'A safe base with one touch from a rising trend.',
                    'competitor_gap' => 'A combination that works for competitors and is missing from your catalog.',
                    'match' => 'The original piece\'s design, in your fabric.',
                    'match_variant' => 'The same design with one considered change.',
                    default => 'A bold signature piece that sets your store apart.',
                },
                'silhouette' => $d['silhouette'], 'neckline' => $d['neckline'], 'sleeve' => $d['sleeve'],
                'length' => $garment === 'kimono' ? 'ankle' : 'floor', 'closure' => $d['closure'],
                'embellishments' => [$d['embellishment']], 'placement' => $d['placement'],
                'colors' => array_values(array_unique(array_slice($roleColors, 0, 3))), 'pattern' => $pattern, 'style' => $d['style'],
                'rationale_ar' => null, 'rationale_en' => null, 'risk_ar' => null, 'risk_en' => null,
                'finishing_ar' => ($fabric['sheer'] ?? false) ? 'خياطة فرنسية للحواف وبطانة كاملة.' : 'حواف نظيفة مع تثبيت الأساور بحشوة خفيفة.',
                'finishing_en' => ($fabric['sheer'] ?? false) ? 'French seams and a full lining.' : 'Clean hems with light interfacing at the cuffs.',
                'design_notes_en' => '', 'evidence_ids' => $evidence,
            ];
        }, $roles, array_keys($roles));

        $occasion = FashionVocabulary::occasions()[$brief['occasion'] ?? 'daily'] ?? FashionVocabulary::occasions()['daily'];

        return [
            'title_ar' => "مجموعة {$occasion['ar']}".($fabricAr ? " — {$fabricAr}" : ''),
            'title_en' => "{$occasion['en']} collection".($fabricEn ? " — {$fabricEn}" : ''),
            'story_ar' => "{$g['ar']} بأربع قراءات مختلفة للقماش نفسه: من القصّة المضمونة إلى قطعة التوقيع، مبنية على ما يبيع عندك وما يتحرك في السوق.",
            'story_en' => "{$g['en']} in distinct takes on the same fabric — from the proven cut to the signature piece — built on what sells for you and what moves in the market.",
            'concepts' => $concepts,
        ];
    }

    /**
     * Sensible defaults per role, so each concept starts from a different cut.
     *
     * @return array<string, string>
     */
    private function defaults(string $garment, string $role, int $index): array
    {
        $silhouettes = array_keys(FashionVocabulary::silhouettes()[$garment] ?? ['straight' => []]);
        $byRole = [
            'safe_bet' => ['neckline' => 'round', 'sleeve' => 'straight', 'closure' => 'hidden_snaps', 'embellishment' => 'none', 'placement' => 'cuffs', 'style' => 'classic'],
            'trend_twist' => ['neckline' => 'mandarin', 'sleeve' => 'bell', 'closure' => 'open_front', 'embellishment' => 'trim_tape', 'placement' => 'sleeves', 'style' => 'modern'],
            'competitor_gap' => ['neckline' => 'shirt_collar', 'sleeve' => 'wide', 'closure' => 'tie_belt', 'embellishment' => 'panel_insert', 'placement' => 'front_placket', 'style' => 'minimal'],
            'signature' => ['neckline' => 'round', 'sleeve' => 'butterfly', 'closure' => 'hidden_buttons', 'embellishment' => 'machine_embroidery', 'placement' => 'neckline', 'style' => 'luxury'],
        ][in_array($role, ['match', 'match_variant'], true) ? 'safe_bet' : $role] ?? [];

        if ($garment === 'abaya' && $role !== 'trend_twist') {
            $byRole['closure'] = $role === 'safe_bet' ? 'hidden_snaps' : 'open_front';
        }

        return [
            ...$byRole,
            'silhouette' => $silhouettes[$index % count($silhouettes)],
            'name_ar' => FashionVocabulary::label('garments', $garment, 'ar').' '.FashionVocabulary::label('roles', $role, 'ar'),
            'name_en' => FashionVocabulary::label('roles', $role, 'en').' '.FashionVocabulary::label('garments', $garment, 'en'),
        ];
    }

    /**
     * @param  array<string, mixed>  $insights
     * @return list<string>
     */
    private function dnaColors(array $insights): array
    {
        $colors = collect($insights['dna'])->where('attribute', 'colors')->pluck('value')->take(2)->values()->all();

        return $colors ?: (collect($insights['bestsellers'])->pluck('colors')->flatten()->filter()->countBy()->sortDesc()->keys()->take(2)->values()->all() ?: ['black']);
    }

    /**
     * @param  list<string>  $signals
     */
    private function signalText(array $signals, string $locale): string
    {
        $labels = [
            'best_seller_label' => ['ضمن الأكثر مبيعًا لديه (ادّعاء من المتجر)', 'listed as a best seller (store\'s claim)'],
            'published_sales' => ['يعرض عدد مبيعات منشورًا', 'shows a published sales count'],
            'homepage_featured' => ['معروض في الصفحة الرئيسية', 'featured on the homepage'],
            'price_drop' => ['خُفّض سعره', 'price cut'],
            'new_arrival' => ['وصل حديثًا', 'new arrival'],
        ];

        return implode($locale === 'ar' ? '، ' : ', ', array_map(fn ($s) => $labels[$s][$locale === 'ar' ? 0 : 1] ?? $s, $signals));
    }

    private function vocabGroup(string $attribute): string
    {
        return ['colors' => 'colors', 'pattern' => 'patterns', 'material' => 'materials', 'style' => 'styles'][$attribute] ?? $attribute;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function notify(DesignCollection $collection, bool $failed): void
    {
        $user = User::query()->find($collection->user_id);
        $title = $collection->title_ar ?: '—';
        $ready = collect($collection->concepts ?? [])->where('status', 'ready')->count();

        $user?->notify(new PlatformNotification(
            kind: 'design',
            titleAr: $failed ? "تعذّر إكمال مجموعة «{$title}»" : "مجموعتك جاهزة: «{$title}»",
            titleEn: $failed ? "The collection “{$collection->title_en}” stopped" : "Your collection is ready: “{$collection->title_en}”",
            bodyAr: $failed ? $this->errorText($collection->error, 'ar') : "رسم المصمم الذكي {$ready} تصاميم مع بطاقاتها الفنية.",
            bodyEn: $failed ? $this->errorText($collection->error, 'en') : "The AI designer drew {$ready} designs with their design sheets.",
            url: "/store/studio?collection={$collection->id}",
            storeId: $collection->store_id,
            level: $failed ? 'danger' : 'success',
        ));
    }

    private function errorText(?string $error, string $locale): string
    {
        return $error === self::NOT_A_GARMENT ? (string) __('mojo.designer_reference_not_garment', [], $locale) : (string) $error;
    }
}
