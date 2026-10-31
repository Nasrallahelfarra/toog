<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SeasonalEvent;
use App\Support\Activity;
use App\Support\Vocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Platform-wide seasonal calendar (store_id = null), shown to every store.
 */
class SeasonalEventController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $event = SeasonalEvent::query()->create([...$this->validated($request), 'store_id' => null]);

        Activity::log('admin.event_created', $event, ['name' => $event->name_en]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return back();
    }

    public function update(Request $request, SeasonalEvent $event): RedirectResponse
    {
        abort_unless($event->store_id === null, 404);

        $event->update($this->validated($request));

        Activity::log('admin.event_updated', $event, ['name' => $event->name_en]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return back();
    }

    public function destroy(SeasonalEvent $event): RedirectResponse
    {
        abort_unless($event->store_id === null, 404);

        Activity::log('admin.event_deleted', null, ['name' => $event->name_en, 'id' => $event->id]);
        $event->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.deleted')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'season_key' => ['nullable', Rule::in(array_keys(Vocabulary::seasons()))],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'prep_weeks' => ['required', 'integer', 'min:1', 'max:26'],
            'palette' => ['nullable', 'array'],
            'palette.*' => ['string', Rule::in(array_keys(Vocabulary::colors()))],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        return [...$data, 'palette' => $data['palette'] ?? [], 'is_active' => $request->boolean('is_active', true)];
    }
}
