<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;

/**
 * Sources without a public, terms-compliant feed. Salla and Zid storefronts
 * and TikTok / Instagram / Snapchat need a licensed data provider or
 * official API access before they can be monitored automatically; until
 * then their data is entered or imported manually.
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
