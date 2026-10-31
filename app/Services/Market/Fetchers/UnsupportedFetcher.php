<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;

/**
 * Social networks require a separate connector. Public storefronts are
 * handled by the catalog / structured-data crawler.
 */
class UnsupportedFetcher implements CompetitorFetcher
{
    public function supports(Competitor $competitor): bool
    {
        return true;
    }

    public function fetch(Competitor $competitor): FetchResult
    {
        return FetchResult::unsupported($competitor->isSocial() ? 'needs_api_access' : 'needs_provider');
    }
}
