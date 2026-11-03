<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Ai\Ai;
use App\Services\Catalog\FeatureExtraction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * Works through a store's pending products one at a time (FR-1.4) and saves
 * progress on the run after each. It re-queues itself in short batches so
 * syncs and evaluations are not stuck behind a large catalog.
 */
class ExtractStoreFeatures implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    // Defaults also apply to jobs serialized before resumable steps existed.
    public int $step = 0;

    /** Seconds of work per job; one more product (image + model retries) still fits the timeout. */
    private const SLICE = 45;

    private const PRODUCTS_PER_JOB = 10;

    /** Consecutive model failures that stop the run instead of burning through the catalog. */
    private const MAX_FAILURES = 5;

    public function __construct(public int $runId, public ?int $userId = null, int $step = 0)
    {
        $this->step = $step;
    }

    public function handle(Ai $ai, FeatureExtraction $extraction): void
    {
        $run = $extraction->claim($this->runId, $this->step);
        if (! $run) {
            return;
        }

        $store = $run->store;
        $processed = 0;
        $until = microtime(true) + self::SLICE;

        while (microtime(true) < $until && $processed < self::PRODUCTS_PER_JOB) {
            // Walk by id so a product the model failed on is not retried in the same run.
            $product = $extraction->pending($store->id)->where('id', '>', $run->stats['cursor'] ?? 0)->orderBy('id')->first();

            if (! $product) {
                $extraction->complete($run, $this->step);

                return;
            }

            if ($store->aiBudgetExceeded()) {
                $extraction->fail($run, 'budget_exceeded', step: $this->step);

                return;
            }

            if (! $extraction->current($run, $this->step, $product)) {
                return;
            }

            $result = (new ExtractProductFeatures($product->setRelation('store', $store), $this->userId))->read($ai);
            if (! $extraction->checkpoint($run, $this->step, $product->id, $result)) {
                return;
            }
            $processed++;

            if (($run->stats['consecutive_failures'] ?? 0) >= self::MAX_FAILURES) {
                $extraction->fail($run, 'ai_unavailable', step: $this->step);

                return;
            }
        }

        if (! $extraction->pending($store->id)->where('id', '>', $run->stats['cursor'] ?? 0)->exists()) {
            $extraction->complete($run, $this->step);
        } else {
            $extraction->next($run, $this->step, $this->userId);
        }
    }

    /**
     * Close the run when the worker gives up on this job (an exception or a
     * timeout), so the panel shows the reason instead of waiting forever.
     */
    public function failed(?Throwable $e): void
    {
        if ($run = SyncRun::query()->find($this->runId)) {
            // TimeoutExceededException also extends MaxAttemptsExceededException.
            $code = $e instanceof MaxAttemptsExceededException ? 'worker_stopped' : 'job_failed';
            app(FeatureExtraction::class)->fail($run, $code, $e?->getMessage(), $this->step);
        }
    }
}
