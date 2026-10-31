<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Design;
use App\Models\DesignRequest;
use App\Support\Activity;
use App\Support\CurrentStore;
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
 * favourites, search and download.
 */
class DesignController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request): Response
    {
        $store = $this->current->require();
        $filters = $request->only(['status', 'favorite', 'tag', 'output_type', 'search']);

        $designs = Design::query()
            ->where('store_id', $store->id)
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
            'designs' => $designs,
            'filters' => $filters,
            'counts' => [
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

    private function own(Design $design): void
    {
        abort_unless($design->store_id === $this->current->id(), 404);
    }
}
