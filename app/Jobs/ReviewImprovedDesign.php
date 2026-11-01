<?php

namespace App\Jobs;

use App\Models\DesignImprovement;
use App\Services\Design\ImprovementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReviewImprovedDesign implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $improvementId) {}

    public function handle(ImprovementService $service): void
    {
        if (DesignImprovement::query()->whereKey($this->improvementId)->where('status', 'review_queued')->update(['status' => 'reviewing', 'updated_at' => now()])) {
            $service->review(DesignImprovement::query()->findOrFail($this->improvementId));
        }
    }

    public function failed(?Throwable $exception): void
    {
        DesignImprovement::query()->whereKey($this->improvementId)->where('status', 'reviewing')
            ->update(['status' => 'failed', 'error' => 'review_failed']);
    }
}
