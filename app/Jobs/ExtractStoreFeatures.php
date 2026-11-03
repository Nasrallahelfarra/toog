<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Ai\Ai;
use App\Services\Catalog\FeatureExtraction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Works through a store's pending products one at a time (FR-1.4) and saves
 * progress on the run after each. It re-queues itself every few minutes so
 * syncs and evaluations are not stuck behind a large catalog.
 */
class ExtractStoreFeatures implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Seconds of work per job; one more product (image + model retries) still fits the timeout. */
    private const SLICE = 180;

    /** Consecutive model failures that stop the run instead of burning through the catalog. */
    private const MAX_FAILURES = 5;

    public function __construct(public int $runId, public ?int $userId = null) {}

    public function handle(Ai $ai, FeatureExtraction $extraction): void
    {
        $run = SyncRun::query()->find($this->runId);

        if (! $run || ! in_array($run->status, FeatureExtraction::ACTIVE, true)) {
            return;
        }

        $store = $run->store;
        $stats = $run->stats ?? [];
        $failures = 0;
        $until = microtime(true) + self::SLICE;
        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);

        while (microtime(true) < $until) {
            // Walk by id so a product the model failed on is not retried in the same run.
            $product = $extraction->pending($store->id)->where('id', '>', $stats['cursor'] ?? 0)->orderBy('id')->first();

            if (! $product) {
                $run->update(['status' => 'completed', 'stats' => [...$stats, 'current' => null], 'finished_at' => now()]);

                return;
            }

            if ($store->aiBudgetExceeded()) {
                $extraction->fail($run, 'budget_exceeded');

                return;
            }

            // Stop when the run was expired while the previous product was read.
            if ($run->fresh()?->status !== 'running') {
                return;
            }

            // Name the product in progress for the progress panel.
            $stats['current'] = $product->name;
            $run->update(['stats' => $stats]);
            $answered = (new ExtractProductFeatures($product->setRelation('store', $store), $this->userId))->handle($ai);
            $failures = $answered ? 0 : $failures + 1;
            $stats = [
                ...$stats,
                'cursor' => $product->id,
                'done' => ($stats['done'] ?? 0) + 1,
                'extracted' => ($stats['extracted'] ?? 0) + (int) $answered,
            ];
            $run->update(['stats' => $stats]);

            if ($failures >= self::MAX_FAILURES) {
                $extraction->fail($run, 'ai_unavailable');

                return;
            }
        }

        try {
            self::dispatch($this->runId, $this->userId);
        } catch (Throwable $e) {
            report($e);
            $extraction->fail($run, 'queue_failed');
        }
    }
}
