<?php

namespace App\Actions\Stores;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates a store owned by the given user, with platform default settings.
 * Used by self-registration and by the admin's "new store" form.
 */
class CreateStore
{
    /**
     * @param  array{name: string, domain?: string|null, category?: string|null, platform?: string|null}  $data
     */
    public function handle(User $owner, array $data): Store
    {
        $store = Store::query()->create([
            'owner_id' => $owner->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'domain' => $data['domain'] ?? null,
            'category' => $data['category'] ?? null,
            'platform' => $data['platform'] ?? 'zid',
            'sync_frequency' => config('mojo.defaults.sync_frequency'),
            'settings' => [],
            'brand_guide' => [],
        ]);

        $store->members()->attach($owner->id, ['role' => 'owner']);

        return $store;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;

        while (Store::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
