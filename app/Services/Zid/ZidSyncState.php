<?php

namespace App\Services\Zid;

use App\Jobs\CheckZidConnection;
use App\Jobs\SyncStoreData;
use App\Models\Store;
use App\Models\SyncRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ZidSyncState
{
    public const ACTIVE = ['queued', 'running'];

    public function enqueue(Store $store, ?int $userId = null, bool $checkAfter = false): SyncRun
    {
        [$run, $created] = $this->reserve($store);
        if (! $created) {
            return $run;
        }

        return $this->dispatch($store, $run, $userId, $checkAfter);
    }

    private function dispatch(Store $store, SyncRun $run, ?int $userId, bool $checkAfter): SyncRun
    {
        try {
            $driver = config('queue.connections.'.config('queue.default').'.driver');
            if (in_array($driver, ['sync', 'null', 'deferred'], true)) {
                throw new RuntimeException('Zid imports require a background queue worker.');
            }
            $job = new SyncStoreData($store, $userId, $run->id, (int) ($run->stats['step'] ?? 0));
            if ($checkAfter) {
                Bus::chain([$job, new CheckZidConnection($store->id)])->dispatch();
            } else {
                Bus::dispatch($job);
            }
        } catch (Throwable $e) {
            $this->fail($run->id, 'queue_failed');
            report($e);
        }

        return $run->refresh();
    }

    /** Resume only a complete checkpoint belonging to the current connection. */
    public function resume(Store $store, int $runId, ?int $userId = null): SyncRun
    {
        $lock = Cache::lock(SyncStoreData::lockKey($store), 660);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['sync' => __('store.sync_resume_busy')]);
        }
        try {
            [$run, $dispatch] = DB::transaction(function () use ($store, $runId): array {
                $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
                $run = $locked->syncRuns()->where('type', 'zid_sync')->latest('id')->lockForUpdate()->first();
                if (! $run || $run->id !== $runId) {
                    throw ValidationException::withMessages(['sync' => __('store.sync_resume_unavailable')]);
                }
                if (in_array($run->status, self::ACTIVE, true)) {
                    return [$run, false];
                }
                if (! $this->canResume($run, $locked)) {
                    throw ValidationException::withMessages(['sync' => __('store.sync_resume_unavailable')]);
                }
                $run->update([
                    'status' => 'queued', 'error' => null, 'finished_at' => null,
                    'stats' => [
                        ...$run->stats, 'step' => ($run->stats['step'] ?? 0) + 1,
                        'step_claimed' => false, 'phase' => 'queued', 'retry_at' => null,
                        'retry_reason' => null, 'rate_retries' => 0, 'transient_retries' => 0, 'error_detail' => null,
                        'resumed_at' => now()->toIso8601String(), 'heartbeat_at' => now()->toIso8601String(),
                    ],
                ]);

                return [$run, true];
            });
        } finally {
            $lock->release();
        }

        return $dispatch ? $this->dispatch($store, $run, $userId, false) : $run;
    }

    public function canResume(SyncRun $run, Store $store): bool
    {
        return $run->status === 'failed' && ($run->stats['mode'] ?? null) === 'paged'
            && (string) $store->platform_store_id === ($run->stats['zid_store_id'] ?? null)
            && filled($store->zid_access_token) && filled($store->zid_authorization)
            && app(ZidSyncStaging::class)->hasCheckpoint($run);
    }

    /** @return array{SyncRun, bool} */
    public function reserve(Store $store): array
    {
        return DB::transaction(function () use ($store): array {
            $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
            $this->expire($locked);
            $active = $locked->syncRuns()->where('type', 'zid_sync')->whereIn('status', self::ACTIVE)->first();
            if ($active) {
                return [$active, false];
            }
            // A fresh import replaces any resumable checkpoint of this store.
            app(ZidSyncStaging::class)->clearStore($locked);
            $changedSince = $this->changedSince($locked);

            return [$locked->syncRuns()->create([
                'type' => 'zid_sync', 'status' => 'queued',
                'stats' => [
                    'phase' => 'queued', 'products_read' => 0, 'orders_read' => 0,
                    'product_pages' => 0, 'order_pages' => 0,
                    'heartbeat_at' => now()->toIso8601String(),
                    'zid_store_id' => (string) $locked->platform_store_id,
                    'mode' => 'paged', 'step' => 0, 'step_claimed' => false,
                    'cursor_phase' => 'products', 'next_page' => 1,
                    'orders_since' => now()->startOfMonth()->subMonths(11)->toIso8601String(),
                    'scope' => $changedSince ? 'changes' : 'full',
                    'changed_since' => $changedSince?->toIso8601String(),
                ],
            ]), true];
        });
    }

    /**
     * After a complete import that stored its orders, read only what changed
     * since it started. The day of overlap absorbs time zone differences.
     */
    private function changedSince(Store $store): ?CarbonInterface
    {
        $runs = $store->syncRuns()->where('type', 'zid_sync')->where('status', 'completed');
        $previous = (clone $runs)->latest('id')->first();
        if (! $previous?->started_at || ! ($previous->stats['orders_stored'] ?? false)
            || ($previous->stats['zid_store_id'] ?? null) !== (string) $store->platform_store_id) {
            return null;
        }
        // A monthly full read corrects anything the change filters missed.
        $full = (clone $runs)->where('stats->scope', 'full')->latest('id')->first();
        if (! $full?->started_at?->gt(now()->subDays(30))) {
            return null;
        }

        return $previous->started_at->copy()->subDay();
    }

    public function claim(int $runId, Store $store, int $step = 0): ?SyncRun
    {
        return DB::transaction(function () use ($runId, $store, $step): ?SyncRun {
            $run = SyncRun::query()->whereKey($runId)->where('store_id', $store->id)->where('type', 'zid_sync')->lockForUpdate()->first();
            if (! $run || ! in_array($run->status, self::ACTIVE, true)
                || ($run->status === 'running' && ($run->stats['mode'] ?? null) !== 'paged')
                || ($run->stats['step'] ?? 0) !== $step || ($run->stats['step_claimed'] ?? false)) {
                return null;
            }
            $run->update([
                'status' => 'running', 'started_at' => $run->started_at ?? now(),
                'stats' => [
                    ...($run->stats ?? []), 'mode' => 'paged', 'step_claimed' => true,
                    'cursor_phase' => $run->stats['cursor_phase'] ?? 'products',
                    'orders_since' => $run->stats['orders_since'] ?? now()->startOfMonth()->subMonths(11)->toIso8601String(),
                ],
            ]);

            return $run;
        });
    }

    /**
     * Progress is written between API requests, outside the catalog transaction.
     *
     * @param  array<string, mixed>  $counts
     */
    public function progress(SyncRun $run, string $phase, array $counts = []): void
    {
        $stats = [...($run->stats ?? []), ...$counts, 'phase' => $phase, 'heartbeat_at' => now()->toIso8601String()];
        $active = SyncRun::query()->whereKey($run->id)->where('status', 'running');
        $changed = $active->update(['stats' => json_encode($stats, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        // MySQL reports zero changed rows for an identical heartbeat within
        // the same second. That does not mean the running row disappeared.
        if (! $changed && ! $active->exists()) {
            throw new RuntimeException('The Zid import is no longer active.');
        }
        $run->stats = $stats;
    }

    public function fail(int $runId, string $code, ?int $step = null, ?string $detail = null): void
    {
        SyncRun::query()->whereKey($runId)->whereIn('status', self::ACTIVE)
            ->when($step !== null, fn ($query) => $query->where('stats->step', $step))->update([
                'status' => 'failed', 'error' => $code, 'finished_at' => now(), 'updated_at' => now(),
                ...($detail !== null ? ['stats->error_detail' => $detail] : []),
            ]);
        // Keep committed pages and the cursor. A failed API call, save, worker
        // or deployment must not discard hours of successfully read data.
    }

    /** Recover rows left running after a killed worker or a deployment. */
    public function expire(Store $store): void
    {
        foreach ($store->syncRuns()->where('type', 'zid_sync')->whereIn('status', self::ACTIVE)->get() as $run) {
            $legacy = ! isset($run->stats['phase']);
            $deadline = $run->status === 'queued'
                ? $run->updated_at?->copy()->addMinutes(15)
                : ($run->started_at ?? $run->created_at)?->copy()->addSeconds($legacy ? 960 : 660);
            if (($run->stats['mode'] ?? null) === 'paged' && $run->status === 'running') {
                $deadline = $run->updated_at?->copy()->addSeconds(($run->stats['step_claimed'] ?? false) ? 660 : 900);
                if (! empty($run->stats['retry_at'])) {
                    $deadline = Carbon::parse($run->stats['retry_at'])->addMinutes(15);
                }
            }
            if ($deadline?->isPast()) {
                $this->fail($run->id, $run->status === 'queued' ? 'queue_stalled' : 'worker_stopped');
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function latest(Store $store): ?array
    {
        $this->expire($store);
        $run = $store->syncRuns()->where('type', 'zid_sync')->latest('id')->first();
        if (! $run) {
            return null;
        }

        return [
            'id' => $run->id, 'status' => $run->status,
            'phase' => $run->stats['phase'] ?? $run->status,
            'products_read' => $run->stats['products_read'] ?? $run->stats['products'] ?? 0,
            'orders_read' => $run->stats['orders_read'] ?? $run->stats['orders'] ?? 0,
            'product_pages' => $run->stats['product_pages'] ?? 0,
            'order_pages' => $run->stats['order_pages'] ?? 0,
            'products_saved' => $run->stats['products'] ?? 0,
            'orders_saved' => $run->stats['orders'] ?? 0,
            'scope' => $run->stats['scope'] ?? 'full',
            'changed_since' => $run->stats['changed_since'] ?? null,
            'error_code' => $run->status === 'failed' ? $run->error : null,
            'error_detail' => $run->status === 'failed' ? ($run->stats['error_detail'] ?? null) : null,
            'queued_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'updated_at' => $run->stats['heartbeat_at'] ?? $run->updated_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'retry_at' => $run->stats['retry_at'] ?? null,
            'retry_reason' => $run->stats['retry_reason'] ?? null,
            'can_resume' => $this->canResume($run, $store),
        ];
    }
}
