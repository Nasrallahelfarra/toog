<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Design;
use App\Models\DesignCollection;
use App\Models\DesignRequest;
use App\Support\Activity;
use App\Support\CurrentStore;
use App\Support\FashionVocabulary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The design library (FR-4.8): every generated design with tags, status,
 * favourites, search and download; the AI designer's finished collections
 * can be browsed as collections, each opening its own designs.
 */
class DesignController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request): Response
    {
        $store = $this->current->require();
        $filters = $request->only(['status', 'favorite', 'tag', 'output_type', 'search', 'collection']);
        $view = $request->query('view') === 'collections' ? 'collections' : 'designs';
        $finished = DesignCollection::query()->where('store_id', $store->id)->where('status', 'completed');
        $collection = isset($filters['collection'])
            ? (clone $finished)->whereKey((int) $filters['collection'])->first()
            : null;

        $designs = $view === 'collections' ? null : Design::query()
            ->where('store_id', $store->id)
            ->when($collection, fn (Builder $q, DesignCollection $c) => $q->where('design_collection_id', $c->id))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when(($filters['favorite'] ?? null) === '1', fn (Builder $q) => $q->where('is_favorite', true))
            ->when($filters['tag'] ?? null, fn (Builder $q, $v) => $q->getConnection()->getDriverName() === 'sqlite'
                ? $q->where('tags', 'like', '%'.json_encode($v).'%')
                : $q->whereJsonContains('tags', $v))
            ->when($filters['output_type'] ?? null, fn (Builder $q, $v) => $q->where('output_type', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $q) => $q->where('title', 'like', "%{$v}%")->orWhere('prompt', 'like', "%{$v}%")))
            ->latest()
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Design $d) => StudioController::design($d));

        $all = Design::query()->where('store_id', $store->id);

        return Inertia::render('store/library/index', [
            'view' => $view,
            'designs' => $designs,
            'collections' => $view === 'collections' ? (clone $finished)
                ->with('designs:id,design_collection_id,status,is_favorite,score')
                ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $q) => $q->where('title_ar', 'like', "%{$v}%")->orWhere('title_en', 'like', "%{$v}%")))
                ->latest('id')
                ->paginate(12)
                ->withQueryString()
                ->through(fn (DesignCollection $c) => $this->collection($c)) : null,
            'collection' => $collection ? ['id' => $collection->id, 'title' => $this->title($collection)] : null,
            'filters' => $filters,
            'counts' => [
                'collections' => (clone $finished)->count(),
                'all' => (clone $all)->count(),
                'favorite' => (clone $all)->where('is_favorite', true)->count(),
                ...collect(Design::STATUSES)->mapWithKeys(fn ($s) => [$s => (clone $all)->where('status', $s)->count()])->all(),
            ],
            'tags' => (clone $all)->pluck('tags')->flatten()->filter()->unique()->values(),
            'outputTypes' => DesignRequest::OUTPUT_TYPES,
        ]);
    }

    public function update(Request $request, Design $design): RedirectResponse
    {
        $this->own($design);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Design::STATUSES)],
            'is_favorite' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:12'],
            'tags.*' => ['string', 'max:40'],
        ]);

        $from = $design->status;
        $design->update($data);

        if (isset($data['status']) && $data['status'] !== $from) {
            Activity::log('design.status_changed', $design, ['from' => $from, 'to' => $data['status']]);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);
        }

        return back();
    }

    public function download(Design $design): StreamedResponse
    {
        $this->own($design);

        $extension = pathinfo($design->image_path, PATHINFO_EXTENSION) ?: 'png';

        return Storage::disk('public')->download($design->image_path, (Str::slug($design->title) ?: 'design-'.$design->id).'.'.$extension);
    }

    public function destroy(Design $design): RedirectResponse
    {
        $this->own($design);

        Activity::log('design.deleted', $design, ['title' => $design->title]);
        Storage::disk('public')->delete($design->image_path);
        $design->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.deleted')]);

        return back();
    }

    /**
     * A finished collection as one library card: its pictures, what it is
     * and how its designs are doing.
     *
     * @return array<string, mixed>
     */
    private function collection(DesignCollection $c): array
    {
        $locale = app()->getLocale();
        $brief = $c->brief ?? [];
        $disk = Storage::disk('public');
        $ready = collect($c->concepts ?? [])->filter(fn (array $concept) => ($concept['status'] ?? null) === 'ready' && ! empty($concept['image_path']));
        $story = $locale === 'ar' ? ($c->story_ar ?: $c->story_en) : ($c->story_en ?: $c->story_ar);

        return [
            'id' => $c->id,
            'title' => $this->title($c),
            'story' => $story ? Str::limit($story, 220) : null,
            'garment' => FashionVocabulary::label('garments', $brief['garment'] ?? null, $locale),
            'garment_key' => $brief['garment'] ?? null,
            'occasion' => FashionVocabulary::label('occasions', $brief['occasion'] ?? null, $locale),
            'fabric' => FashionVocabulary::label('fabrics', $brief['fabric'] ?? null, $locale),
            'fabric_image_url' => $c->fabric_image_path ? $disk->url($c->fabric_image_path) : null,
            'images' => $ready->take(4)->map(fn (array $concept) => [
                'url' => $disk->url($concept['image_path']),
                'name' => $locale === 'ar' ? ($concept['name_ar'] ?? $concept['name_en'] ?? '') : ($concept['name_en'] ?? $concept['name_ar'] ?? ''),
            ])->values()->all(),
            'ready' => $ready->count(),
            'total' => count($c->concepts ?? []),
            'approved' => $c->designs->where('status', 'approved')->count(),
            'favorites' => $c->designs->where('is_favorite', true)->count(),
            'score' => $c->designs->whereNotNull('score')->isNotEmpty() ? (int) round((float) $c->designs->whereNotNull('score')->avg('score')) : null,
            'created_at' => $c->created_at?->toIso8601String(),
            'open_href' => "/store/studio?mode=designer&collection={$c->id}",
            'designs_href' => "/store/library?collection={$c->id}",
        ];
    }

    private function title(DesignCollection $c): string
    {
        return (app()->getLocale() === 'ar' ? ($c->title_ar ?: $c->title_en) : ($c->title_en ?: $c->title_ar)) ?: __('mojo.collection_untitled', ['id' => $c->id]);
    }

    private function own(Design $design): void
    {
        abort_unless($design->store_id === $this->current->id(), 404);
    }
}
