<?php

namespace App\Jobs;

use App\Models\CompetitorScan;
use App\Services\Market\CompetitorMonitor;
use App\Services\Market\CompetitorScanState;
use App\Services\Market\Fetchers\FetchResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

class ScanCompetitor implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $scanId) {}

    public function handle(CompetitorMonitor $monitor, CompetitorScanState $state): void
    {
        $scan = CompetitorScan::query()->with('competitor.store')->find($this->scanId);
        if (! $scan || $scan->status !== 'queued') {
            return;
        }
        if (! $scan->competitor->is_active || ! $scan->competitor->store->isActive()) {
            $state->fail($scan, 'inactive');

            return;
        }
        $result = $monitor->scan($scan->competitor, $scan);

        // Social posts feed the store's trends; refresh them once read.
        if ($result->status === FetchResult::OK && $scan->competitor->isSocial()) {
            DetectTrends::dispatch($scan->competitor->store_id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($scan = CompetitorScan::query()->find($this->scanId)) {
            app(CompetitorScanState::class)->fail($scan,
                $exception instanceof TimeoutExceededException ? 'timed_out' : 'job_failed');
        }
    }
}
