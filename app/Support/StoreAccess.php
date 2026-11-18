<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * What the signed-in user may open in the current store.
 *
 * The platform admin and the store's owners open everything. A staff
 * member opens only the sections the owner granted; these are read from
 * the membership row on every request, never cached in the session, so a
 * change by the owner applies at once.
 */
class StoreAccess
{
    /** @var array<string, array{owner: bool, member: bool, sections: list<string>}> */
    private array $resolved = [];

    public function __construct(private readonly CurrentStore $current) {}

    /**
     * Full access: the platform admin, or an owner of this store.
     */
    public function isOwner(): bool
    {
        return $this->resolve()['owner'];
    }

    /**
     * A member the owner added with limited sections.
     */
    public function isStaff(): bool
    {
        $state = $this->resolve();

        return $state['member'] && ! $state['owner'];
    }

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        return $this->resolve()['sections'];
    }

    /**
     * True when the user holds at least one of the sections.
     */
    public function can(string ...$sections): bool
    {
        return $this->isOwner() || array_intersect($sections, $this->sections()) !== [];
    }

    public function allowsRoute(?string $name): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        $needed = StoreSections::forRoute($name);

        return $needed === StoreSections::ANYONE
            ? $this->resolve()['member']
            : array_intersect($needed, $this->sections()) !== [];
    }

    /**
     * Where this user lands in the store.
     */
    public function home(): string
    {
        return $this->isOwner() || $this->can('dashboard') ? 'store.dashboard' : 'store.my-products.index';
    }

    /**
     * @return array{owner: bool, member: bool, sections: list<string>}
     */
    private function resolve(): array
    {
        $user = Auth::user();
        $store = $this->current->get();
        $key = ($user?->getAuthIdentifier() ?? 0).':'.($store?->id ?? 0);

        return $this->resolved[$key] ??= $this->read($user instanceof User ? $user : null, $store);
    }

    /**
     * @return array{owner: bool, member: bool, sections: list<string>}
     */
    private function read(?User $user, ?Store $store): array
    {
        $none = ['owner' => false, 'member' => false, 'sections' => []];

        if (! $user || ! $store) {
            return $none;
        }

        $all = ['owner' => true, 'member' => true, 'sections' => StoreSections::all()];

        if ($user->isAdmin() || $store->owner_id === $user->id) {
            return $all;
        }

        $membership = DB::table('store_user')->where('store_id', $store->id)->where('user_id', $user->id)->first(['role', 'permissions']);

        if (! $membership) {
            return $none;
        }

        // Anything but an explicit staff row keeps the access it always had.
        if ($membership->role !== Store::ROLE_STAFF) {
            return $all;
        }

        $granted = json_decode((string) ($membership->permissions ?? '[]'), true);

        return ['owner' => false, 'member' => true, 'sections' => StoreSections::clean(is_array($granted) ? $granted : [])];
    }
}
