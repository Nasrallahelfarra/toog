<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\SeasonalEvent;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\Trend;
use App\Models\User;
use App\Services\Analytics\StorePerformance;
use App\Services\Catalog\BalanceService;
use App\Services\Catalog\DemoCatalogCleanup;
use App\Services\Catalog\StagnantProducts;
use App\Services\Zid\ConnectionCheck;
use App\Services\Zid\ZidClient;
use App\Services\Zid\ZidSyncState;
use App\Support\CurrentStore;
use App\Support\RevenueVisibility;
use App\Support\StoreAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store owner's home (FR-6.1): evaluation KPIs, decision mix, catalog
 * diversity, products at risk of stalling, rising trends and the seasons
 * coming up.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentStore $current, StorePerformance $performance, BalanceService $balance, DemoCatalogCleanup $cleanup, StoreAccess $access): Response
    {
        $store = $current->require();
        // Each card belongs to a page; a staff member gets only the cards
        // of the pages they may open. The rest is never sent.
        $can = fn (string $section): bool => $access->can($section);
        $sync = app(ZidSyncState::class)->latest($store);

        $evaluations = Evaluation::query()
            ->where('store_id', $store->id)
            ->where('created_at', '>=', now()->startOfMonth()->subMonths(5))
            ->get(['id', 'status', 'recommendation', 'final_decision', 'created_at']);

        $thisMonth = $evaluations->filter(fn ($e) => $e->created_at >= now()->startOfMonth())->count();
        $lastMonth = $evaluations->filter(fn ($e) => $e->created_at >= now()->startOfMonth()->subMonth() && $e->created_at < now()->startOfMonth())->count();

        $completed = Evaluation::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(180))
            ->get(['recommendation']);

        $decisions = collect([Evaluation::ACCEPT, Evaluation::MODIFY, Evaluation::REJECT])
            ->mapWithKeys(fn ($d) => [$d => $completed->where('recommendation', $d)->count()]);

        $byMonth = collect(range(5, 0))->map(function ($i) use ($evaluations) {
            $start = now()->startOfMonth()->subMonths($i);
            $rows = $evaluations->filter(fn ($e) => $e->created_at >= $start && $e->created_at < $start->addMonth());

            return [
                'period' => $start->toDateString(),
                'accept' => $rows->where('recommendation', Evaluation::ACCEPT)->count(),
                'modify' => $rows->where('recommendation', Evaluation::MODIFY)->count(),
                'reject' => $rows->where('recommendation', Evaluation::REJECT)->count(),
            ];
        })->values();

        $analysis = $balance->analyze($store);
        $trend = $performance->monthlyTrend($store, 6);
        $revenueNow = $trend[5]['revenue'] ?? 0;
        $revenuePrev = $trend[4]['revenue'] ?? 0;

        return Inertia::render('store/dashboard', [
            'sync' => $can('connection') ? $sync : null,
            'demoCatalog' => fn () => $cleanup->availableInDashboard($store, $request->user()) ? $cleanup->preview($store) : null,
            'kpis' => RevenueVisibility::apply($store, [
                'evaluated_this_month' => $can('evaluations') ? $thisMonth : null,
                'evaluated_delta' => $can('evaluations') && $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100, 1) : null,
                'acceptance_rate' => $can('evaluations') && $completed->count() ? (int) round($decisions[Evaluation::ACCEPT] / $completed->count() * 100) : null,
                'diversity' => $can('balance') ? $analysis['overall']['diversity'] : null,
                'novel_ratio' => $can('balance') ? $analysis['overall']['novel_ratio'] : null,
                'target_novel' => $can('balance') ? $analysis['overall']['target_novel'] : null,
                'balance_alerts' => $can('balance') ? count($analysis['alerts']) : 0,
                'at_risk' => $can('stagnant') ? $performance->atRiskCount($store) : null,
                'revenue' => $can('performance') ? $revenueNow : null,
                'revenue_delta' => $can('performance') && $revenuePrev > 0 ? round(($revenueNow - $revenuePrev) / $revenuePrev * 100, 1) : null,
            ]),
            'revenueTrend' => $store->showsRevenue() && $can('performance') ? array_column($trend, 'revenue') : [],
            'evaluationsTrend' => $can('evaluations') ? $byMonth->map(fn ($m) => $m['accept'] + $m['modify'] + $m['reject'])->all() : [],
            'decisions' => $can('evaluations') ? $decisions : ['accept' => 0, 'modify' => 0, 'reject' => 0],
            'byMonth' => $can('evaluations') ? $byMonth : [],
            'trends' => ! $can('market') ? [] : Trend::query()
                ->where('store_id', $store->id)
                ->where('status', 'rising')
                ->orderByDesc('momentum')
                ->limit(5)
                ->get(['id', 'type', 'value', 'name_ar', 'name_en', 'momentum', 'growth', 'saturation', 'in_catalog', 'platforms']),
            'events' => ! $can('calendar') ? [] : SeasonalEvent::query()
                ->forStore($store)
                ->where('ends_on', '>=', now()->toDateString())
                ->orderBy('starts_on')
                ->limit(4)
                ->get()
                ->map(fn (SeasonalEvent $e) => [
                    'id' => $e->id,
                    'season_key' => $e->season_key,
                    'name_ar' => $e->name_ar,
                    'name_en' => $e->name_en,
                    'starts_on' => $e->starts_on->toDateString(),
                    'ends_on' => $e->ends_on->toDateString(),
                    'launch_by' => $e->starts_on->subWeeks($e->prep_weeks)->toDateString(),
                    'days_until' => (int) now()->startOfDay()->diffInDays($e->starts_on, false),
                    'prep_weeks' => $e->prep_weeks,
                    'palette' => $e->palette ?? [],
                ]),
            'atRisk' => $can('stagnant') ? $performance->atRisk($store, 5) : [],
            'stagnant' => fn () => $can('stagnant') ? app(StagnantProducts::class)->dashboard($store) : null,
            'recentEvaluations' => ! $can('evaluations') ? [] : Evaluation::query()
                ->where('store_id', $store->id)
                ->latest()
                ->limit(6)
                ->get(['id', 'name', 'category', 'score', 'recommendation', 'final_decision', 'status', 'version', 'image_path', 'colors', 'created_at'])
                ->map(fn (Evaluation $e) => [
                    ...$e->only(['id', 'name', 'category', 'score', 'recommendation', 'final_decision', 'status', 'version', 'colors']),
                    'created_at' => $e->created_at?->toIso8601String(),
                    'image_url' => $e->image_path ? Storage::disk('public')->url($e->image_path) : null,
                ]),
            'connection' => ! $can('connection') ? null : [
                'status' => $store->connection_status,
                'platform' => $store->platform,
                'last_synced_at' => $store->last_synced_at?->toIso8601String(),
                'check' => ($check = ConnectionCheck::latest($store)) ? [
                    'ok' => $check['ok'],
                    'fail' => $check['counts']['fail'],
                    'checked_at' => $check['checked_at'],
                ] : null,
            ],
            'thresholds' => [
                'accept' => (int) $store->setting('accept_threshold'),
                'reject' => (int) $store->setting('reject_threshold'),
            ],
            'setup' => $this->setup($store, $request->user()),
        ]);
    }

    /**
     * First-run state, shown instead of an empty dashboard until the store
     * has data: "connect" (not linked to Zid yet), "syncing" (the first
     * import is running) or "empty" (linked, but the import brought nothing
     * in or failed). Null once there are products to analyse.
     *
     * @return array<string, mixed>|null
     */
    private function setup(Store $store, User $user): ?array
    {
        if (Product::query()->where('store_id', $store->id)->exists()) {
            return null;
        }

        $run = SyncRun::query()->where('store_id', $store->id)->where('type', 'zid_sync')->latest('id')->first();
        $syncing = $run && in_array($run->status, ZidSyncState::ACTIVE, true);

        return [
            'state' => match (true) {
                $syncing => 'syncing',
                $store->isConnected() => 'empty',
                default => 'connect',
            },
            'connection_status' => $store->connection_status,
            'zid_configured' => ZidClient::configured(),
            'can_configure' => $user->isAdmin(),
            'run' => $run ? [
                'status' => $run->status,
                'stats' => $run->stats ?? [],
                'error' => $run->error,
                'started_at' => $run->started_at?->toIso8601String(),
            ] : null,
        ];
    }
}
