<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreEvaluationRequest;
use App\Jobs\RunEvaluation;
use App\Models\Design;
use App\Models\DesignImprovement;
use App\Models\Evaluation;
use App\Models\EvaluationBatch;
use App\Models\Product;
use App\Models\Store;
use App\Services\Ai\Ai;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The quality gate (module 3): candidates in, score + decision +
 * explanation out, then the human's final call (FR-3.1 .. FR-3.10).
 */
class EvaluationController extends Controller
{
    /** Decision the platform expects for each recommendation (FR-3.9). */
    private const EXPECTED = ['accept' => 'approved', 'reject' => 'rejected', 'modify' => 'needs_changes'];

    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request): Response
    {
        $store = $this->current->require();
        $filters = $request->only(['recommendation', 'decision', 'category', 'search', 'from', 'to', 'source']);

        $evaluations = Evaluation::query()
            ->where('store_id', $store->id)
            ->with('user:id,name')
            ->when($filters['recommendation'] ?? null, fn (Builder $q, $v) => $q->where('recommendation', $v))
            ->when($filters['decision'] ?? null, fn (Builder $q, $v) => $v === 'none' ? $q->whereNull('final_decision') : $q->where('final_decision', $v))
            ->when($filters['category'] ?? null, fn (Builder $q, $v) => $q->where('category', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when(
                ($filters['source'] ?? null) === 'design',
                fn (Builder $q) => $q->where('source', 'design'),
                fn (Builder $q) => ($filters['source'] ?? null) === 'all' ? $q : $q->where('source', '!=', 'design'),
            )
            ->latest()
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Evaluation $e) => $this->summary($e));

        $base = Evaluation::query()->where('store_id', $store->id)->where('source', '!=', 'design');

        return Inertia::render('store/evaluations/index', [
            'evaluations' => $evaluations,
            'filters' => $filters,
            'stats' => [
                'total' => (clone $base)->count(),
                'accept' => (clone $base)->where('recommendation', 'accept')->count(),
                'modify' => (clone $base)->where('recommendation', 'modify')->count(),
                'reject' => (clone $base)->where('recommendation', 'reject')->count(),
                'pending' => (clone $base)->whereIn('status', ['pending', 'processing'])->count(),
                'average' => (int) round((clone $base)->where('status', 'completed')->avg('score') ?? 0),
                'overrides' => (clone $base)->whereNotNull('override_reason')->count(),
            ],
            'categories' => $this->categories($store),
            'batches' => EvaluationBatch::query()->where('store_id', $store->id)->withCount('evaluations')->latest()->take(4)->get()
                ->map(fn (EvaluationBatch $b) => ['id' => $b->id, 'name' => $b->name, 'count' => $b->evaluations_count, 'created_at' => $b->created_at]),
            'thresholds' => $this->thresholds($store),
        ]);
    }

    public function create(Request $request): Response
    {
        $store = $this->current->require();
        $parent = $request->integer('parent') ? $this->find($request->integer('parent')) : null;

        $prefill = $parent ? [
            'name' => $parent->name,
            'category' => $parent->category,
            'price' => $parent->price,
            'material' => $parent->material,
            'colors' => $parent->colors ?? [],
            'pattern' => $parent->pattern,
            'style' => $parent->style,
            'season' => $parent->season,
            'description' => $parent->description,
            'target_audience' => $parent->target_audience,
        ] : array_filter([
            // Prefill from an idea or a link, e.g. ?name=..&colors=sage,ivory
            'name' => $request->string('name')->toString() ?: null,
            'category' => $request->string('category')->toString() ?: null,
            'price' => $request->input('price'),
            'material' => $request->string('material')->toString() ?: null,
            // Accepts ?colors=a,b as well as ?colors[]=a&colors[]=b.
            'colors' => $request->filled('colors')
                ? array_values(array_filter(is_array($request->input('colors')) ? $request->input('colors') : explode(',', (string) $request->input('colors'))))
                : null,
            'pattern' => $request->string('pattern')->toString() ?: null,
            'style' => $request->string('style')->toString() ?: null,
            'season' => $request->string('season')->toString() ?: null,
            'description' => $request->string('description')->toString() ?: null,
        ], fn ($v) => $v !== null);

        return Inertia::render('store/evaluations/create', [
            'parent' => $parent ? [
                'id' => $parent->id,
                'name' => $parent->name,
                'version' => $parent->version,
                'score' => $parent->score,
                'recommendation' => $parent->recommendation,
                'image_url' => $this->imageUrl($parent->image_path),
                'modifications' => $parent->modifications ?? [],
                'expected_score' => $parent->expected_score,
            ] : null,
            'prefill' => $prefill,
            'categories' => $this->categories($store),
        ]);
    }

    public function store(StoreEvaluationRequest $request): RedirectResponse
    {
        $store = $this->current->require();
        $data = $request->validated();
        $parent = ! empty($data['parent_id']) ? $this->find((int) $data['parent_id']) : null;

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store("evaluations/{$store->id}", 'public')
            : $parent?->image_path;

        $rootId = $parent ? ($parent->root_id ?? $parent->id) : null;

        $evaluation = Evaluation::query()->create([
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parent?->id,
            'root_id' => $rootId,
            'version' => $parent ? (int) Evaluation::query()->where('root_id', $rootId)->max('version') + 1 : 1,
            'source' => 'manual',
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'price' => $data['price'] ?? null,
            'material' => $data['material'] ?? null,
            'colors' => array_values($data['colors'] ?? []),
            'pattern' => $data['pattern'] ?? null,
            'style' => $data['style'] ?? null,
            'season' => $data['season'] ?? null,
            'description' => $data['description'] ?? null,
            'target_audience' => $data['target_audience'] ?? null,
            'image_path' => $imagePath,
            'status' => 'pending',
        ]);

        if (! $rootId) {
            $evaluation->update(['root_id' => $evaluation->id]);
        }

        RunEvaluation::dispatch($evaluation->id);

        Activity::log('evaluation.created', $evaluation, ['name' => $evaluation->name, 'version' => $evaluation->version]);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.evaluation_queued')]);

        return to_route('store.evaluations.show', $evaluation);
    }

    public function show(Evaluation $evaluation): Response
    {
        $store = $this->current->require();
        $this->own($evaluation);
        $evaluation->load(['user:id,name', 'decider:id,name', 'batch:id,name']);
        // Expire abandoned jobs without showing an endless spinner after a worker outage.
        DesignImprovement::query()->where('evaluation_id', $evaluation->id)
            ->whereIn('status', DesignImprovement::ACTIVE)->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'error' => 'timed_out']);
        $improvements = DesignImprovement::query()->where('evaluation_id', $evaluation->id)
            ->with(['design', 'result'])->latest('id')->take(10)->get()->map(fn ($r) => [
                ...$r->only(['id', 'status', 'recommendations', 'verification', 'error', 'approved_at', 'created_at', 'parent_improvement_id', 'custom_instruction']),
                'before_url' => $this->imageUrl($r->source_image_path),
                'after_url' => $r->design?->image_url,
                'design_id' => $r->design_id,
                'result_id' => $r->result_evaluation_id,
                'score' => $r->result?->score,
                'review' => $r->result?->specialist_review,
            ]);

        $designs = Design::query()
            ->where('store_id', $store->id)
            ->where(fn (Builder $q) => $q
                ->whereIn('design_request_id', fn ($sub) => $sub->select('id')->from('design_requests')->where('evaluation_id', $evaluation->id))
                ->orWhere('id', $evaluation->design_id))
            ->latest()
            ->take(6)
            ->get(['id', 'title', 'image_path', 'score', 'status', 'aspect_ratio']);

        return Inertia::render('store/evaluations/show', [
            'evaluation' => $this->detail($evaluation),
            'versions' => $this->versions($evaluation),
            'designs' => $designs,
            'thresholds' => $this->thresholds($store),
            'improvements' => $improvements,
            'canImprove' => app(Ai::class)->isLive(),
        ]);
    }

    public function compare(Evaluation $evaluation): Response
    {
        $store = $this->current->require();
        $this->own($evaluation);

        $versions = Evaluation::query()
            ->where('store_id', $store->id)
            ->where('root_id', $evaluation->root_id ?? $evaluation->id)
            ->orderBy('version')
            ->get()
            ->map(fn (Evaluation $e) => $this->detail($e));

        return Inertia::render('store/evaluations/compare', [
            'current' => $evaluation->id,
            'versions' => $versions,
            'thresholds' => $this->thresholds($store),
        ]);
    }

    /**
     * FR-3.9: the platform recommends, an authorised user decides.
     */
    public function decide(Request $request, Evaluation $evaluation): RedirectResponse
    {
        $this->own($evaluation);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'needs_changes'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $override = $evaluation->recommendation && self::EXPECTED[$evaluation->recommendation] !== $data['decision'];

        if ($override && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => app()->getLocale() === 'ar'
                    ? 'اذكر سبب تجاوز توصية المنصة.'
                    : 'Please give a reason for overriding the recommendation.',
            ]);
        }

        $evaluation->update([
            'final_decision' => $data['decision'],
            'override_reason' => $override ? $data['reason'] : null,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        Activity::log('evaluation.decided', $evaluation, ['decision' => $data['decision'], 'recommendation' => $evaluation->recommendation]);

        if ($override) {
            Activity::log('evaluation.override', $evaluation, [
                'recommendation' => $evaluation->recommendation,
                'decision' => $data['decision'],
                'reason' => $data['reason'],
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return back();
    }

    public function retry(Evaluation $evaluation): RedirectResponse
    {
        $this->own($evaluation);

        if (in_array($evaluation->status, ['pending', 'processing'], true)) {
            return back();
        }

        if (Evaluation::query()->whereKey($evaluation->id)->whereIn('status', ['completed', 'failed'])->update(['status' => 'pending', 'error' => null, 'updated_at' => now()])) {
            RunEvaluation::dispatch($evaluation->id);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.evaluation_queued')]);

        return back();
    }

    public function destroy(Evaluation $evaluation): RedirectResponse
    {
        $this->own($evaluation);

        Activity::log('evaluation.deleted', $evaluation, ['name' => $evaluation->name]);
        $evaluation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.deleted')]);

        return to_route('store.evaluations.index');
    }

    private function find(int $id): Evaluation
    {
        $evaluation = Evaluation::query()->findOrFail($id);
        $this->own($evaluation);

        return $evaluation;
    }

    private function own(Evaluation $evaluation): void
    {
        abort_unless($evaluation->store_id === $this->current->id(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Evaluation $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'category' => $e->category,
            'price' => $e->price,
            'colors' => $e->colors ?? [],
            'image_url' => $this->imageUrl($e->image_path),
            'status' => $e->status,
            'score' => $e->score,
            'recommendation' => $e->recommendation,
            'final_decision' => $e->final_decision,
            'override' => $e->override_reason !== null,
            'version' => $e->version,
            'source' => $e->source,
            'expected_score' => $e->expected_score,
            'user' => $e->user?->name,
            'created_at' => $e->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Evaluation $e): array
    {
        return [
            ...$e->only([
                'id', 'name', 'category', 'price', 'material', 'colors', 'pattern', 'style', 'season', 'description',
                'status', 'score', 'breakdown', 'recommendation', 'explanation_ar', 'explanation_en', 'top_factors',
                'modifications', 'expected_score', 'cultural_flags', 'alternatives', 'suggested_launch', 'is_novel',
                'final_decision', 'override_reason', 'decided_at', 'version', 'parent_id', 'root_id', 'source',
                'batch_id', 'design_id', 'error', 'created_at', 'updated_at', 'target_audience', 'specialist_review',
            ]),
            'colors' => $e->colors ?? [],
            'image_url' => $this->imageUrl($e->image_path),
            'user' => $e->relationLoaded('user') ? $e->user?->name : null,
            'decider' => $e->relationLoaded('decider') ? $e->decider?->name : null,
            'batch' => $e->relationLoaded('batch') && $e->batch ? ['id' => $e->batch->id, 'name' => $e->batch->name] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versions(Evaluation $e): array
    {
        return Evaluation::query()
            ->where('store_id', $e->store_id)
            ->where('root_id', $e->root_id ?? $e->id)
            ->orderBy('version')
            ->get(['id', 'version', 'score', 'recommendation', 'status', 'final_decision', 'created_at'])
            ->toArray();
    }

    /**
     * @return list<string>
     */
    private function categories(Store $store): array
    {
        return Product::query()->where('store_id', $store->id)->whereNotNull('category')->distinct()->pluck('category')
            ->merge(Evaluation::query()->where('store_id', $store->id)->whereNotNull('category')->distinct()->pluck('category'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{accept: int, reject: int}
     */
    private function thresholds(Store $store): array
    {
        return [
            'accept' => (int) $store->setting('accept_threshold'),
            'reject' => (int) $store->setting('reject_threshold'),
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
