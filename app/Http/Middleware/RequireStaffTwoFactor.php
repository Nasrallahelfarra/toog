<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentStore;
use App\Support\StoreAccess;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the store owner requires two-factor sign-in for the team, a staff
 * member who has not set it up yet is sent to their security page and kept
 * out of the store until they do. Owners and the admin are never held here.
 */
class RequireStaffTwoFactor
{
    public function __construct(
        private readonly CurrentStore $current,
        private readonly StoreAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $store = $this->current->get();

        if (! $user instanceof User
            || ! $store?->requiresStaffTwoFactor()
            || ! $this->access->isStaff()
            || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if (! $request->isMethod('GET') || $request->expectsJson()) {
            abort(403, __('mojo.two_factor_required'));
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => __('mojo.two_factor_required')]);

        return redirect()->route('security.edit');
    }
}
