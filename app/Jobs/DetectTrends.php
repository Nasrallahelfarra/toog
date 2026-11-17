<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Market\PostLookReader;
use App\Services\Market\TrendDetector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DetectTrends implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $storeId) {}

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function handle(TrendDetector $detector, PostLookReader $looks): void
    {
        $store = Store::query()->find($this->storeId);

        if ($store?->isActive()) {
            // Posts are pictures of products: read the new ones (once each)
            // before the analysis. A failed read never blocks the trends.
            try {
                $looks->read($store);
            } catch (Throwable $e) {
                report($e);
            }
            $detector->detect($store);
        }
    }
}
