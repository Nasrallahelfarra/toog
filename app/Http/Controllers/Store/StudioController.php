<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDesigns;
use App\Models\Design;
use App\Models\DesignRequest;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\Trend;
use App\Services\Design\DesignService;
use App\Services\Design\IdeaService;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The design studio workspace (module 4): requests, variants side by
 * side (FR-4.9), proactive ideas (FR-4.4).
 */
class StudioController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request, DesignService $designs, IdeaService $ideas): Response
    {
        $store = $this->current->require();
        $source = null;

        if ($request->integer('evaluation')) {
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

        return Inertia::render('store/studio/index', [
            // A prompt handed over from another page, e.g. a market trend.
            'prefillPrompt' => $request->string('prompt')->limit(500)->toString() ?: null,
            'source' => $source,
            'brand' => $store->brand_guide ?? [],
            'trends' => Trend::query()->where('store_id', $store->id)->where('status', 'rising')->orderByDesc('momentum')->take(8)
                ->get(['id', 'type', 'value', 'name_ar', 'name_en', 'momentum', 'saturation', 'in_catalog']),
            'categories' => Product::query()->where('store_id', $store->id)->whereNotNull('category')->distinct()->pluck('category'),
            'requests' => DesignRequest::query()
                ->where('store_id', $store->id)
                ->with(['designs' => fn ($q) => $q->orderBy('id')])
                ->latest()
                ->take(8)
                ->get()
                ->map(fn (DesignRequest $r) => [
                    ...$r->only(['id', 'type', 'title', 'prompt', 'category', 'output_type', 'aspect_ratio', 'variants_count', 'status', 'error', 'evaluation_id', 'created_at']),
                    'designs' => $r->designs->map(fn (Design $d) => $this->design($d)),
                ]),
            'outputTypes' => DesignRequest::OUTPUT_TYPES,
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
            'source_image' => ['nullable', 'image', 'max:8192'],
        ]);

        $evaluation = ! empty($data['evaluation_id'])
            ? Evaluation::query()->where('store_id', $store->id)->find($data['evaluation_id'])
            : null;

        // A modification keeps the original product's shape: reuse its photo
        // as the reference unless a new one was uploaded (FR-4.2).
        $sourcePath = $request->hasFile('source_image')
            ? $request->file('source_image')->store("design-sources/{$store->id}", 'public')
            : $evaluation?->image_path;

        $designRequest = DesignRequest::query()->create([
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'evaluation_id' => $evaluation?->id,
            'type' => $data['type'],
            'title' => $data['title'] ?: $evaluation?->name,
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

        return to_route('store.studio.index');
    }

    public function retry(DesignRequest $designRequest): RedirectResponse
    {
        abort_unless($designRequest->store_id === $this->current->id(), 404);

        $designRequest->update(['status' => 'pending', 'error' => null]);
        GenerateDesigns::dispatch($designRequest->id);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.design_queued')]);

        return back();
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
