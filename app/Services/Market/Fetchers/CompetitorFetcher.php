<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;
use Closure;

/**
 * Collects public data for one competitor. One implementation per source;
 * add a data-provider fetcher (e.g. for Salla or TikTok) by implementing
 * this interface and registering it in CompetitorMonitor.
 */
interface CompetitorFetcher
{
    public function supports(Competitor $competitor): bool;

    /** @param Closure(string, array<string, int>): void|null $progress */
    public function fetch(Competitor $competitor, ?Closure $progress = null): FetchResult;
}
