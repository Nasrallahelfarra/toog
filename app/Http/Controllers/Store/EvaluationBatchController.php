<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\RunEvaluation;
use App\Models\Evaluation;
use App\Models\EvaluationBatch;
use App\Models\Product;
use App\Support\Activity;
use App\Support\CurrentStore;
use App\Support\Vocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Evaluate a whole collection at once and rank it by score (FR-3.8).
 */
class EvaluationBatchController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function create(): Response
    {
        $store = $this->current->require();

        return Inertia::render('store/batches/create', [
            'categories' => Product::query()->where('store_id', $store->id)->whereNotNull('category')->distinct()->pluck('category'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rows' => ['required', 'array', 'min:1', 'max:30'],
            'rows.*.name' => ['required', 'string', 'max:160'],
            'rows.*.category' => ['nullable', 'string', 'max:80'],
            'rows.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'rows.*.material' => ['nullable', Rule::in(array_keys(Vocabulary::materials()))],
            'rows.*.pattern' => ['nullable', Rule::in(array_keys(Vocabulary::patterns()))],
            'rows.*.style' => ['nullable', Rule::in(array_keys(Vocabulary::styles()))],
            'rows.*.season' => ['nullable', Rule::in(array_keys(Vocabulary::seasons()))],
            'rows.*.colors' => ['nullable', 'array', 'max:4'],
            'rows.*.colors.*' => [Rule::in(array_keys(Vocabulary::colors()))],
            'rows.*.image' => ['nullable', 'image', 'max:8192'],
        ]);

        $batch = DB::transaction(function () use ($data, $store, $request) {
            $batch = EvaluationBatch::query()->create([
                'store_id' => $store->id,
                'user_id' => $request->user()->id,
                'name' => $data['name'],
            ]);

            foreach ($data['rows'] as $i => $row) {
                $image = $request->file("rows.{$i}.image");

                $evaluation = Evaluation::query()->create([
                    'store_id' => $store->id,
                    'user_id' => $request->user()->id,
                    'batch_id' => $batch->id,
                    'source' => 'batch',
                    'name' => $row['name'],
                    'category' => $row['category'] ?? null,
                    'price' => $row['price'] ?? null,
                    'material' => $row['material'] ?? null,
                    'colors' => array_values($row['colors'] ?? []),
                    'pattern' => $row['pattern'] ?? null,
                    'style' => $row['style'] ?? null,
                    'season' => $row['season'] ?? null,
                    'image_path' => $image?->store("evaluations/{$store->id}", 'public'),
                    'status' => 'pending',
                ]);
                $evaluation->update(['root_id' => $evaluation->id]);
            }

            return $batch;
        });

        $batch->evaluations()->pluck('id')->each(fn (int $id) => RunEvaluation::dispatch($id));

        Activity::log('batch.created', $batch, ['name' => $batch->name, 'count' => count($data['rows'])]);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.evaluation_queued')]);

        return to_route('store.batches.show', $batch);
    }

    public function show(EvaluationBatch $batch): Response
    {
        $store = $this->current->require();
        abort_unless($batch->store_id === $store->id, 404);

        $evaluations = $batch->evaluations()->get()
            ->sortByDesc(fn (Evaluation $e) => $e->status === 'completed' ? 1000 + (int) $e->score : 0)
            ->values()
            ->map(fn (Evaluation $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'category' => $e->category,
                'price' => $e->price,
                'colors' => $e->colors ?? [],
                'pattern' => $e->pattern,
                'image_url' => $e->image_path ? Storage::disk('public')->url($e->image_path) : null,
                'status' => $e->status,
                'score' => $e->score,
                'recommendation' => $e->recommendation,
                'expected_score' => $e->expected_score,
                'final_decision' => $e->final_decision,
                'top_factor' => collect($e->top_factors ?? [])->first(),
            ]);

        return Inertia::render('store/batches/show', [
            'batch' => ['id' => $batch->id, 'name' => $batch->name, 'created_at' => $batch->created_at],
            'evaluations' => $evaluations,
            'thresholds' => [
                'accept' => (int) $store->setting('accept_threshold'),
                'reject' => (int) $store->setting('reject_threshold'),
            ],
        ]);
    }
}
