<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\SyncRun;
use App\Services\Analytics\ClassificationRefresh;
use App\Services\Analytics\PerformanceClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReclassifyProducts implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId, public ?int $userId = null) {}

    public function handle(PerformanceClassifier $classifier): void
    {
        $run = SyncRun::query()->where('type', ClassificationRefresh::TYPE)->find($this->runId);
        if (! $run || $run->status !== 'queued') {
            return;
        }
        $store = $run->store;
        if ($store->syncRuns()->where('type', 'zid_sync')->whereIn('status', ['queued', 'running'])->exists()) {
            $this->release(15);

            return;
        }
        $lock = Cache::lock(SyncStoreData::lockKey($store), 660);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            if (! SyncRun::query()->whereKey($run->id)->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => now()])) {
                return;
            }
            DB::transaction(function () use ($classifier, $run, $store): void {
                $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
                $classifier->classify($locked);
                $saved = SyncRun::query()->whereKey($run->id)->where('status', 'running')->update([
                    'status' => 'completed', 'finished_at' => now(),
                    'stats' => json_encode(['total' => $locked->products()->count()], JSON_THROW_ON_ERROR),
                ]);
                if (! $saved) {
                    throw new RuntimeException('Classification request is no longer active.');
                }
                ActivityLog::query()->create(['user_id' => $this->userId, 'store_id' => $store->id,
                    'action' => 'data.reclassified', 'subject_type' => $store->getMorphClass(),
                    'subject_id' => $store->id, 'created_at' => now()]);
            });
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($e instanceof TimeoutExceededException && DB::transactionLevel() > 0) {
            DB::rollBack(0);
        }
        app(ClassificationRefresh::class)->fail($this->runId, $e instanceof TimeoutExceededException ? 'timed_out' : 'job_failed');
    }
}
