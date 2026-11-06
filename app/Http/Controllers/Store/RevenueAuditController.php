<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\AuditZidRevenue;
use App\Models\Store;
use App\Models\SyncRun;
use App\Services\Zid\RevenueAudit;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Revenue audit page: reads a month of orders from Zid and explains how
 * they differ from the revenue the store sees here.
 */
class RevenueAuditController extends Controller
{
    /** A run with no progress for this long lost its worker. */
    private const STALE_MINUTES = 15;

    public function __construct(private readonly CurrentStore $current) {}

    public function show(Request $request): Response
    {
        $store = $this->current->require();
        $this->expire($store);
        $runs = SyncRun::query()->where('store_id', $store->id)->where('type', RevenueAudit::TYPE)->latest('id')->limit(8)->get();
        $selected = $request->integer('run') ? $runs->firstWhere('id', $request->integer('run')) : $runs->first();

        return Inertia::render('store/settings/revenue-audit', [
            'connected' => $this->connected($store),
            'months' => collect(range(0, 11))->map(fn (int $i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->all(),
            'audit' => $selected ? [
                ...$this->summary($selected),
                'progress' => ['pages' => $selected->stats['pages'] ?? 0, 'orders' => $selected->stats['orders_read'] ?? 0],
                'report' => $selected->stats['report'] ?? null,
            ] : null,
            'history' => $runs->map(fn (SyncRun $run) => $this->summary($run))->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->current->require();
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m', 'after_or_equal:'.now()->startOfMonth()->subMonths(11)->format('Y-m'), 'before_or_equal:'.now()->format('Y-m')],
            'expected' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        if (! $this->connected($store)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('store.revenue_audit_needs_connection')]);

            return back();
        }

        $this->expire($store);
        $run = DB::transaction(function () use ($store, $data): ?SyncRun {
            Store::query()->lockForUpdate()->findOrFail($store->id);
            if (SyncRun::query()->where('store_id', $store->id)->where('type', RevenueAudit::TYPE)->whereIn('status', RevenueAudit::ACTIVE)->exists()) {
                return null;
            }

            return SyncRun::query()->create([
                'store_id' => $store->id, 'type' => RevenueAudit::TYPE, 'status' => 'queued',
                'stats' => [
                    'month' => $data['month'],
                    'expected' => isset($data['expected']) ? (float) $data['expected'] : null,
                    'phase' => 'queued', 'heartbeat_at' => now()->toIso8601String(),
                ],
            ]);
        });

        if (! $run) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('store.revenue_audit_running')]);

            return back();
        }

        AuditZidRevenue::dispatch($run->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('store.revenue_audit_started')]);

        return to_route('store.settings.revenue-audit');
    }

    private function connected(Store $store): bool
    {
        return $store->isConnected() && filled($store->zid_access_token) && filled($store->zid_authorization);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'error' => $run->error,
            'month' => $run->stats['month'] ?? null,
            'expected' => $run->stats['expected'] ?? null,
            'created_at' => $run->created_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    private function expire(Store $store): void
    {
        SyncRun::query()->where('store_id', $store->id)->where('type', RevenueAudit::TYPE)->whereIn('status', RevenueAudit::ACTIVE)->get()
            ->filter(fn (SyncRun $run) => ($run->stats['heartbeat_at'] ?? null) === null
                || now()->subMinutes(self::STALE_MINUTES)->gt($run->stats['heartbeat_at']))
            ->each(fn (SyncRun $run) => $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'stalled']));
    }
}
