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
use App\Services\Zid\ZidSyncState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Pulls products and the last 12 months of orders from Zid, rebuilds the
 * monthly sales table and re-classifies product performance (FR-1.1,
 * FR-1.2, FR-1.6).
 */
class SyncStoreData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public ?int $runId = null;

    // The run ID is serialized before dispatch, so failed() can find it even
    // though Laravel invokes that hook on a fresh job instance.
    public function __construct(public Store $store, public ?int $userId = null, ?int $runId = null)
    {
        $this->runId = $runId;
    }

    public function uniqueId(): string
    {
        return (string) $this->store->id;
    }

    public static function lockKey(Store $store): string
    {
        return 'zid-catalog-write:'.$store->id;
    }

    public function handle(ZidMapper $mapper, PerformanceClassifier $classifier): void
    {
        $state = app(ZidSyncState::class);
        // Compatibility with jobs queued before progress tracking was added.
        if ($this->runId === null) {
            [$reserved, $created] = $state->reserve($this->store);
            if (! $created) {
                $this->chain([]);

                return;
            }
            $this->runId = $reserved->id;
        }
        $run = $state->claim($this->runId, $this->store);
        if (! $run) {
            $this->chain([]);

            return;
        }

        $lock = Cache::lock(self::lockKey($this->store), 660);
        if (! $lock->get()) {
            $state->fail($run->id, 'catalog_busy');
            throw new RuntimeException('The store catalog is being updated by another task.');
        }
        $products = $orders = null;

        try {
            $this->store->refresh();
            $this->assertIdentity($run, $this->store);
            $state->progress($run, 'connecting');
            $client = new ZidClient($this->store);
            $client->refreshIfNeeded();
            // Keep normalized rows in private temporary streams. Fetching outside
            // the transaction makes page progress visible and avoids holding
            // catalog locks while waiting on the remote API. No customer PII.
            $products = tmpfile();
            $orders = tmpfile();
            if ($products === false || $orders === false) {
                throw new RuntimeException('Could not stage the Zid import.');
            }
            $state->progress($run, 'products');
            foreach ($client->products(function (int $page, int $count) use ($state, $run): void {
                $state->progress($run, 'products', ['products_read' => $count, 'product_pages' => $page]);
            }) as $payload) {
                if ($row = $mapper->product($payload)) {
                    $this->stage($products, $row);
                }
            }
            $state->progress($run, 'orders');
            foreach ($client->orders(now()->startOfMonth()->subMonths(11), function (int $page, int $count) use ($state, $run): void {
                $state->progress($run, 'orders', ['orders_read' => $count, 'order_pages' => $page]);
            }) as $payload) {
                if ($row = $mapper->order($payload)) {
                    $this->stage($orders, $row);
                }
            }

            $state->progress($run, 'saving');
            $stats = DB::transaction(function () use ($products, $orders, $classifier, $run): array {
                $store = Store::query()->lockForUpdate()->findOrFail($this->store->id);
                $this->assertIdentity($run, $store);
                if ($run->fresh()?->status !== 'running') {
                    throw new RuntimeException('The Zid import is no longer active.');
                }
                $stats = $this->import($products, $orders);
                $classifier->classify($store);
                $store->forceFill(['last_synced_at' => now(), 'connection_status' => 'connected'])->save();
                $run->update([
                    'status' => 'completed', 'finished_at' => now(),
                    'stats' => [...$run->stats, ...$stats, 'phase' => 'completed', 'heartbeat_at' => now()->toIso8601String()],
                ]);

                return $stats;
            });
        } catch (Throwable $e) {
            $state->fail($run->id, 'import_failed');
            throw $e; // Record a failed queue job and stop the post-sync chain.
        } finally {
            foreach ([$products, $orders] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $lock->release();
        }

        // Notification failures must not relabel a committed import as failed.
        try {
            $this->notify('success', 'اكتملت مزامنة بيانات زد', 'Zid data sync complete',
                "تم تحديث {$stats['products']} منتجًا و {$stats['orders']} طلبًا.",
                "{$stats['products']} products and {$stats['orders']} orders updated.");
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException && DB::transactionLevel() > 0) {
            // A signal can interrupt the save transaction before its catch block.
            // Roll it back before recording the timeout, so that state survives exit.
            DB::rollBack(0);
        }
        if ($this->runId !== null) {
            app(ZidSyncState::class)->fail($this->runId, match (true) {
                $exception instanceof TimeoutExceededException => 'timed_out',
                $exception instanceof MaxAttemptsExceededException => 'worker_stopped',
                default => 'job_failed',
            });
        }
    }

    private function assertIdentity(SyncRun $run, Store $store): void
    {
        if ((string) $store->platform_store_id !== ($run->stats['zid_store_id'] ?? null) || blank($store->zid_access_token) || blank($store->zid_authorization)) {
            throw new RuntimeException('The Zid connection changed. Start a new import.');
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $row
     */
    private function stage($stream, array $row): void
    {
        $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
        if (fwrite($stream, $line) !== strlen($line)) {
            throw new RuntimeException('Could not write the staged Zid import.');
        }
    }

    /**
     * @param  resource  $stream
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows($stream): \Generator
    {
        rewind($stream);
        while (($line = fgets($stream)) !== false) {
            yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
        if (! feof($stream)) {
            throw new RuntimeException('Could not read the staged Zid import.');
        }
    }

    /**
     * @param  resource  $products
     * @param  resource  $orderRows
     * @return array{products: int, orders: int, sales_rows: int, returns: int}
     */
    private function import($products, $orderRows): array
    {
        $ids = [];
        $productCount = 0;

        foreach ($this->rows($products) as $row) {
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

        foreach ($this->rows($orderRows) as $order) {
            $orders++;
            $returns += (int) $order['returned'];

            foreach ($order['lines'] as $line) {
                $productId = $ids[$line['external_id']] ?? null;

                if (! $productId) {
                    continue;
                }

                $bucket = $buckets[$productId][$order['month']] ?? ['orders' => 0, 'units' => 0, 'revenue' => 0.0, 'returns' => 0];
                $bucket['orders']++;
                $bucket['units'] += $line['units'];
                $bucket['revenue'] += $order['returned'] ? 0 : $line['revenue'];
                $bucket['returns'] += $order['returned'] ? $line['units'] : 0;
                $buckets[$productId][$order['month']] = $bucket;
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
