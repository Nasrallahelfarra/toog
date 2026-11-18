<?php

namespace App\Http\Middleware;

use App\Support\StoreAccess;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a staff member inside the sections the store owner granted them.
 * Runs after the "store" middleware. Opening a closed page sends the member
 * to their home; a closed action is refused.
 */
class EnsureSectionAllowed
{
    public function __construct(private readonly StoreAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName();

        if ($this->access->allowsRoute($route)) {
            return $next($request);
        }

        if (! $request->isMethod('GET') || $request->expectsJson()) {
            abort(403, __('mojo.section_forbidden'));
        }

        // The dashboard is where sign-in lands, so being moved on from it
        // is not an error worth announcing.
        if ($route !== 'store.dashboard') {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('mojo.section_forbidden')]);
        }

        return redirect()->route($this->access->home());
    }
}
