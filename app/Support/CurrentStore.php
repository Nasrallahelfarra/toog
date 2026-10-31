<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves which store the signed-in user is working in.
 *
 * Store owners work in their own store (and can switch if they own more
 * than one). The platform admin can open any store; while doing so they
 * act with the owner's full permissions and every action is logged as
 * "acting as admin".
 */
class CurrentStore
{
    public const SESSION_KEY = 'current_store_id';

    private ?Store $store = null;

    private bool $resolved = false;

    public function __construct(private readonly Request $request) {}

    public function get(): ?Store
    {
        if ($this->resolved) {
            return $this->store;
        }

        $this->resolved = true;
        $user = $this->request->user();

        if (! $user instanceof User) {
            return null;
        }

        $id = $this->request->session()->get(self::SESSION_KEY);

        if ($user->isAdmin()) {
            return $this->store = $id ? Store::query()->find($id) : null;
        }

        $stores = $user->stores();

        return $this->store = ($id ? (clone $stores)->whereKey($id)->first() : null) ?? $stores->first();
    }

    public function require(): Store
    {
        return $this->get() ?? abort(404);
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function set(Store $store): void
    {
        $this->request->session()->put(self::SESSION_KEY, $store->id);
        $this->store = $store;
        $this->resolved = true;
    }

    public function forget(): void
    {
        $this->request->session()->forget(self::SESSION_KEY);
        $this->store = null;
        $this->resolved = true;
    }

    /**
     * True when the platform admin is operating inside a store.
     */
    public function actingAsAdmin(): bool
    {
        return (bool) $this->request->user()?->isAdmin() && $this->get() !== null;
    }
}
