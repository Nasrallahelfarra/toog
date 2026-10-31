<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;

/**
 * Collects public data for one competitor. One implementation per source;
 * add a data-provider fetcher (e.g. for Salla or TikTok) by implementing
 * this interface and registering it in CompetitorMonitor.
 */
interface CompetitorFetcher
{
    public function supports(Competitor $competitor): bool;

    public function fetch(Competitor $competitor): FetchResult;
}
