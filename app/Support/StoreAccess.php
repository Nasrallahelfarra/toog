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
 *
 * A page switched off for stores (StoreSections::enabled) is closed to all
 * of them, owners included.
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
     * The sections this user opens: every page that is switched on for an
     * owner, the granted ones among them for staff.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return array_values(array_intersect($this->resolve()['sections'], StoreSections::enabled()));
    }

    /** Pages that are not sections: always the owner's, never staff's. */
    public const OWNER_PAGES = ['connection'];

    /**
     * True when the user opens at least one of the sections. "connection"
     * (the Zid connection) is the owner's and never switched off.
     */
    public function can(string ...$sections): bool
    {
        if ($this->isOwner() && array_intersect($sections, self::OWNER_PAGES) !== []) {
            return true;
        }

        return array_intersect($sections, $this->sections()) !== [];
    }

    public function allowsRoute(?string $name): bool
    {
        $needed = StoreSections::forRoute($name);

        if ($needed === StoreSections::OWNER_ONLY) {
            return $this->isOwner();
        }

        if ($needed === StoreSections::ANYONE) {
            return $this->resolve()['member'];
        }

        return $this->can(...$needed);
    }

    /**
     * Where this user lands in the store: the first page they open, in
     * sidebar order; a member with none lands on their own products.
     */
    public function home(): string
    {
        foreach (self::LANDINGS as $section => $route) {
            if ($this->can($section)) {
                return $route;
            }
        }

        return $this->isOwner() ? 'store.settings.integration' : 'store.my-products.index';
    }

    /** The page each section opens on, in sidebar order. */
    private const LANDINGS = [
        'dashboard' => 'store.dashboard',
        'performance' => 'store.performance',
        'catalog' => 'store.catalog',
        'stagnant' => 'store.stagnant.index',
        'evaluations' => 'store.evaluations.index',
        'studio' => 'store.studio.index',
        'library' => 'store.library.index',
        'balance' => 'store.balance',
        'market' => 'store.market',
        'competitors' => 'store.competitors.index',
        'calendar' => 'store.calendar.index',
        'team' => 'store.team.index',
        'team_report' => 'store.team.report',
        'reports' => 'store.reports.index',
        'activity' => 'store.activity.index',
        'settings' => 'store.settings',
    ];

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
