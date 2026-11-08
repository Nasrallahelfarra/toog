<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;
use Closure;

/**
 * Competitors no fetcher can read yet: Instagram, TikTok and Facebook
 * until an Apify token is set, Snapchat, and storefronts without a URL.
 */
class UnsupportedFetcher implements CompetitorFetcher
{
    public function supports(Competitor $competitor): bool
    {
        return true;
    }

    public function fetch(Competitor $competitor, ?Closure $progress = null): FetchResult
    {
        return FetchResult::unsupported($this->reason($competitor));
    }

    /** Machine key for what this competitor still needs before a scan. */
    public function reason(Competitor $competitor): string
    {
        return match (true) {
            in_array($competitor->platform, ApifySocialFetcher::PLATFORMS, true) => 'needs_apify',
            $competitor->isSocial() => 'needs_api_access',
            default => 'needs_provider',
        };
    }
}
