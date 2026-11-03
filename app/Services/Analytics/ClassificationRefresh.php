<?php

namespace App\Services\Analytics;

use App\Jobs\ReclassifyProducts;
use App\Models\Store;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class ClassificationRefresh
{
    public const TYPE = 'product_classification';

    public const ACTIVE = ['queued', 'running'];

    public function start(Store $store, ?int $userId): SyncRun
    {
        // Do not wait on the store row: a catalog import may hold it for
        // minutes. Serialize only the short enqueue request with a cache lock.
        $lock = Cache::lock('classification-request:'.$store->id, 10);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['classification' => __('store.classification_busy')]);
        }
        try {
            $this->expire($store);
            $active = $store->syncRuns()->where('type', self::TYPE)->whereIn('status', self::ACTIVE)->first();
            if ($active) {
                return $active;
            }
            $run = $store->syncRuns()->create(['type' => self::TYPE, 'status' => 'queued']);
            $driver = config('queue.connections.'.config('queue.default').'.driver');
            if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                $this->fail($run->id, 'queue_unavailable');

                return $run->refresh();
            }
            try {
                ReclassifyProducts::dispatch($run->id, $userId);
            } catch (Throwable $e) {
                report($e);
                $this->fail($run->id, 'queue_failed');
            }

            return $run->refresh();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed>|null */
    public function latest(Store $store): ?array
    {
        $this->expire($store);
        $run = $store->syncRuns()->where('type', self::TYPE)->latest('id')->first();

        return $run ? [
            'id' => $run->id, 'status' => $run->status, 'error' => $run->error,
            'total' => (int) ($run->stats['total'] ?? 0),
            'queued_at' => $run->created_at?->toIso8601String(),
            'recent' => in_array($run->status, self::ACTIVE, true) || (bool) $run->finished_at?->gt(now()->subDay()),
        ] : null;
    }

    public function fail(int $runId, string $error): void
    {
        SyncRun::query()->whereKey($runId)->where('type', self::TYPE)->whereIn('status', self::ACTIVE)
            ->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
    }

    private function expire(Store $store): void
    {
        $runs = $store->syncRuns()->where('type', self::TYPE)->whereIn('status', self::ACTIVE)->get();
        foreach ($runs as $run) {
            $column = $run->status === 'running' ? 'started_at' : 'created_at';
            $cutoff = now()->subMinutes($run->status === 'running' ? 11 : 30);
            if ($run->getAttribute($column)?->lt($cutoff)) {
                SyncRun::query()->whereKey($run->id)->where('status', $run->status)->where($column, '<', $cutoff)
                    ->update(['status' => 'failed', 'error' => 'timed_out', 'finished_at' => now()]);
            }
        }
    }
}
