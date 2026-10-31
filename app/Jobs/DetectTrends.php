<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Market\TrendDetector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DetectTrends implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $storeId) {}

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function handle(TrendDetector $detector): void
    {
        $store = Store::query()->find($this->storeId);

        if ($store?->isActive()) {
            $detector->detect($store);
        }
    }
}
