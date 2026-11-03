<?php

namespace App\Jobs;

use App\Models\ProductRecommendation;
use App\Services\Catalog\ProductRecovery;
use App\Services\Catalog\RecommendationProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

class RecommendStagnantProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Covers two bounded Gemini attempts plus image retrieval and local analysis.
    public int $timeout = 360;

    public bool $failOnTimeout = true;

    public function __construct(public int $recommendationId) {}

    public function handle(ProductRecovery $service): void
    {
        $run = ProductRecommendation::query()->find($this->recommendationId);
        if (! $run || app(RecommendationProgress::class)->refresh($run)->status !== 'pending') {
            return;
        }
        $claimed = ProductRecommendation::query()->whereKey($this->recommendationId)->where('status', 'pending')->update([
            'status' => 'processing',
            'review' => json_encode(['_progress' => ['phase' => 'context', 'started_at' => now()->toIso8601String()]], JSON_THROW_ON_ERROR),
        ]);
        if ($claimed) {
            $service->run(ProductRecommendation::query()->findOrFail($this->recommendationId));
        }
    }

    public function failed(?Throwable $e): void
    {
        app(RecommendationProgress::class)->fail($this->recommendationId, $e instanceof MaxAttemptsExceededException ? 'timed_out' : 'review_failed');
    }
}
