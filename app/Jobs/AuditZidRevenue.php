<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\SyncRun;
use App\Services\Zid\RevenueAudit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * Runs a revenue audit in the background — reading a month of orders from
 * Zid can outlast a web request — and saves the report on its SyncRun.
 */
class AuditZidRevenue implements ShouldQueue
{
    use Queueable;

    public int $timeout = 540;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function handle(RevenueAudit $audit): void
    {
        $run = SyncRun::query()->where('type', RevenueAudit::TYPE)->whereKey($this->runId)->where('status', 'queued')->first();
        $store = $run ? Store::query()->find($run->store_id) : null;
        if (! $run || ! $store) {
            return;
        }

        $run->update(['status' => 'running', 'started_at' => now(), 'stats' => [...$run->stats, 'phase' => 'reading', 'heartbeat_at' => now()->toIso8601String()]]);

        try {
            $report = $audit->run($store, $run->stats['month'], $run->stats['expected'] ?? null, function (int $pages, int $orders) use ($run): void {
                $run->update(['stats' => [...$run->stats, 'pages' => $pages, 'orders_read' => $orders, 'heartbeat_at' => now()->toIso8601String()]]);
            });
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500), 'stats' => [...$run->stats, 'phase' => 'failed']]);
            report($e);

            return;
        }

        $run->update(['status' => 'completed', 'finished_at' => now(), 'stats' => [...$run->stats, 'phase' => 'completed', 'report' => $report]]);
    }

    public function failed(?Throwable $exception): void
    {
        SyncRun::query()->whereKey($this->runId)->whereIn('status', RevenueAudit::ACTIVE)->update([
            'status' => 'failed', 'finished_at' => now(),
            'error' => $exception instanceof TimeoutExceededException ? 'timed_out' : 'job_failed',
        ]);
    }
}
