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
        $locale = $request->user()?->locale ?? $request->session()->get('locale') ?? config('app.locale');

        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'ar');

        return $next($request);
    }
}
