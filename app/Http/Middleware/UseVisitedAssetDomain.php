<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scripts, styles and fonts load from the domain the page was opened on.
 *
 * Laravel Cloud sets ASSET_URL to its own laravel.cloud address, so a page
 * opened on a custom domain (or the other way round) loaded its assets
 * from the other domain and the browser blocked them (CORS). Only our own
 * domains (APP_URL and ASSET_URL) are followed; any other Host header
 * keeps the default, and the scheme comes from the configuration.
 */
class UseVisitedAssetDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $known = array_filter(array_map(
            fn ($url) => is_string($url) && $url !== '' ? strtolower((string) parse_url($url, PHP_URL_HOST)) : null,
            [config('app.url'), config('app.asset_url')],
        ));

        if ($host !== '' && in_array($host, $known, true)) {
            $scheme = parse_url((string) (config('app.asset_url') ?: config('app.url')), PHP_URL_SCHEME) ?: 'https';
            URL::useAssetOrigin("{$scheme}://{$host}");
        }

        return $next($request);
    }
}
