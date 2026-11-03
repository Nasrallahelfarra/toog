<?php

namespace App\Jobs;

use App\Models\ProductRecommendation;
use App\Services\Catalog\ProductRecovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RecommendStagnantProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $recommendationId) {}

    public function handle(ProductRecovery $service): void
    {
        $claimed = ProductRecommendation::query()->whereKey($this->recommendationId)->where('status', 'pending')->update(['status' => 'processing']);
        if ($claimed) {
            $service->run(ProductRecommendation::query()->findOrFail($this->recommendationId));
        }
    }

    public function failed(?Throwable $e): void
    {
        ProductRecommendation::query()->whereKey($this->recommendationId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error' => 'review_failed']);
    }
}
