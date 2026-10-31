<?php

namespace App\Http\Middleware;

use App\Support\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the store dashboard: there must be a current store, and a
 * suspended store is closed to its owner (the admin can still open it).
 */
class EnsureStoreSelected
{
    public function __construct(private readonly CurrentStore $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $store = $this->current->get();

        if (! $store) {
            return $user?->isAdmin()
                ? redirect()->route('admin.stores.index')
                : Inertia::render('store/no-store')->toResponse($request);
        }

        if (! $store->isActive() && ! $user?->isAdmin()) {
            return Inertia::render('store/suspended', [
                'reason' => $store->suspended_reason,
            ])->toResponse($request);
        }

        return $next($request);
    }
}
