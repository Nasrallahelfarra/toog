<?php

use App\Models\Store;
use App\Services\Zid\ZidSyncState;
use Illuminate\Support\Facades\Schedule;

/*
| Periodic store data refresh (FR-1.6): connected, active stores only,
| following each store's daily / weekly setting.
*/

$syncStores = function (string $frequency): void {
    Store::query()
        ->where('status', Store::STATUS_ACTIVE)
        ->where('connection_status', 'connected')
        ->whereNotNull('zid_access_token')
        ->where('sync_frequency', $frequency)
        ->each(fn (Store $store) => app(ZidSyncState::class)->continueOrEnqueue($store));
};

Schedule::call(fn () => $syncStores('daily'))
    ->dailyAt('03:00')
    ->name('zid-sync-daily')
    ->withoutOverlapping();

Schedule::call(fn () => $syncStores('weekly'))
    ->weeklyOn(0, '03:30')
    ->name('zid-sync-weekly')
    ->withoutOverlapping();
