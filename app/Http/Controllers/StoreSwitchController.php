<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreSwitchController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    /**
     * Open a store dashboard. Owners can switch between their own stores;
     * the admin can open any store and act with full owner permissions.
     */
    public function enter(Request $request, Store $store): RedirectResponse
    {
        abort_unless($request->user()->canAccessStore($store), 403);

        $this->current->set($store);

        if ($request->user()->isAdmin()) {
            Activity::log('admin.store_entered', $store, storeId: $store->id);
        }

        return to_route('store.dashboard');
    }

    /**
     * Admin leaves the store and returns to the platform dashboard.
     */
    public function exit(Request $request): RedirectResponse
    {
        $this->current->forget();

        return $request->user()->isAdmin() ? to_route('admin.dashboard') : to_route('store.dashboard');
    }

    /**
     * Home after login: admins go to the platform panel, owners to their store.
     */
    public function home(Request $request): RedirectResponse
    {
        return $request->user()->isAdmin() && ! $this->current->get()
            ? to_route('admin.dashboard')
            : to_route('store.dashboard');
    }

    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);

        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->saveQuietly();

        return back();
    }
}
