<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDesignCollection;
use App\Models\DesignCollection;
use App\Models\DesignerBriefing;
use App\Services\Designer\DesignerAdvisor;
use App\Support\Activity;
use App\Support\CurrentStore;
use App\Support\FashionVocabulary;
use App\Support\Vocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The AI fashion designer inside the design studio: a brief becomes a
 * collection of design concepts; the designer's advice can be refreshed
 * on demand.
 */
class DesignerController extends Controller
{
    /** Evidence id prefix for each kind of inspiration. */
    private const INSPIRATION = ['bestseller' => 'P', 'rising' => 'R', 'competitor' => 'C', 'social' => 'S', 'gap' => 'G', 'season' => 'E'];

    public function __construct(private readonly CurrentStore $current) {}

    public function store(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        $data = $request->validate([
            'garment' => ['required', Rule::in(array_keys(FashionVocabulary::garments()))],
            'fabric' => ['nullable', 'required_without_all:fabric_image,fabric_from', Rule::in(array_keys(FashionVocabulary::fabrics()))],
            'fabric_image' => ['nullable', 'image', 'max:8192'],
            'fabric_from' => ['nullable', 'integer'],
            'colors' => ['nullable', 'array', 'max:4'],
            'colors.*' => ['string', Rule::in(array_keys(Vocabulary::colors()))],
            'occasion' => ['required', Rule::in(array_keys(FashionVocabulary::occasions()))],
            'age' => ['nullable', Rule::in(['young', 'adult', 'mature'])],
            'modesty' => ['nullable', Rule::in(['strict', 'standard'])],
            'tier' => ['nullable', Rule::in(['economy', 'mid', 'premium', 'luxury'])],
            'creativity' => ['required', 'integer', 'min:1', 'max:5'],
            'count' => ['required', 'integer', 'min:1', 'max:4'],
            'render' => ['required', Rule::in(array_keys(FashionVocabulary::renders()))],
            'notes' => ['nullable', 'string', 'max:600'],
            'inspiration' => ['nullable', 'array'],
            'inspiration.kind' => ['required_with:inspiration', Rule::in(array_keys(self::INSPIRATION))],
            'inspiration.id' => ['required_with:inspiration', 'string', 'max:80'],
            'inspiration.label' => ['nullable', 'string', 'max:160'],
            'inspiration.image_url' => ['nullable', 'string', 'max:2048'],
        ], [
            'fabric.required_without_all' => __('mojo.designer_fabric_required'),
        ]);

        $inspiration = isset($data['inspiration']) ? [
            ...$data['inspiration'],
            'evidence_id' => self::INSPIRATION[$data['inspiration']['kind']].':'.$data['inspiration']['id'],
        ] : null;

        // "Another collection with the same fabric" reuses an earlier swatch.
        $fabricPath = $request->hasFile('fabric_image')
            ? $request->file('fabric_image')->store("design-sources/{$store->id}/fabrics", 'public')
            : DesignCollection::query()->where('store_id', $store->id)->whereKey($data['fabric_from'] ?? 0)->value('fabric_image_path');

        $collection = DesignCollection::query()->create([
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'status' => 'pending',
            'fabric_image_path' => $fabricPath,
            'brief' => [
                'garment' => $data['garment'],
                'fabric' => $data['fabric'] ?? null,
                'colors' => array_values(array_unique($data['colors'] ?? [])),
                'occasion' => $data['occasion'],
                'age' => $data['age'] ?? 'adult',
                'modesty' => $data['modesty'] ?? (in_array($data['garment'], ['abaya', 'prayer_set', 'jalabiya'], true) ? 'strict' : 'standard'),
                'tier' => $data['tier'] ?? null,
                'creativity' => (int) $data['creativity'],
                'count' => (int) $data['count'],
                'render' => $data['render'],
                'notes' => $data['notes'] ?? null,
                'inspiration' => $inspiration,
            ],
        ]);

        GenerateDesignCollection::dispatch($collection->id);

        Activity::log('designer.collection_requested', $collection, ['garment' => $data['garment'], 'count' => (int) $data['count']]);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.designer_queued')]);

        return to_route('store.studio.index', ['mode' => 'designer', 'collection' => $collection->id]);
    }

    public function retry(DesignCollection $collection): RedirectResponse
    {
        $this->own($collection);

        $collection->update(['status' => 'pending', 'error' => null]);
        GenerateDesignCollection::dispatch($collection->id);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.designer_queued')]);

        return back();
    }

    /**
     * Draw one concept again (a failed image, or a new take on it).
     */
    public function redraw(DesignCollection $collection, int $index): RedirectResponse
    {
        $this->own($collection);
        $concepts = $collection->concepts ?? [];
        abort_unless(isset($concepts[$index]) && ! $collection->isActive(), 422);

        $concepts[$index] = [...$concepts[$index], 'status' => 'pending', 'error' => null];
        $collection->update(['concepts' => $concepts, 'status' => 'pending', 'error' => null]);
        GenerateDesignCollection::dispatch($collection->id, $index);

        Activity::log('designer.concept_redrawn', $collection, ['index' => $index]);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('mojo.designer_redraw')]);

        return back();
    }

    public function destroy(DesignCollection $collection): RedirectResponse
    {
        $this->own($collection);
        $collection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.designer_deleted')]);

        return to_route('store.studio.index', ['mode' => 'designer']);
    }

    /**
     * Ask the designer for fresh advice on the store's latest data.
     */
    public function advise(Request $request, DesignerAdvisor $advisor): RedirectResponse
    {
        $store = $this->current->require();
        $advisor->advise($store, $request->user()->id);

        Activity::log('designer.advice_requested', $store);

        return back();
    }

    private function own(DesignCollection $collection): void
    {
        abort_unless($collection->store_id === $this->current->id(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(DesignCollection $c): array
    {
        $disk = Storage::disk('public');

        return [
            'id' => $c->id,
            'status' => $c->status,
            'error' => $c->error,
            'brief' => $c->brief,
            'fabric_image_url' => $c->fabric_image_path ? $disk->url($c->fabric_image_path) : null,
            'title_ar' => $c->title_ar,
            'title_en' => $c->title_en,
            'story_ar' => $c->story_ar,
            'story_en' => $c->story_en,
            'from_model' => $c->from_model,
            'created_at' => $c->created_at?->toIso8601String(),
            'concepts' => array_map(fn (array $concept) => [
                ...$concept,
                'image_url' => ! empty($concept['image_path']) ? $disk->url($concept['image_path']) : null,
            ], $c->concepts ?? []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function briefing(int $storeId): ?array
    {
        $b = DesignerBriefing::query()->where('store_id', $storeId)->latest('id')->first();

        return $b ? ['items' => $b->advice['items'] ?? [], 'from_model' => $b->from_model, 'created_at' => $b->created_at?->toIso8601String()] : null;
    }
}
