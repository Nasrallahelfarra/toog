<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDesigns;
use App\Models\Design;
use App\Models\DesignCollection;
use App\Models\DesignRequest;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\ProductRecommendation;
use App\Models\Store;
use App\Models\Trend;
use App\Services\Design\DesignService;
use App\Services\Design\IdeaService;
use App\Services\Design\Refinements;
use App\Services\Designer\DesignerInsights;
use App\Support\Activity;
use App\Support\CurrentStore;
use App\Support\FashionVocabulary;
use App\Support\Vocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The design studio workspace (module 4): requests, variants side by
 * side (FR-4.9), proactive ideas (FR-4.4).
 */
class StudioController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request, DesignService $designs, IdeaService $ideas, DesignerInsights $insights): Response
    {
        $store = $this->current->require();
        $source = null;

        if ($request->integer('recovery')) {
            $recovery = ProductRecommendation::query()->where('store_id', $store->id)->where('status', 'completed')->findOrFail($request->integer('recovery'));
            $selected = $recovery->visualRecommendation($request->string('action')->toString());
            abort_unless($selected && $recovery->source_image_path, 404);
            $snapshot = $recovery->snapshot;
            $features = $snapshot['features'] ?? [];
            $source = [
                'id' => null, 'recovery_id' => $recovery->id, 'action' => $selected['id'],
                'name' => $snapshot['name'], 'category' => $snapshot['category'] ?? null,
                'score' => null, 'expected_score' => null, 'recommendation' => null,
                'colors' => $features['colors'] ?? [], 'pattern' => $features['pattern'] ?? null,
                'material' => $features['material'] ?? null, 'style' => $features['style'] ?? null, 'modifications' => [],
                'image_url' => Storage::disk('public')->url($recovery->source_image_path),
                'prompt' => $selected['instruction'][app()->getLocale()] ?? $selected['instruction']['ar'],
            ];
        } elseif ($request->integer('evaluation')) {
            $evaluation = Evaluation::query()->where('store_id', $store->id)->find($request->integer('evaluation'));

            if ($evaluation) {
                $source = [
                    'id' => $evaluation->id,
                    'name' => $evaluation->name,
                    'category' => $evaluation->category,
                    'score' => $evaluation->score,
                    'expected_score' => $evaluation->expected_score,
                    'recommendation' => $evaluation->recommendation,
                    'colors' => $evaluation->colors ?? [],
                    'pattern' => $evaluation->pattern,
                    'material' => $evaluation->material,
                    'style' => $evaluation->style,
                    'modifications' => $evaluation->modifications ?? [],
                    'image_url' => $evaluation->image_path ? Storage::disk('public')->url($evaluation->image_path) : null,
                    'prompt' => $designs->defaultPrompt($evaluation, app()->getLocale()),
                ];
            }
        }

        $refine = $request->integer('refine')
            ? Design::query()->where('store_id', $store->id)->find($request->integer('refine'))
            : null;

        // Opening the studio to edit something starts a clean workspace: the
        // earlier results stay in the library, and only requests newer than
        // `since` (or still generating) are listed. Submits and polls keep it.
        $since = $request->integer('since') ?: (($source || $refine)
            ? (int) DesignRequest::query()->where('store_id', $store->id)->max('id') : 0);

        // The free idea is the studio's first mode (the store manager's
        // choice); links into a collection open the AI designer, and links
        // naming a mode keep it. Display shots are part of the free-idea tab
        // now, so an old "mockup" link opens that tab.
        $asked = $request->query('mode') === 'mockup' ? 'idea' : $request->query('mode');
        $modes = self::modes();
        $mode = in_array($asked, ['designer', 'modify', 'idea'], true) ? $asked : match (true) {
            $source !== null => 'modify',
            $request->filled('collection') => 'designer',
            default => 'idea',
        };
        // A tab switched off opens the first one in use instead.
        if (! in_array($mode, $modes, true)) {
            $mode = $modes[0];
        }
        // "Design like this" while the AI designer is off: the free idea
        // starts from a description of the piece instead of an empty form.
        $inspiration = ! in_array('designer', $modes, true) && $request->filled('inspire') && ! $request->filled('prompt')
            ? self::inspirationPrompt(DesignerController::inspire($store, $request->string('inspire')->toString()))
            : null;
        $collectionId = $request->integer('collection');

        return Inertia::render('store/studio/index', [
            'mode' => $mode,
            'modes' => $modes,
            'designer' => fn () => $this->designer($store, $designs),
            // The latest collections; one opened by link stays first.
            'collections' => fn () => DesignCollection::query()->where('store_id', $store->id)
                ->when($collectionId, fn ($q) => $q->orderByRaw('id = ? DESC', [$collectionId]))
                ->latest('id')->limit(6)->get()
                ->map(fn (DesignCollection $c) => DesignerController::present($c)),
            'openCollection' => $collectionId ?: null,
            // "Design like this" from the competitor radar.
            'designerPrefill' => fn () => DesignerController::inspire($store, $request->string('inspire')->toString()),
            // Store intelligence for the designer's rail: loaded after the page.
            'insights' => Inertia::defer(fn () => $insights->build($store)),
            // A prompt handed over from another page, e.g. a market trend.
            'prefillPrompt' => $request->string('prompt')->limit(500)->toString() ?: $inspiration,
            'source' => $source,
            'brand' => $store->brand_guide ?? [],
            // Closures: a background check (only collections or requests) skips them.
            'trends' => fn () => Trend::query()->where('store_id', $store->id)->where('status', 'rising')->orderByDesc('momentum')->take(8)
                ->get(['id', 'type', 'value', 'name_ar', 'name_en', 'momentum', 'saturation', 'in_catalog']),
            'categories' => fn () => Product::query()->where('store_id', $store->id)->whereNotNull('category')->distinct()->pluck('category'),
            'since' => $since ?: null,
            // The studio is each person's own bench: their latest request, and
            // whatever of theirs is still generating. Earlier results, and
            // everyone else's, are in the library. While editing, the requests
            // of that edit (newer than `since`) are listed instead.
            'requests' => fn () => DesignRequest::query()
                ->where('store_id', $store->id)
                ->where('user_id', $request->user()->id)
                ->where(fn ($q) => $q
                    ->whereIn('status', ['pending', 'processing'])
                    ->when($since,
                        fn ($q) => $q->orWhere('id', '>', $since),
                        fn ($q) => $q->orWhere('id', DesignRequest::query()->where('store_id', $store->id)->where('user_id', $request->user()->id)->max('id'))))
                ->with(['designs' => fn ($q) => $q->orderBy('id'), 'parentDesign'])
                ->latest()
                ->orderByDesc('id')
                ->take(8)
                ->get()
                ->map(fn (DesignRequest $r) => [
                    ...$r->only(['id', 'type', 'title', 'prompt', 'category', 'output_type', 'aspect_ratio', 'variants_count', 'status', 'error', 'evaluation_id', 'created_at']),
                    'designs' => $r->designs->map(fn (Design $d) => $this->design($d)),
                    // The design this edit started from, for the before/after view.
                    'parent' => $r->parentDesign ? ['id' => $r->parentDesign->id, 'title' => $r->parentDesign->title, 'image_url' => $r->parentDesign->image_url] : null,
                ]),
            'outputTypes' => DesignRequest::OUTPUT_TYPES,
            // A design opened for editing from the library.
            'refine' => $refine ? self::design($refine) : null,
            'ideas' => Inertia::optional(fn () => $ideas->suggest($store)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        $data = $request->validate([
            'type' => ['required', Rule::in(['modification', 'new_idea', 'mockup'])],
            'title' => ['nullable', 'string', 'max:160'],
            'prompt' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:80'],
            'output_type' => ['required', Rule::in(DesignRequest::OUTPUT_TYPES)],
            'aspect_ratio' => ['required', Rule::in(['1:1', '4:5', '9:16', '16:9', '3:4'])],
            'variants_count' => ['required', 'integer', 'min:1', 'max:4'],
            'evaluation_id' => ['nullable', 'integer'],
            'product_recommendation_id' => ['nullable', 'integer', 'prohibits:evaluation_id'],
            'recommendation_key' => ['required_with:product_recommendation_id', 'nullable', 'string', 'max:30'],
            'source_image' => ['nullable', 'image', 'max:8192'],
            'since' => ['nullable', 'integer', 'min:0'],
        ]);

        $evaluation = ! empty($data['evaluation_id'])
            ? Evaluation::query()->where('store_id', $store->id)->whereKey($request->integer('evaluation_id'))->first()
            : null;

        $recovery = null;
        if (! empty($data['product_recommendation_id'])) {
            $recovery = ProductRecommendation::query()->where('store_id', $store->id)->where('status', 'completed')->whereKey($request->integer('product_recommendation_id'))->firstOrFail();
            $selected = $recovery->visualRecommendation($request->string('recommendation_key')->toString());
            abort_unless($selected && $data['type'] === 'modification', 422);
            if (! $request->hasFile('source_image') && (! $recovery->source_image_path || ! Storage::disk('public')->exists($recovery->source_image_path))) {
                throw ValidationException::withMessages(['source_image' => __('stagnant.source_missing')]);
            }
        }

        // A modification keeps the original product's shape: reuse its photo
        // as the reference unless a new one was uploaded (FR-4.2).
        $sourcePath = $request->hasFile('source_image')
            ? $request->file('source_image')->store("design-sources/{$store->id}", 'public')
            : ($recovery->source_image_path ?? $evaluation?->image_path);

        $designRequest = DesignRequest::query()->create([
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'evaluation_id' => $evaluation?->id,
            'product_recommendation_id' => $recovery?->id,
            'type' => $data['type'],
            'title' => ($data['title'] ?? null) ?: $evaluation?->name,
            'prompt' => $data['prompt'],
            'category' => $data['category'] ?? $evaluation?->category,
            'output_type' => $data['output_type'],
            'aspect_ratio' => $data['aspect_ratio'],
            'variants_count' => $data['variants_count'],
            'source_image_path' => $sourcePath,
            'status' => 'pending',
        ]);

        GenerateDesigns::dispatch($designRequest->id);

        Activity::log('design.requested', $designRequest, ['type' => $designRequest->type, 'variants' => $designRequest->variants_count]);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.design_queued')]);

        return $this->toWorkspace($request, $designRequest, $designRequest->type === 'modification');
    }

    /**
     * Edit an existing design: professional presets, a recolour and the
     * merchant's own words, applied to a copy of the design as reference.
     */
    public function refine(Request $request, Design $design, Refinements $refinements): RedirectResponse
    {
        $store = $this->current->require();
        abort_unless($design->store_id === $store->id, 404);

        $data = $request->validate([
            'presets' => ['nullable', 'array', 'max:'.Refinements::MAX_PRESETS],
            'presets.*' => ['string', Rule::in(array_keys(Refinements::PRESETS))],
            'color' => ['nullable', 'string', Rule::in(array_keys(Vocabulary::colors()))],
            'instruction' => ['nullable', 'string', 'max:1000'],
            'output_type' => ['nullable', Rule::in(DesignRequest::OUTPUT_TYPES)],
            'aspect_ratio' => ['nullable', Rule::in(['1:1', '4:5', '9:16', '16:9', '3:4'])],
            'variants_count' => ['nullable', 'integer', 'min:1', 'max:2'],
            'since' => ['nullable', 'integer', 'min:0'],
        ]);

        $lines = $refinements->lines($data['presets'] ?? [], $data['color'] ?? null, $data['instruction'] ?? null);
        if ($lines === []) {
            throw ValidationException::withMessages(['instruction' => __('mojo.refine_needs_edit')]);
        }

        // Work from a copy, so deleting the original later cannot break this edit.
        $disk = Storage::disk('public');
        if (! $design->image_path || ! $disk->exists($design->image_path)) {
            throw ValidationException::withMessages(['instruction' => __('mojo.refine_source_missing')]);
        }
        $source = "design-sources/{$store->id}/".Str::uuid().'.'.(pathinfo($design->image_path, PATHINFO_EXTENSION) ?: 'png');
        $disk->copy($design->image_path, $source);

        $parentRequest = $design->request;
        $designRequest = DesignRequest::query()->create([
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'evaluation_id' => $design->evaluation_id ?? $parentRequest?->evaluation_id,
            'parent_design_id' => $design->id,
            'type' => DesignRequest::REFINEMENT,
            'title' => $design->title,
            'prompt' => implode("\n", $lines),
            'category' => $parentRequest?->category,
            'output_type' => $data['output_type'] ?? $design->output_type,
            'aspect_ratio' => $data['aspect_ratio'] ?? $design->aspect_ratio,
            'variants_count' => $data['variants_count'] ?? 1,
            'source_image_path' => $source,
            'status' => 'pending',
        ]);

        GenerateDesigns::dispatch($designRequest->id);

        Activity::log('design.refined', $designRequest, ['parent' => $design->id, 'presets' => $data['presets'] ?? [], 'color' => $data['color'] ?? null]);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.design_queued')]);

        return $this->toWorkspace($request, $designRequest, true);
    }

    /**
     * Back to the studio after a submit. An edit opens a clean workspace
     * starting at its request, unless one is already open; a new idea from
     * the plain studio keeps the full history.
     */
    private function toWorkspace(Request $request, DesignRequest $designRequest, bool $edit): RedirectResponse
    {
        $since = $request->integer('since') ?: ($edit ? $designRequest->id - 1 : 0);

        return to_route('store.studio.index', array_filter(['mode' => self::modeFor($designRequest), 'since' => $since ?: null]));
    }

    /**
     * The studio tabs in use, in order (config mojo.studio_modes).
     *
     * @return non-empty-list<string>
     */
    public static function modes(): array
    {
        $all = ['idea', 'designer', 'modify'];
        $setting = config('mojo.studio_modes');
        $on = $setting === null || $setting === 'all'
            ? $all
            : array_values(array_intersect($all, array_map('trim', explode(',', (string) $setting))));

        return $on === [] ? ['idea'] : $on;
    }

    /**
     * A free-idea description of a competitor piece or a market attribute:
     * what it is, its colours and pattern, made in the store's own way.
     *
     * @param  array<string, mixed>|null  $prefill  from DesignerController::inspire()
     */
    private static function inspirationPrompt(?array $prefill): ?string
    {
        if (! $prefill) {
            return null;
        }

        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $garment = FashionVocabulary::garments()[$prefill['garment'] ?? '']['ar' === $locale ? 'ar' : 'en'] ?? null;
        $colors = array_values(array_filter(array_map(fn ($c) => Vocabulary::label('colors', $c, $locale), (array) ($prefill['colors'] ?? []))));
        $pattern = Vocabulary::label('patterns', $prefill['pattern'] ?? null, $locale);
        $source = $prefill['inspiration']['label'] ?? null;

        if ($locale === 'ar') {
            return trim(($garment ? "صمّم {$garment}" : 'صمّم قطعة').($source ? " مستوحاة من «{$source}»" : '')
                .($colors ? '، بألوان '.implode(' و', $colors) : '')
                .($pattern ? "، نقشة {$pattern}" : '')
                .'، بقصّة قريبة منها وبهوية متجري.');
        }

        return trim(($garment ? "Design a {$garment}" : 'Design a piece').($source ? " inspired by “{$source}”" : '')
            .($colors ? ', in '.implode(' and ', $colors) : '')
            .($pattern ? ", {$pattern} pattern" : '')
            .', with a close cut and my store’s identity.');
    }

    /**
     * The studio tab a request belongs to, named on every redirect back so
     * the request reopens on its own tab.
     */
    private static function modeFor(DesignRequest $designRequest): string
    {
        return $designRequest->type === 'modification' ? 'modify' : 'idea';
    }

    public function retry(DesignRequest $designRequest): RedirectResponse
    {
        abort_unless($designRequest->store_id === $this->current->id(), 404);

        $designRequest->update(['status' => 'pending', 'error' => null]);
        GenerateDesigns::dispatch($designRequest->id);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.design_queued')]);

        // Back to the page it came from, on the request's own tab.
        $previous = parse_url(url()->previous());
        parse_str($previous['query'] ?? '', $query);

        return ($previous['path'] ?? '') === '/store/studio'
            ? to_route('store.studio.index', [...$query, 'mode' => self::modeFor($designRequest)])
            : back();
    }

    /**
     * The AI designer's brief data: vocabulary, the latest advice, the
     * store's colours, its clothing sections (from Zid and added by hand)
     * and its own fabrics.
     *
     * @return array<string, mixed>
     */
    private function designer(Store $store, DesignService $designs): array
    {
        $custom = DesignerOptionController::forBrief($store->id);

        return [
            'vocabulary' => FashionVocabulary::forFrontend(),
            'briefing' => DesignerController::briefing($store->id),
            'brand_colors' => collect($store->brand_guide['colors'] ?? [])->map(fn ($hex) => $designs->nearestColor((string) $hex))->unique()->values()->all(),
            // The store's own clothing sections (synced from Zid), each with
            // the garment it designs; accessories, bags and the like are left out.
            'categories' => [
                ...Product::query()->where('store_id', $store->id)->whereNotNull('category')->where('category', '!=', '')
                    ->selectRaw('category, COUNT(*) as products')->groupBy('category')->orderByDesc('products')->get()
                    ->map(fn ($row) => ['name' => $row->category, 'products' => (int) $row->products, 'garment' => FashionVocabulary::garmentFor($row->category), 'source' => 'zid', 'id' => null])
                    ->filter(fn (array $c) => $c['garment'] !== null)
                    ->values()->all(),
                // Sections the merchant added by hand.
                ...array_map(fn (array $s) => ['name' => $s['label'], 'products' => 0, 'garment' => $s['garment'], 'source' => 'custom', 'id' => $s['id']], $custom['sections']),
            ],
            // Fabrics the merchant added that are not in the built-in list.
            'custom_fabrics' => $custom['fabrics'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function design(Design $d): array
    {
        return [
            ...$d->only(['id', 'title', 'image_path', 'output_type', 'aspect_ratio', 'palette', 'tags', 'status', 'is_favorite', 'score', 'evaluation_id', 'design_request_id', 'provider', 'model', 'cost', 'prompt', 'created_at']),
            'image_url' => $d->image_url,
        ];
    }
}
