<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\SeasonalEvent;
use App\Support\Activity;
use App\Support\CurrentStore;
use App\Support\Vocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Saudi seasonal calendar: platform events (read-only here) plus the
 * store's own occasions, with prep windows and suggested launch dates.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(): Response
    {
        $store = $this->current->require();
        $today = now()->startOfDay();

        // Approved candidates per season show how ready the store is.
        $ready = Evaluation::query()
            ->where('store_id', $store->id)
            ->where('final_decision', 'approved')
            ->whereNotNull('season')
            ->selectRaw('season, count(*) as total')
            ->groupBy('season')
            ->pluck('total', 'season');

        $events = SeasonalEvent::query()
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $store->id))
            ->where('ends_on', '>=', $today->subDays(45))
            ->orderBy('starts_on')
            ->get()
            ->map(function (SeasonalEvent $e) use ($today, $ready) {
                $launch = $e->starts_on->subWeeks($e->prep_weeks);

                $phase = match (true) {
                    $e->ends_on->lessThan($today) => 'past',
                    $e->starts_on->lessThanOrEqualTo($today) => 'live',
                    $launch->lessThanOrEqualTo($today) => 'prep',
                    default => 'upcoming',
                };

                return [
                    'id' => $e->id,
                    'season_key' => $e->season_key,
                    'name_ar' => $e->name_ar,
                    'name_en' => $e->name_en,
                    'starts_on' => $e->starts_on->toDateString(),
                    'ends_on' => $e->ends_on->toDateString(),
                    'prep_weeks' => $e->prep_weeks,
                    'launch_on' => $launch->toDateString(),
                    'days_until' => (int) $today->diffInDays($e->starts_on, false),
                    'days_to_launch' => (int) $today->diffInDays($launch, false),
                    'palette' => $e->palette ?? [],
                    'notes' => $e->notes,
                    'is_global' => $e->store_id === null,
                    'is_active' => $e->is_active,
                    'phase' => $phase,
                    'ready' => $e->season_key ? (int) ($ready[$e->season_key] ?? 0) : 0,
                ];
            });

        return Inertia::render('store/calendar', [
            'events' => $events,
            'seasons' => array_keys(Vocabulary::seasons()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->current->require();
        $event = SeasonalEvent::query()->create([...$this->validated($request), 'store_id' => $store->id]);

        Activity::log('calendar.created', $event, ['name' => $event->name_ar]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('market.event_saved')]);

        return back();
    }

    public function update(Request $request, SeasonalEvent $event): RedirectResponse
    {
        $this->authorizeEvent($event);

        $event->update($this->validated($request));

        Activity::log('calendar.updated', $event, ['name' => $event->name_ar]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('market.event_saved')]);

        return back();
    }

    public function destroy(SeasonalEvent $event): RedirectResponse
    {
        $this->authorizeEvent($event);

        Activity::log('calendar.deleted', $event, ['name' => $event->name_ar]);
        $event->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('market.event_deleted')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'season_key' => ['nullable', Rule::in(array_keys(Vocabulary::seasons()))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'prep_weeks' => ['required', 'integer', 'min:0', 'max:26'],
            'palette' => ['nullable', 'array', 'max:6'],
            'palette.*' => [Rule::in(array_keys(Vocabulary::colors()))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return [...$data, 'palette' => $data['palette'] ?? [], 'is_active' => true];
    }

    /**
     * Platform-wide events belong to the admin; stores edit only their own.
     */
    private function authorizeEvent(SeasonalEvent $event): void
    {
        abort_unless($event->store_id !== null && $event->store_id === $this->current->id(), 403);
    }
}
