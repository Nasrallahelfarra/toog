<?php

namespace App\Jobs;

use App\Models\CustomerReview;
use App\Models\Store;
use App\Models\SyncRun;
use App\Services\Zid\ZidApiException;
use App\Services\Zid\ZidRateLimited;
use App\Services\Zid\ZidReviewImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads the store's customer reviews from Zid, then analyses the new ones.
 * Each run is a sync run ("zid_reviews") so the page can show when the
 * reviews were last read and why a read failed.
 */
class ImportZidReviews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public int $storeId, public ?int $userId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function handle(ZidReviewImporter $importer): void
    {
        $store = Store::query()->find($this->storeId);
        if (! $store || ! CustomerReview::fromZid($store)) {
            return;
        }

        $run = SyncRun::query()->create(['store_id' => $store->id, 'type' => 'zid_reviews', 'status' => 'running', 'started_at' => now()]);

        try {
            $stats = $importer->import($store);
        } catch (ZidRateLimited $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'rate_limited']);
            $this->release($e->retryAfter);

            return;
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => match (true) {
                $e instanceof ZidApiException && in_array($e->httpStatus, [401, 403], true) => 'authorization_failed',
                $e instanceof ZidApiException => 'zid_rejected',
                default => 'import_failed',
            }]);
            report($e);

            return;
        }

        $run->update(['status' => 'completed', 'finished_at' => now(), 'stats' => $stats]);

        if (CustomerReview::query()->shownFor($store)->whereNull('analyzed_at')->exists()) {
            AnalyzeReviews::dispatch($store->id, $this->userId);
        }
    }
}
