<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-platform alerts (FR-6.3) for the signed-in user in the current store.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request): Response
    {
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';

        $notifications = $this->query($request)
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
                ...$n->data,
            ]);

        return Inertia::render('store/notifications', [
            'notifications' => $notifications,
            'filter' => $filter,
            'counts' => [
                'all' => $this->query($request)->count(),
                'unread' => $this->query($request)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $item = $this->query($request)->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        $url = $item->data['url'] ?? null;

        return $request->boolean('open') && $url ? redirect($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('market.notifications_read')]);

        return back();
    }

    /**
     * The user's notifications for this store, plus platform-wide ones.
     *
     * @return Builder<DatabaseNotification>
     */
    private function query(Request $request): Builder
    {
        $storeId = $this->current->id();

        return DatabaseNotification::query()
            ->where('notifiable_type', $request->user()->getMorphClass())
            ->where('notifiable_id', $request->user()->id)
            ->where(fn ($q) => $q->where('data->store_id', $storeId)->orWhereNull('data->store_id'));
    }
}
