<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    use AdminData;

    public function index(Request $request): Response
    {
        $filters = $request->only(['store', 'user', 'action', 'admin', 'from', 'to']);

        $logs = ActivityLog::query()
            ->with(['user:id,name,email', 'store:id,name'])
            ->when($filters['store'] ?? null, fn (Builder $q, $id) => $q->where('store_id', $id))
            ->when($filters['user'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn (Builder $q, string $a) => $q->where('action', 'like', "{$a}%"))
            ->when(isset($filters['admin']) && $filters['admin'] !== '', fn (Builder $q) => $q->where('acting_as_admin', $filters['admin'] === '1'))
            ->when($filters['from'] ?? null, fn (Builder $q, string $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $q, string $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/activity', [
            'logs' => $this->activityPage($logs),
            'filters' => $filters,
            'stores' => Store::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
