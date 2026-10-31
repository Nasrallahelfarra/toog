<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store's audit trail (FR-6.7).
 */
class ActivityController extends Controller
{
    public function index(Request $request, CurrentStore $current): Response
    {
        $store = $current->require();
        $filters = $request->only(['user', 'action', 'from', 'to']);

        $base = ActivityLog::query()->where('store_id', $store->id);

        $logs = (clone $base)
            ->with('user:id,name,email,role')
            ->when($filters['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user))
            // "evaluation" matches evaluation.created, evaluation.decided, ...
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where(fn ($w) => $w->where('action', $action)->orWhere('action', 'like', "{$action}.%")))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'role' => $log->user->role] : null,
                'properties' => $log->properties,
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_id' => $log->subject_id,
                'acting_as_admin' => $log->acting_as_admin,
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        $userIds = (clone $base)->whereNotNull('user_id')->distinct()->pluck('user_id');

        return Inertia::render('store/activity', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => User::query()->whereIn('id', $userIds)->orderBy('name')->get(['id', 'name', 'role']),
            'actions' => (clone $base)->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
