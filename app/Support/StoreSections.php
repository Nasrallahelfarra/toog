<?php

namespace App\Support;

/**
 * The store dashboard's pages as permission units. The owner grants a staff
 * member any subset of ALL; every store route maps to the sections that
 * open it. A route that is not mapped is closed to staff.
 */
class StoreSections
{
    /**
     * Grantable sections, grouped as the sidebar groups them.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'store' => ['dashboard', 'performance', 'catalog', 'stagnant'],
        'decisions' => ['evaluations', 'studio', 'library', 'balance'],
        'intelligence' => ['market', 'competitors', 'calendar'],
        'management' => ['reports', 'activity', 'settings'],
    ];

    /** Open to every member of the store, whatever their sections. */
    public const ANYONE = ['*'];

    /** Open to the owner (and the platform admin) only. */
    public const OWNER_ONLY = [];

    /**
     * Route name (without the "store." prefix) → sections, any of which
     * opens it. Exact names win over prefixes.
     *
     * @var array<string, list<string>>
     */
    private const ROUTES = [
        'dashboard' => ['dashboard'],
        'catalog' => ['catalog'],
        // Deletes catalog rows.
        'catalog.clear-demo' => self::OWNER_ONLY,
        // Reached from several pages; ProductController narrows it further.
        'products.show' => self::ANYONE,

        'performance' => ['performance'],
        'performance.extract' => ['performance'],
        'performance.reclassify' => ['performance', 'stagnant'],

        'settings' => ['settings'],

        'designs.download' => ['studio', 'library', 'evaluations'],
        'market' => ['market'],
        'balance' => ['balance'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const PREFIXES = [
        // The Zid connection is the owner's alone and cannot be granted:
        // linking, syncing and importing all replace the store's data.
        // That covers the connection page, the file import, the revenue
        // audit (every revenue figure, straight from Zid) and the sync.
        'settings.integration' => self::OWNER_ONLY,
        'settings.import' => self::OWNER_ONLY,
        'settings.template' => self::OWNER_ONLY,
        'settings.revenue-audit' => self::OWNER_ONLY,
        'integrations.' => self::OWNER_ONLY,
        'stagnant.' => ['stagnant'],
        'settings.general' => ['settings'],
        'settings.evaluation' => ['settings'],
        'settings.brand' => ['settings'],
        'evaluations.' => ['evaluations'],
        'improvements.' => ['evaluations'],
        'batches.' => ['evaluations'],
        'studio.' => ['studio'],
        'designer.' => ['studio'],
        'library.' => ['library'],
        'designs.' => ['studio', 'library'],
        'market.' => ['market'],
        'competitors.' => ['competitors'],
        'calendar.' => ['calendar'],
        'reports.' => ['reports'],
        'activity.' => ['activity'],
        // A member's own inbox and their own products are always theirs.
        'notifications.' => self::ANYONE,
        'my-products.' => self::ANYONE,
        'team.' => self::OWNER_ONLY,
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Keep only real section keys, in the canonical order.
     *
     * @param  array<int, mixed>  $sections
     * @return list<string>
     */
    public static function clean(array $sections): array
    {
        return array_values(array_intersect(self::all(), $sections));
    }

    /**
     * The pages a member holds, read from the store_user row.
     *
     * @return list<string>
     */
    public static function stored(mixed $permissions): array
    {
        return self::clean((array) json_decode((string) ($permissions ?? '[]'), true));
    }

    /**
     * Sections that open a route; OWNER_ONLY when the route is not mapped.
     *
     * @return list<string>
     */
    public static function forRoute(?string $name): array
    {
        if ($name === null || ! str_starts_with($name, 'store.')) {
            return self::OWNER_ONLY;
        }

        $name = substr($name, 6);

        if (array_key_exists($name, self::ROUTES)) {
            return self::ROUTES[$name];
        }

        $match = self::OWNER_ONLY;
        $length = 0;

        foreach (self::PREFIXES as $prefix => $sections) {
            if (str_starts_with($name, $prefix) && strlen($prefix) > $length) {
                $match = $sections;
                $length = strlen($prefix);
            }
        }

        return $match;
    }
}
