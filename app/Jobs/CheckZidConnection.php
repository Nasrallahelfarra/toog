<?php

namespace App\Jobs;

use App\Models\Store;
use App\Notifications\PlatformNotification;
use App\Services\Zid\ConnectionCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the Zid connection check in the background — chained after the
 * first sync when a store connects — and tells the store's members the
 * outcome.
 */
class CheckZidConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $storeId, public bool $notify = true) {}

    public function handle(ConnectionCheck $check): void
    {
        $store = Store::query()->find($this->storeId);

        if (! $store) {
            return;
        }

        $result = $check->run($store);

        if (! $this->notify) {
            return;
        }

        $notification = $result['ok']
            ? new PlatformNotification(
                kind: 'sync',
                titleAr: 'تم التحقق من ربط متجرك على زد',
                titleEn: 'Your Zid connection is verified',
                bodyAr: "اجتاز الربط {$result['counts']['pass']} فحوصات — البيانات وصلت والتحليل جاهز.",
                bodyEn: "The connection passed {$result['counts']['pass']} checks — data arrived and the analysis is ready.",
                url: '/store/settings/integration',
                storeId: $store->id,
                level: 'success',
            )
            : new PlatformNotification(
                kind: 'sync',
                titleAr: 'يحتاج ربط متجرك على زد إلى انتباه',
                titleEn: 'Your Zid connection needs attention',
                bodyAr: "فشل {$result['counts']['fail']} من فحوصات الربط. افتح إعدادات الربط لمعرفة السبب وطريقة الإصلاح.",
                bodyEn: "{$result['counts']['fail']} connection checks failed. Open the integration settings to see why and how to fix it.",
                url: '/store/settings/integration',
                storeId: $store->id,
                level: 'danger',
                email: true,
            );

        foreach ($store->members as $member) {
            $member->notify($notification);
        }
    }
}
