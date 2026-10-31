<?php

namespace App\Jobs;

use App\Models\CompetitorScan;
use App\Services\Market\CompetitorMonitor;
use App\Services\Market\CompetitorScanState;
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
        $monitor->scan($scan->competitor, $scan);
    }

    public function failed(?Throwable $exception): void
    {
        if ($scan = CompetitorScan::query()->find($this->scanId)) {
            app(CompetitorScanState::class)->fail($scan,
                $exception instanceof TimeoutExceededException ? 'timed_out' : 'job_failed');
        }
    }
}
