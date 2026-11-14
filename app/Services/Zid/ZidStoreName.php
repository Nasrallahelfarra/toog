<?php

namespace App\Services\Zid;

use App\Models\Store;
use App\Support\Activity;

/**
 * A store connected to Zid carries its Zid name. The name is taken from
 * the store profile when the store connects, after every sync and on each
 * connection check, and only from the profile of the same Zid store.
 */
class ZidStoreName
{
    /**
     * @param  array<string, mixed>  $profile  managers/account/store
     * @return bool whether the name changed
     */
    public static function sync(Store $store, array $profile): bool
    {
        $id = data_get($profile, 'store.id');
        if (filled($store->platform_store_id) && (string) $id !== (string) $store->platform_store_id) {
            return false;
        }

        $name = ZidClient::storeName($profile);
        if ($name === null || $name === $store->name) {
            return false;
        }

        $from = $store->name;
        $store->forceFill(['name' => $name])->save();
        Activity::log('integration.store_renamed', $store, ['from' => $from, 'to' => $name], $store->id);

        return true;
    }

    /**
     * A store whose name follows Zid: it holds its own Zid credentials.
     */
    public static function follows(Store $store): bool
    {
        return $store->platform === 'zid' && filled($store->zid_access_token) && filled($store->zid_authorization);
    }
}
