<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Market\CompetitorMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scans every active competitor of a store (FR-2.2), then refreshes the
 * store's trends from the new data.
 */
class ScanCompetitors implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $storeId) {}

    public function handle(CompetitorMonitor $monitor): void
    {
        $store = Store::query()->find($this->storeId);

        if (! $store?->isActive()) {
            return;
        }

        $monitor->scanStore($store);

        DetectTrends::dispatch($store->id);
    }
}
