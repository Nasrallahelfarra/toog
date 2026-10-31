<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Notifications\PlatformNotification;
use App\Services\Analytics\PerformanceClassifier;
use App\Services\Zid\ZidClient;
use App\Services\Zid\ZidMapper;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls products and the last 12 months of orders from Zid, rebuilds the
 * monthly sales table and re-classifies product performance (FR-1.1,
 * FR-1.2, FR-1.6).
 */
class SyncStoreData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Store $store, public ?int $userId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->store->id;
    }

    public function handle(ZidMapper $mapper, PerformanceClassifier $classifier): void
    {
        $run = SyncRun::query()->create([
            'store_id' => $this->store->id,
            'type' => 'zid_sync',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $client = new ZidClient($this->store);
            $client->refreshIfNeeded();

            $stats = DB::transaction(fn () => $this->import($client, $mapper));

            $classifier->classify($this->store);

            $this->store->forceFill(['last_synced_at' => now(), 'connection_status' => 'connected'])->save();
            $run->update(['status' => 'completed', 'stats' => $stats, 'finished_at' => now()]);

            $this->notify(
                'success',
                'اكتملت مزامنة بيانات زد',
                'Zid data sync complete',
                "تم تحديث {$stats['products']} منتجًا و {$stats['orders']} طلبًا.",
                "{$stats['products']} products and {$stats['orders']} orders updated.",
            );
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            $this->store->forceFill(['connection_status' => 'error'])->save();

            $this->notify(
                'danger',
                'فشلت مزامنة بيانات زد',
                'Zid data sync failed',
                'تعذّر جلب البيانات من زد. تحقق من الربط في إعدادات التكامل.',
                'Could not fetch data from Zid. Check the connection in integration settings.',
                email: true,
            );
        }
    }

    /**
     * @return array{products: int, orders: int, sales_rows: int, returns: int}
     */
    private function import(ZidClient $client, ZidMapper $mapper): array
    {
        $ids = [];
        $productCount = 0;

        foreach ($client->products() as $payload) {
            $row = $mapper->product($payload);

            if (! $row) {
                continue;
            }

            $product = Product::query()->firstOrNew(['store_id' => $this->store->id, 'external_id' => $row['external_id']]);
            $product->fill(array_filter($row, fn ($v) => $v !== null));
            $product->launched_at ??= $row['launched_at'];
            $product->save();

            $ids[$row['external_id']] = $product->id;
            $productCount++;
        }

        // Aggregate orders into product × month buckets.
        $buckets = [];
        $orders = 0;
        $returns = 0;

        foreach ($client->orders(now()->startOfMonth()->subMonths(11)) as $payload) {
            $order = $mapper->order($payload);

            if (! $order) {
                continue;
            }

            $orders++;
            $returns += (int) $order['returned'];

            foreach ($order['lines'] as $line) {
                $productId = $ids[$line['external_id']] ?? null;

                if (! $productId) {
                    continue;
                }

                $bucket = &$buckets[$productId][$order['month']];
                $bucket ??= ['orders' => 0, 'units' => 0, 'revenue' => 0.0, 'returns' => 0];
                $bucket['orders']++;
                $bucket['units'] += $line['units'];
                $bucket['revenue'] += $order['returned'] ? 0 : $line['revenue'];
                $bucket['returns'] += $order['returned'] ? $line['units'] : 0;
                unset($bucket);
            }
        }

        $rows = 0;
        foreach ($buckets as $productId => $months) {
            foreach ($months as $period => $values) {
                // Zid's API has no page views / add-to-cart; keep any values
                // that came from an analytics file import.
                // A date object binds as "Y-m-d H:i:s", matching stored rows.
                ProductSale::query()->updateOrCreate(
                    ['product_id' => $productId, 'period' => Carbon::parse($period)->startOfDay()],
                    ['store_id' => $this->store->id, ...$values],
                );
                $rows++;
            }
        }

        return ['products' => $productCount, 'orders' => $orders, 'sales_rows' => $rows, 'returns' => $returns];
    }

    private function notify(string $level, string $titleAr, string $titleEn, string $bodyAr, string $bodyEn, bool $email = false): void
    {
        $notification = new PlatformNotification('sync', $titleAr, $titleEn, $bodyAr, $bodyEn, '/store/settings/integration', $this->store->id, $level, $email);

        $this->store->members()->get()->each->notify($notification);
    }
}
