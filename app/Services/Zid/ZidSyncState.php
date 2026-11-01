<?php

namespace App\Services\Zid;

use App\Jobs\CheckZidConnection;
use App\Jobs\SyncStoreData;
use App\Models\Store;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        try {
            $driver = config('queue.connections.'.config('queue.default').'.driver');
            if (in_array($driver, ['sync', 'null', 'deferred'], true)) {
                throw new RuntimeException('Zid imports require a background queue worker.');
            }
            $job = new SyncStoreData($store, $userId, $run->id);
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
                ],
            ]), true];
        });
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
        if (! SyncRun::query()->whereKey($run->id)->where('status', 'running')->update(['stats' => json_encode($stats, JSON_THROW_ON_ERROR), 'updated_at' => now()])) {
            throw new RuntimeException('The Zid import is no longer active.');
        }
        $run->stats = $stats;
    }

    public function fail(int $runId, string $code, ?int $step = null): void
    {
        $changed = SyncRun::query()->whereKey($runId)->whereIn('status', self::ACTIVE)
            ->when($step !== null, fn ($query) => $query->where('stats->step', $step))->update([
                'status' => 'failed', 'error' => $code, 'finished_at' => now(), 'updated_at' => now(),
            ]);
        if ($changed && Schema::hasTable('zid_sync_pages')) {
            app(ZidSyncStaging::class)->clear($runId);
        }
    }

    /** Recover rows left running after a killed worker or a deployment. */
    public function expire(Store $store): void
    {
        foreach ($store->syncRuns()->where('type', 'zid_sync')->whereIn('status', self::ACTIVE)->get() as $run) {
            $legacy = ! isset($run->stats['phase']);
            $deadline = $run->status === 'queued'
                ? $run->created_at?->copy()->addMinutes(15)
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
            'error_code' => $run->status === 'failed' ? $run->error : null,
            'queued_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'updated_at' => $run->stats['heartbeat_at'] ?? $run->updated_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'retry_at' => $run->stats['retry_at'] ?? null,
        ];
    }
}
