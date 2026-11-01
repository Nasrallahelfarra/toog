<?php

namespace App\Services\Zid;

use App\Jobs\CheckZidConnection;
use App\Jobs\SyncStoreData;
use App\Models\Store;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
                ],
            ]), true];
        });
    }

    public function claim(int $runId, Store $store): ?SyncRun
    {
        $changed = SyncRun::query()->whereKey($runId)->where('store_id', $store->id)
            ->where('type', 'zid_sync')->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);

        return $changed ? SyncRun::query()->findOrFail($runId) : null;
    }

    /**
     * Progress is written between API requests, outside the catalog transaction.
     *
     * @param  array<string, int>  $counts
     */
    public function progress(SyncRun $run, string $phase, array $counts = []): void
    {
        $stats = [...($run->stats ?? []), ...$counts, 'phase' => $phase, 'heartbeat_at' => now()->toIso8601String()];
        if (! SyncRun::query()->whereKey($run->id)->where('status', 'running')->update(['stats' => json_encode($stats, JSON_THROW_ON_ERROR), 'updated_at' => now()])) {
            throw new RuntimeException('The Zid import is no longer active.');
        }
        $run->stats = $stats;
    }

    public function fail(int $runId, string $code): void
    {
        SyncRun::query()->whereKey($runId)->whereIn('status', self::ACTIVE)->update([
            'status' => 'failed', 'error' => $code, 'finished_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Recover rows left running after a killed worker or a deployment. */
    public function expire(Store $store): void
    {
        foreach ($store->syncRuns()->where('type', 'zid_sync')->whereIn('status', self::ACTIVE)->get() as $run) {
            $legacy = ! isset($run->stats['phase']);
            $deadline = $run->status === 'queued'
                ? $run->created_at?->copy()->addMinutes(15)
                : ($run->started_at ?? $run->created_at)?->copy()->addSeconds($legacy ? 960 : 660);
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
        ];
    }
}
