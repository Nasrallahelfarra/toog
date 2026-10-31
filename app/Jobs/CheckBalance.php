<?php

namespace App\Jobs;

use App\Models\Store;
use App\Notifications\PlatformNotification;
use App\Services\Catalog\BalanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Notifies store members when a catalog imbalance appears (FR-5.5).
 * Only alerts that were not raised last time are sent, so a lasting
 * imbalance is reported once rather than every day.
 */
class CheckBalance implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $storeId) {}

    public function handle(BalanceService $balance): void
    {
        $store = Store::query()->find($this->storeId);

        if (! $store?->isActive()) {
            return;
        }

        $alerts = collect($balance->analyze($store)['alerts']);
        $key = "balance_alerts:{$store->id}";
        $previous = Cache::get($key, []);
        $signature = fn (array $a) => $a['type'].'|'.($a['category'] ?? '*');

        foreach ($alerts->reject(fn ($a) => in_array($signature($a), $previous, true)) as $alert) {
            [$titleAr, $titleEn, $bodyAr, $bodyEn] = $this->text($alert);

            foreach ($store->members as $member) {
                $member->notify(new PlatformNotification(
                    kind: 'balance',
                    titleAr: $titleAr,
                    titleEn: $titleEn,
                    bodyAr: $bodyAr,
                    bodyEn: $bodyEn,
                    url: '/store/balance',
                    storeId: $store->id,
                    level: $alert['severity'] === 'danger' ? 'danger' : 'warning',
                    email: true,
                ));
            }
        }

        Cache::forever($key, $alerts->map($signature)->values()->all());
    }

    /**
     * @param  array<string, mixed>  $alert
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function text(array $alert): array
    {
        $c = $alert['category'];
        $p = $alert['params'];

        return match ($alert['type']) {
            'repetitive' => [
                "تنبيه توازن: فئة {$c} متكررة", "Balance alert: {$c} is repetitive",
                "مؤشر التنوع {$p['diversity']}/100 — أكثر شكل يتكرر في {$p['share']}% من المنتجات.",
                "Diversity index {$p['diversity']}/100 — the most repeated look covers {$p['share']}% of products.",
            ],
            'overloaded' => [
                "تنبيه توازن: منتجات راكدة كثيرة في {$c}", "Balance alert: too many slow movers in {$c}",
                "{$p['stagnant']} من {$p['products']} منتجًا راكدة. خفّف وتيرة الطرح في هذه الفئة.",
                "{$p['stagnant']} of {$p['products']} products are slow movers. Slow down launches in this category.",
            ],
            'low_innovation' => [
                'تنبيه توازن: نسبة المنتجات المبتكرة منخفضة', 'Balance alert: too few new ideas',
                "المبتكرة {$p['novel']}% فقط مقابل المستهدف {$p['target']}%.",
                "Only {$p['novel']}% innovative products versus a {$p['target']}% target.",
            ],
            default => [
                'تنبيه توازن: نسبة تجريب مرتفعة', 'Balance alert: too experimental',
                "المبتكرة {$p['novel']}% مقابل المستهدف {$p['target']}% — المخاطرة أعلى من المعتاد.",
                "{$p['novel']}% innovative products versus a {$p['target']}% target — higher risk than usual.",
            ],
        };
    }
}
