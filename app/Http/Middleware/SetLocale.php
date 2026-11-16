<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arabic (RTL) by default; English on request (FR-6.5).
 */
class SetLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // Not the environment's APP_LOCALE: the platform opens in Arabic.
        $locale = $request->user()?->locale ?? $request->session()->get('locale') ?? 'ar';

        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'ar');

        return $next($request);
    }
}
