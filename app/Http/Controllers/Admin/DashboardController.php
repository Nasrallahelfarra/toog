<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiUsage;
use App\Models\Design;
use App\Models\Evaluation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use AdminData;

    public function index(): Response
    {
        $monthStart = now()->startOfMonth();
        $lastMonthStart = $monthStart->subMonth();
        // Compare month-to-date with the same number of days last month.
        $lastMonthSameDay = $lastMonthStart->addDays(now()->day - 1)->endOfDay();

        $costThisMonth = (float) AiUsage::query()->where('created_at', '>=', $monthStart)->sum('cost');
        $costLastMonth = (float) AiUsage::query()->whereBetween('created_at', [$lastMonthStart, $lastMonthSameDay])->sum('cost');

        $daily = $this->dailyAiCost(now()->subDays(29)->startOfDay());

        $stores = Store::query()
            ->with('owner:id,name,email,status')
            ->withSum(['aiUsages as month_cost' => fn ($q) => $q->where('created_at', '>=', $monthStart)], 'cost')
            ->get();

        return Inertia::render('admin/dashboard', [
            'kpis' => [
                'stores' => [
                    'total' => $stores->count(),
                    'active' => $stores->where('status', Store::STATUS_ACTIVE)->count(),
                    'suspended' => $stores->where('status', Store::STATUS_SUSPENDED)->count(),
                    'connected' => $stores->where('connection_status', 'connected')->count(),
                ],
                'users' => [
                    'total' => User::query()->count(),
                    'active' => User::query()->where('status', User::STATUS_ACTIVE)->count(),
                    'disabled' => User::query()->where('status', User::STATUS_DISABLED)->count(),
                    'admins' => User::query()->where('role', User::ROLE_ADMIN)->count(),
                ],
                'ai' => [
                    'month_cost' => round($costThisMonth, 4),
                    'last_month_cost' => round($costLastMonth, 4),
                    'delta' => $costLastMonth > 0 ? round(($costThisMonth - $costLastMonth) / $costLastMonth * 100, 1) : null,
                    'month_calls' => AiUsage::query()->where('created_at', '>=', $monthStart)->count(),
                    'spark' => collect($daily)->slice(-14)->pluck('cost')->values(),
                ],
                'evaluations' => [
                    'month' => Evaluation::query()->where('created_at', '>=', $monthStart)->count(),
                    'total' => Evaluation::query()->count(),
                ],
                'designs' => [
                    'month' => Design::query()->where('created_at', '>=', $monthStart)->count(),
                    'total' => Design::query()->count(),
                ],
            ],
            'dailyCost' => $daily,
            'topStores' => $stores->sortByDesc('month_cost')->take(6)->values()->map(fn (Store $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'cost' => round((float) $s->month_cost, 4),
                'budget' => $s->ai_monthly_budget !== null ? (float) $s->ai_monthly_budget : null,
            ]),
            'attention' => $this->attention($stores),
            'recentActivity' => ActivityLog::query()
                ->with(['user:id,name,email', 'store:id,name'])
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(fn (ActivityLog $log) => $this->activityRow($log)),
        ]);
    }

    /**
     * Stores and owners that need the admin's attention.
     *
     * @param  Collection<int, Store>  $stores
     * @return list<array<string, mixed>>
     */
    private function attention($stores): array
    {
        $items = [];

        foreach ($stores as $store) {
            if ($store->status === Store::STATUS_SUSPENDED) {
                $items[] = ['type' => 'suspended', 'store' => ['id' => $store->id, 'name' => $store->name], 'detail' => $store->suspended_reason];
            }

            if ($store->connection_status === 'error') {
                $items[] = ['type' => 'connection_error', 'store' => ['id' => $store->id, 'name' => $store->name], 'detail' => null];
            }

            $budget = $store->ai_monthly_budget !== null ? (float) $store->ai_monthly_budget : null;
            if ($budget && (float) $store->month_cost >= $budget * 0.8) {
                $items[] = [
                    'type' => 'budget',
                    'store' => ['id' => $store->id, 'name' => $store->name],
                    'detail' => null,
                    'usage' => (int) round((float) $store->month_cost / $budget * 100),
                ];
            }

            if ($store->owner && $store->owner->status === User::STATUS_DISABLED) {
                $items[] = ['type' => 'owner_disabled', 'store' => ['id' => $store->id, 'name' => $store->name], 'detail' => $store->owner->name];
            }
        }

        return $items;
    }
}
