<?php

namespace App\Jobs;

use App\Models\Evaluation;
use App\Services\Evaluation\EvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one evaluation in the background (score → Gemini explanation →
 * modifications) so the upload form returns immediately.
 */
class RunEvaluation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public int $evaluationId) {}

    public function handle(EvaluationService $service): void
    {
        if (Evaluation::query()->whereKey($this->evaluationId)->where('status', 'pending')->update(['status' => 'processing', 'updated_at' => now()])) {
            $service->run(Evaluation::query()->findOrFail($this->evaluationId));
        }
    }

    public function failed(?Throwable $e): void
    {
        Evaluation::query()->whereKey($this->evaluationId)->where('status', 'processing')->update([
            'status' => 'failed',
            'error' => $e?->getMessage(),
        ]);
    }
}
