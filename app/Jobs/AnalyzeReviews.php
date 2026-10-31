<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Market\SentimentAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sentiment and topics for the store's unanalysed reviews (FR-2.4).
 */
class AnalyzeReviews implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public int $storeId, public ?int $userId = null) {}

    public function handle(SentimentAnalyzer $analyzer): void
    {
        $store = Store::query()->find($this->storeId);

        if (! $store) {
            return;
        }

        // Batches keep each model call small.
        while ($analyzer->analyzePending($store, 40, $this->userId) > 0) {
            //
        }
    }
}
