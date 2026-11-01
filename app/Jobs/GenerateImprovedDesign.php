<?php

namespace App\Jobs;

use App\Models\DesignImprovement;
use App\Services\Design\ImprovementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateImprovedDesign implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public int $improvementId) {}

    public function handle(ImprovementService $service): void
    {
        if (DesignImprovement::query()->whereKey($this->improvementId)->where('status', 'queued')->update(['status' => 'generating', 'updated_at' => now()])) {
            $service->generate(DesignImprovement::query()->findOrFail($this->improvementId));
        }
    }

    public function failed(?Throwable $exception): void
    {
        DesignImprovement::query()->whereKey($this->improvementId)->whereIn('status', ['generating', 'review_queued'])
            ->update(['status' => 'failed', 'error' => 'generation_failed']);
    }
}
