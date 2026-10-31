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

    public int $timeout = 240;

    public function __construct(public int $evaluationId) {}

    public function handle(EvaluationService $service): void
    {
        $evaluation = Evaluation::query()->find($this->evaluationId);

        if ($evaluation && ! $evaluation->isFinished()) {
            $service->run($evaluation);
        }
    }

    public function failed(?Throwable $e): void
    {
        Evaluation::query()->whereKey($this->evaluationId)->update([
            'status' => 'failed',
            'error' => $e?->getMessage(),
        ]);
    }
}
