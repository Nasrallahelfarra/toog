<?php

namespace App\Actions\Stores;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently removes a store with everything it owns. Database rows go
 * through the store_id foreign keys (cascade on delete); uploaded and
 * generated files live in per-store folders on the public disk.
 */
class DeleteStore
{
    /** Public-disk folders keyed by store id. */
    private const FOLDERS = ['evaluations', 'logos', 'design-sources', 'designs', 'product-recommendations'];

    public function handle(Store $store): void
    {
        $id = $store->id;

        DB::transaction(function () use ($store): void {
            // Staff accounts exist for this store only, so they go with it.
            $staff = $store->staff()->get();
            // Members are a pivot without a cascading key on every driver.
            $store->members()->detach();
            $staff->filter(fn (User $member) => ! $member->isAdmin() && $member->stores()->doesntExist() && $member->ownedStores()->whereKeyNot($store->id)->doesntExist())
                ->each->delete();
            $store->delete();
        });

        $disk = Storage::disk('public');
        foreach (self::FOLDERS as $folder) {
            $disk->deleteDirectory("{$folder}/{$id}");
        }
    }
}
