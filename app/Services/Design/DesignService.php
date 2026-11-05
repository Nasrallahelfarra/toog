<?php

namespace App\Services\Design;

use App\Models\Design;
use App\Models\DesignRequest;
use App\Models\Evaluation;
use App\Models\Store;
use App\Models\Trend;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Ai\Ai;
use App\Services\Ai\AiException;
use App\Services\Evaluation\Candidate;
use App\Services\Evaluation\EvaluationService;
use App\Services\Evaluation\StoreContext;
use App\Support\Vocabulary;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The design engine (module 4): turns a request into several variants
 * (FR-4.9), generates each image, then runs every result through the
 * evaluation gate before it is offered for approval (FR-4.7).
 */
class DesignService
{
    public const VARIANTS = ['faithful', 'trend', 'heritage', 'minimal'];

    public function __construct(
        private readonly Ai $ai,
        private readonly PromptBuilder $prompts,
        private readonly EvaluationService $evaluations,
    ) {}

    public function generate(DesignRequest $request): void
    {
        $request->update(['status' => 'processing', 'error' => null]);

        $store = $request->store;
        $source = $request->evaluation_id ? Evaluation::query()->find($request->evaluation_id) : null;
        $ctx = new StoreContext($store);
        $product = $this->product($request, $source);
        $variants = $request->type === DesignRequest::REFINEMENT
            // An edit is the same change each time; the model's own variation tells the options apart.
            ? array_fill(0, max(1, min(4, $request->variants_count)), ['key' => 'refined', 'colors' => $product['colors'], 'pattern' => $product['pattern'],
                'material' => $product['material'], 'style' => $product['style'], 'placement' => 'as in the reference'])
            : array_slice($this->variants($product, $ctx), 0, max(1, min(4, $request->variants_count)));
        $title = $request->title ?: ($source?->name ?? 'Design');
        $created = 0;

        try {
            foreach ($variants as $i => $variant) {
                $prompt = $this->prompts->build($request, $store, $variant, $product, (bool) $request->source_image_path);
                $image = $this->ai->image('design', $store, $prompt, $request->source_image_path, $request->aspect_ratio, $request->user_id);

                $design = Design::query()->create([
                    'store_id' => $store->id,
                    'design_request_id' => $request->id,
                    'user_id' => $request->user_id,
                    'title' => $title.' · '.($i + 1),
                    'image_path' => $image->path,
                    'output_type' => $request->output_type,
                    'aspect_ratio' => $request->aspect_ratio,
                    'palette' => array_map(fn ($key) => Vocabulary::colors()[$key]['hex'] ?? $key, $variant['colors']),
                    'tags' => array_values(array_filter([$product['category'], 'variant:'.$variant['key'], 'type:'.$request->type])),
                    'status' => 'draft',
                    'provider' => $image->provider,
                    'model' => $image->model,
                    'cost' => $image->cost,
                    'prompt' => $prompt,
                ]);

                $this->autoEvaluate($design, $variant, $product, $ctx);
                $created++;
            }
        } catch (AiException $e) {
            $request->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->notify($request, $created, failed: true);

            return;
        } catch (Throwable $e) {
            report($e);
            $request->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return;
        }

        $request->update(['status' => 'completed']);
        $this->notify($request, $created);
    }

    /**
     * Product attributes for the request: the source evaluation with its
     * suggested modifications applied (FR-4.3), else store best-seller DNA.
     *
     * @return array{name: string|null, category: string|null, price: float|null, colors: list<string>, pattern: string|null, material: string|null, style: string|null, season: string|null}
     */
    public function product(DesignRequest $request, ?Evaluation $source): array
    {
        if ($recovery = $request->productRecommendation) {
            $snapshot = $recovery->snapshot;
            $features = $snapshot['features'] ?? [];

            return ['name' => $snapshot['name'], 'category' => $request->category ?: ($snapshot['category'] ?? null),
                'price' => isset($snapshot['price']) ? (float) $snapshot['price'] : null,
                'colors' => $features['colors'] ?? [], 'pattern' => $features['pattern'] ?? null,
                'material' => $features['material'] ?? null, 'style' => $features['style'] ?? null, 'season' => $features['season'] ?? null];
        }
        if ($source) {
            $candidate = Candidate::fromEvaluation($source);

            if ($request->type === 'modification') {
                foreach ($source->modifications ?? [] as $mod) {
                    $candidate = $candidate->with($mod['change'] ?? []);
                }
            }

            return [
                'name' => $source->name,
                'category' => $request->category ?: $candidate->category,
                'price' => $candidate->price,
                'colors' => $candidate->colors,
                'pattern' => $candidate->pattern,
                'material' => $candidate->material,
                'style' => $candidate->style,
                'season' => $candidate->season,
            ];
        }

        if ($request->type === DesignRequest::REFINEMENT && ($parent = $request->parentDesign)) {
            return ['name' => $request->title, 'category' => $request->category, 'price' => null,
                'colors' => collect($parent->palette ?? [])->map(fn ($hex) => $this->nearestColor((string) $hex))->unique()->values()->all(),
                'pattern' => null, 'material' => null, 'style' => null, 'season' => null];
        }

        $store = $request->store;
        $brandColors = collect($store->brand_guide['colors'] ?? [])->map(fn ($hex) => $this->nearestColor($hex))->unique()->values()->all();

        return [
            'name' => $request->title,
            'category' => $request->category,
            'price' => null,
            'colors' => array_slice($brandColors ?: ['beige', 'gold'], 0, 2),
            'pattern' => null,
            'material' => null,
            'style' => null,
            'season' => null,
        ];
    }

    /**
     * Four deliberately different takes on the same product.
     *
     * @param  array<string, mixed>  $product
     * @return list<array{key: string, colors: list<string>, pattern: string|null, material: string|null, style: string|null, placement: string}>
     */
    public function variants(array $product, StoreContext $ctx): array
    {
        $winners = $ctx->inCategory($ctx->winners, $product['category'])->pluck('candidate');
        $mode = fn (string $field) => $winners->pluck($field)->filter()->countBy()->sortDesc()->keys()->first();

        $colors = $product['colors'] ?: ['beige', 'gold'];
        $pattern = $product['pattern'] ?? $mode('pattern') ?? 'solid';
        $material = $product['material'] ?? $mode('material');
        $style = $product['style'] ?? $mode('style') ?? 'modern';

        /** @var Collection<int, Trend> $rising */
        $rising = $ctx->trends->where('status', 'rising')->sortByDesc('momentum');
        $trendColor = $rising->first(fn (Trend $t) => $t->type === 'color' && ! in_array($t->value, $colors, true))?->value;
        $trendPattern = $rising->first(fn (Trend $t) => $t->type === 'pattern' && $t->value !== $pattern)?->value;
        $heritagePattern = $rising->first(fn (Trend $t) => $t->type === 'pattern' && in_array($t->value, ['sadu', 'arabesque', 'calligraphy'], true))?->value ?? 'arabesque';

        return [
            ['key' => 'faithful', 'colors' => array_slice($colors, 0, 3), 'pattern' => $pattern, 'material' => $material, 'style' => $style,
                'placement' => 'as specified, centred on the main print area'],
            ['key' => 'trend', 'colors' => array_values(array_unique(array_filter([$trendColor, $colors[0]]))), 'pattern' => $trendPattern ?? $pattern, 'material' => $material, 'style' => $style,
                'placement' => 'subtle all-over repeat'],
            ['key' => 'heritage', 'colors' => array_values(array_unique([$colors[0], 'gold'])), 'pattern' => $heritagePattern, 'material' => $material, 'style' => 'heritage',
                'placement' => 'refined border along the hem, cuffs or edges'],
            ['key' => 'minimal', 'colors' => [$colors[0]], 'pattern' => 'solid', 'material' => $material, 'style' => 'minimal',
                'placement' => 'small tone-on-tone detail only'],
        ];
    }

    /**
     * FR-4.7: every generated design goes through the quality gate.
     *
     * @param  array<string, mixed>  $variant
     * @param  array<string, mixed>  $product
     */
    private function autoEvaluate(Design $design, array $variant, array $product, StoreContext $ctx): void
    {
        $evaluation = Evaluation::query()->create([
            'store_id' => $design->store_id,
            'user_id' => $design->user_id,
            'design_id' => $design->id,
            'source' => 'design',
            'name' => $design->title,
            'category' => $product['category'],
            'price' => $product['price'],
            'material' => $variant['material'],
            'colors' => $variant['colors'],
            'pattern' => $variant['pattern'],
            'style' => $variant['style'],
            'season' => $product['season'],
            'image_path' => $design->image_path,
            'status' => 'pending',
        ]);
        $evaluation->update(['root_id' => $evaluation->id]);

        $this->evaluations->run($evaluation, $ctx, notify: false);

        $design->update(['evaluation_id' => $evaluation->id, 'score' => $evaluation->fresh()->score]);
    }

    /**
     * Nearest vocabulary colour for a hex value (simple RGB distance).
     */
    public function nearestColor(string $hex): string
    {
        $rgb = fn (string $h) => array_map('hexdec', str_split(ltrim($h, '#'), 2));
        [$r, $g, $b] = $rgb(str_pad(ltrim($hex, '#'), 6, '0'));

        return collect(Vocabulary::colors())
            ->map(function ($c) use ($rgb, $r, $g, $b) {
                [$cr, $cg, $cb] = $rgb($c['hex']);

                return ($r - $cr) ** 2 + ($g - $cg) ** 2 + ($b - $cb) ** 2;
            })
            ->sort()
            ->keys()
            ->first();
    }

    private function notify(DesignRequest $request, int $created, bool $failed = false): void
    {
        $user = User::query()->find($request->user_id);
        $title = $request->title ?: '—';

        $user?->notify(new PlatformNotification(
            kind: 'design',
            titleAr: $failed ? "تعذّر إكمال توليد «{$title}»" : "التصاميم جاهزة: «{$title}»",
            titleEn: $failed ? "Design generation for “{$title}” stopped" : "Designs ready: “{$title}”",
            bodyAr: $failed ? (string) $request->error : "تم توليد {$created} بدائل وتقييمها تلقائيًا.",
            bodyEn: $failed ? (string) $request->error : "{$created} variants generated and auto-evaluated.",
            url: '/store/studio',
            storeId: $request->store_id,
            level: $failed ? 'danger' : 'success',
        ));
    }

    /**
     * The prefilled studio brief for "generate an improved version".
     */
    public function defaultPrompt(Evaluation $evaluation, string $locale): string
    {
        $label = fn (string $group, ?string $key) => $key ? Vocabulary::label($group, $key, $locale) : null;
        $ar = $locale === 'ar';
        $mods = collect($evaluation->modifications ?? []);

        $changes = $mods->map(function ($m) use ($label, $ar) {
            $c = $m['change'] ?? [];

            return collect([
                isset($c['colors']) ? ($ar ? 'الألوان: ' : 'colours: ').collect($c['colors'])->map(fn ($k) => $label('colors', $k))->implode($ar ? '، ' : ', ') : null,
                isset($c['pattern']) ? ($ar ? 'النقشة: ' : 'pattern: ').$label('patterns', $c['pattern']) : null,
                isset($c['material']) ? ($ar ? 'الخامة: ' : 'material: ').$label('materials', $c['material']) : null,
                isset($c['style']) ? ($ar ? 'الأسلوب: ' : 'style: ').$label('styles', $c['style']) : null,
                isset($c['season']) ? ($ar ? 'الموسم: ' : 'season: ').$label('seasons', $c['season']) : null,
            ])->filter()->implode($ar ? '، ' : ', ');
        })->filter()->implode($ar ? '؛ ' : '; ');

        $base = $ar
            ? "نسخة محسّنة من «{$evaluation->name}» بنفس شكل المنتج وقصّته."
            : "An improved version of “{$evaluation->name}” keeping the same product shape and cut.";

        return $changes ? $base.($ar ? ' طبّق التعديلات: ' : ' Apply: ').$changes.'.' : $base;
    }
}
