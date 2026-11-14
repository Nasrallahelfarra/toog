<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Zid\ZidClient;
use App\Services\Zid\ZidStoreName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs after a Zid sync: picks up a store renamed in Zid. A failed read
 * leaves the name as it is.
 */
class RefreshZidStoreName implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public int $storeId) {}

    public function handle(): void
    {
        $store = Store::query()->find($this->storeId);
        if (! $store || ! ZidStoreName::follows($store)) {
            return;
        }

        try {
            ZidStoreName::sync($store, (new ZidClient($store, timeout: 20))->storeProfile());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
