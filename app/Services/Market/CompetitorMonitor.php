<?php

namespace App\Services\Market;

use App\Models\Competitor;
use App\Models\Store;
use App\Models\SyncRun;
use App\Services\Market\Fetchers\CompetitorFetcher;
use App\Services\Market\Fetchers\FetchResult;
use App\Services\Market\Fetchers\ShopifyFetcher;
use App\Services\Market\Fetchers\UnsupportedFetcher;
use Throwable;

/**
 * Runs the right fetcher for each competitor and records the outcome.
 */
class CompetitorMonitor
{
    /** @var list<CompetitorFetcher> */
    private array $fetchers;

    public function __construct(ShopifyFetcher $shopify, UnsupportedFetcher $fallback)
    {
        // Order matters: the first fetcher that supports a competitor wins.
        $this->fetchers = [$shopify, $fallback];
    }

    public function canScan(Competitor $competitor): bool
    {
        return ! $this->fetcherFor($competitor) instanceof UnsupportedFetcher;
    }

    public function scan(Competitor $competitor): FetchResult
    {
        try {
            $result = $this->fetcherFor($competitor)->fetch($competitor);
        } catch (Throwable $e) {
            report($e);
            $result = FetchResult::failed($e->getMessage(), 'exception');
        }

        if ($result->status !== FetchResult::UNSUPPORTED) {
            $competitor->update([
                'last_checked_at' => now(),
                'last_error' => $result->status === FetchResult::FAILED ? mb_substr((string) $result->message, 0, 250) : null,
            ]);
        }

        return $result;
    }

    /**
     * Scan every active competitor of a store and log one sync run.
     *
     * @return array<string, mixed> totals
     */
    public function scanStore(Store $store): array
    {
        $run = SyncRun::query()->create([
            'store_id' => $store->id, 'type' => 'competitor_scan', 'status' => 'running', 'started_at' => now(),
        ]);

        $totals = ['scanned' => 0, 'added' => 0, 'updated' => 0, 'price_changes' => 0, 'failed' => 0, 'unsupported' => 0];

        foreach ($store->competitors()->where('is_active', true)->get() as $competitor) {
            $result = $this->scan($competitor);

            match ($result->status) {
                FetchResult::OK => $totals['scanned']++,
                FetchResult::FAILED => $totals['failed']++,
                default => $totals['unsupported']++,
            };

            $totals['added'] += $result->added;
            $totals['updated'] += $result->updated;
            $totals['price_changes'] += $result->priceChanges;
        }

        $run->update([
            'status' => $totals['failed'] > 0 && $totals['scanned'] === 0 ? 'failed' : 'completed',
            'stats' => $totals,
            'finished_at' => now(),
        ]);

        return $totals;
    }

    private function fetcherFor(Competitor $competitor): CompetitorFetcher
    {
        foreach ($this->fetchers as $fetcher) {
            if ($fetcher->supports($competitor)) {
                return $fetcher;
            }
        }

        return new UnsupportedFetcher;
    }
}
