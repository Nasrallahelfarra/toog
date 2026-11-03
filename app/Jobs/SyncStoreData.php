<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use App\Notifications\PlatformNotification;
use App\Services\Analytics\PerformanceClassifier;
use App\Services\Zid\ZidApiException;
use App\Services\Zid\ZidClient;
use App\Services\Zid\ZidMapper;
use App\Services\Zid\ZidRateLimited;
use App\Services\Zid\ZidSyncStaging;
use App\Services\Zid\ZidSyncState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
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

    public int $step = 0;

    // The run ID is serialized before dispatch, so failed() can find it even
    // though Laravel invokes that hook on a fresh job instance.
    public function __construct(public Store $store, public ?int $userId = null, ?int $runId = null, int $step = 0)
    {
        $this->runId = $runId;
        $this->step = $step;
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
        $run = $state->claim($this->runId, $this->store, $this->step);
        if (! $run) {
            $this->chain([]);

            return;
        }

        $lock = Cache::lock(self::lockKey($this->store), 660);
        if (! $lock->get()) {
            $state->fail($run->id, 'catalog_busy');
            throw new RuntimeException('The store catalog is being updated by another task.');
        }
        $staging = app(ZidSyncStaging::class);

        try {
            $this->store->refresh();
            $this->assertIdentity($run, $this->store);
            if (($run->stats['cursor_phase'] ?? 'products') !== 'saving') {
                if ($this->step === 0) {
                    $state->progress($run, 'connecting');
                }
                $client = new ZidClient($this->store);
                $client->refreshIfNeeded();
                $this->fetchBatch($run, $client, $mapper, $state, $staging);
                $this->continueImport($run, $state, $run->stats['cursor_phase'], 3);

                return;
            }

            $state->progress($run, 'saving');
            $stats = DB::transaction(function () use ($staging, $classifier, $run): array {
                $store = Store::query()->lockForUpdate()->findOrFail($this->store->id);
                $this->assertIdentity($run, $store);
                if ($run->fresh()?->status !== 'running') {
                    throw new RuntimeException('The Zid import is no longer active.');
                }
                $stats = $this->import($run, $staging->rows($run->id, 'products'), $staging->rows($run->id, 'orders'));
                $store->forceFill(['last_synced_at' => now(), 'connection_status' => 'connected'])->save();
                $run->update([
                    'status' => 'completed', 'finished_at' => now(),
                    'stats' => [...$run->stats, ...$stats, 'phase' => 'completed', 'orders_stored' => true, 'heartbeat_at' => now()->toIso8601String()],
                ]);
                // Make the completed sales window available to classification
                // inside this transaction; an analysis failure rolls it all back.
                $classifier->classify($store);
                $staging->clear($run->id);

                return $stats;
            });
        } catch (ZidRateLimited $e) {
            $retries = ($run->stats['rate_retries'] ?? 0) + 1;
            if ($retries > 10) {
                $state->fail($run->id, 'rate_limited');
                throw $e;
            }
            $state->progress($run, 'waiting', ['rate_retries' => $retries, 'retry_reason' => 'rate_limit', 'retry_at' => now()->addSeconds($e->retryAfter)->toIso8601String()]);
            $this->continueImport($run, $state, 'waiting', $e->retryAfter);

            return;
        } catch (Throwable $e) {
            $transient = $e instanceof ConnectionException || ($e instanceof ZidApiException && $e->httpStatus >= 500);
            $retries = ($run->stats['transient_retries'] ?? 0) + 1;
            if ($transient && $retries <= 5) {
                $delay = min(600, 30 * (2 ** ($retries - 1)));
                $state->progress($run, 'waiting', [
                    'transient_retries' => $retries, 'retry_reason' => 'api_unavailable',
                    'retry_at' => now()->addSeconds($delay)->toIso8601String(),
                ]);
                $this->continueImport($run, $state, 'waiting', $delay);

                return;
            }
            $state->fail($run->id, match (true) {
                $transient => 'api_unavailable',
                $e instanceof ZidApiException && in_array($e->httpStatus, [401, 403], true) => 'authorization_failed',
                $e instanceof ZidApiException => 'zid_rejected',
                default => 'import_failed',
            }, detail: $e instanceof ZidApiException ? $e->detail : null);
            throw $e; // Record a failed queue job and stop the post-sync chain.
        } finally {
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
            }, $this->step);
        }
    }

    private function assertIdentity(SyncRun $run, Store $store): void
    {
        if ((string) $store->platform_store_id !== ($run->stats['zid_store_id'] ?? null) || blank($store->zid_access_token) || blank($store->zid_authorization)) {
            throw new RuntimeException('The Zid connection changed. Start a new import.');
        }
    }

    private function continueImport(SyncRun $run, ZidSyncState $state, string $phase, int $delay): void
    {
        $state->progress($run, $phase, ['step' => $this->step + 1, 'step_claimed' => false]);
        // Carry the optional connection check behind every continuation.
        // Space the batches below Zid's per-store request limit; a 429 uses
        // the exact server-provided delay instead of retrying immediately.
        $next = new self($this->store, $this->userId, $run->id, $this->step + 1);
        $this->prependToChain($next->delay($delay));
    }

    private function fetchBatch(SyncRun $run, ZidClient $client, ZidMapper $mapper, ZidSyncState $state, ZidSyncStaging $staging): void
    {
        // A "changes" run reads only orders updated since the last import,
        // and products newest-updated first until it reaches unchanged ones.
        $changes = ($run->stats['scope'] ?? 'full') === 'changes';
        // Two pages per job bound API wait time, regardless of catalog size.
        // Committed normalized pages survive handoff to another worker.
        for ($batch = 0; $batch < 2; $batch++) {
            $phase = $run->stats['cursor_phase'] ?? 'products';
            if ($phase === 'saving') {
                return;
            }
            $page = (int) ($run->stats['next_page'] ?? 1);
            $newestFirst = $changes && ($run->stats['products_newest_first'] ?? true);
            $state->progress($run, $phase);
            try {
                $result = match (true) {
                    $phase === 'products' => $client->productPage($page, $newestFirst),
                    $changes => $client->orderPage(Carbon::parse($run->stats['changed_since']), $page, 'updated_at'),
                    default => $client->orderPage(Carbon::parse($run->stats['orders_from'] ?? $run->stats['orders_since'] ?? now()->startOfMonth()->subMonths(11)), $page),
                };
            } catch (ZidApiException $e) {
                if ($phase === 'products' && $newestFirst && $e->httpStatus === 422 && $page === 1) {
                    // The ordering is not accepted: read every product instead.
                    $state->progress($run, 'products', ['products_newest_first' => false]);

                    continue;
                }
                // Zid's order search rejects pages past its result window (about
                // 10,000 orders) with a 422. Restart from the newest order read.
                if ($phase === 'orders' && ! $changes && $e->httpStatus === 422 && $page > 1 && $this->shiftOrderWindow($run, $state)) {
                    continue;
                }
                throw $e;
            }
            $rows = [];
            foreach ($result['items'] as $payload) {
                // A canceled order is staged as a removal so a changes run
                // can take it out of the stored orders.
                $row = $phase === 'products' ? $mapper->product($payload) : ($mapper->order($payload) ?? $this->removal($payload));
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            $cursor = [];
            if ($phase === 'products' && $newestFirst) {
                [$inOrder, $oldest] = $this->updatedOrder($result['items'], $run->stats['products_oldest_at'] ?? null, $run->stats['products_in_order'] ?? true);
                $cursor = ['products_in_order' => $inOrder, 'products_oldest_at' => $oldest];
                // Every later page was updated before the last import started.
                if ($inOrder && $oldest !== null && Carbon::parse($oldest)->lt(Carbon::parse($run->stats['changed_since']))) {
                    $result['has_next'] = false;
                }
            }

            DB::transaction(function () use ($run, $phase, $page, $result, $rows, $cursor, $state, $staging): void {
                $locked = SyncRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($locked->status !== 'running' || ($locked->stats['step'] ?? 0) !== $this->step) {
                    throw new RuntimeException('The Zid import is no longer active.');
                }
                $countKey = $phase === 'products' ? 'products_read' : 'orders_read';
                $pageKey = $phase === 'products' ? 'product_pages' : 'order_pages';
                // Staged pages are numbered in reading order: the API page
                // restarts at 1 in each order window. Overlapping windows
                // may return the same orders, so a repeat is per window.
                $sequence = ($run->stats[$pageKey] ?? 0) + 1;
                $fingerprint = $phase === 'orders' ? hash('sha256', ($run->stats['order_windows'] ?? 0).':'.$result['fingerprint']) : $result['fingerprint'];
                $staging->save($run->id, $phase, $sequence, $fingerprint, $rows);
                $nextPhase = $result['has_next'] ? $phase : ($phase === 'products' ? 'orders' : 'saving');
                $state->progress($run, $nextPhase, [
                    $countKey => ($run->stats[$countKey] ?? 0) + count($result['items']), $pageKey => $sequence,
                    'cursor_phase' => $nextPhase, 'next_page' => $result['has_next'] ? $page + 1 : 1,
                    'rate_retries' => 0, 'transient_retries' => 0, 'retry_at' => null, 'retry_reason' => null,
                    ...$cursor,
                    ...($phase === 'orders' ? ['orders_last_at' => $this->latestOrderTime($result['items'], $run->stats['orders_last_at'] ?? null)] : []),
                ]);
            });
        }
    }

    /**
     * Whether products keep arriving newest-updated first, and the oldest
     * update time read so far. A missing time disables the early stop.
     *
     * @param  list<array<string, mixed>>  $products
     * @return array{bool, string|null}
     */
    private function updatedOrder(array $products, ?string $oldest, bool $inOrder): array
    {
        $previous = $oldest ? Carbon::parse($oldest) : null;
        foreach ($products as $product) {
            $time = $this->time($product['updated_at'] ?? null);
            if (! $time || ($previous && $time->gt($previous))) {
                return [false, $previous?->toIso8601String()];
            }
            $previous = $time;
        }

        return [$inOrder, $previous?->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{id: string, removed: true}|null
     */
    private function removal(array $order): ?array
    {
        return isset($order['id']) && is_scalar($order['id']) ? ['id' => (string) $order['id'], 'removed' => true] : null;
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function shiftOrderWindow(SyncRun $run, ZidSyncState $state): bool
    {
        $last = $run->stats['orders_last_at'] ?? null;
        $from = Carbon::parse($run->stats['orders_from'] ?? $run->stats['orders_since']);
        // A day of overlap absorbs any time zone gap between Zid's order
        // timestamps and the filter; the save step counts each order once.
        $next = $last ? Carbon::parse($last)->subDay() : null;
        if (! $next?->gt($from)) {
            return false; // No progress since the last window: stop rather than loop.
        }
        $state->progress($run, 'orders', [
            'orders_from' => $next->toIso8601String(), 'next_page' => 1,
            'order_windows' => ($run->stats['order_windows'] ?? 0) + 1,
        ]);

        return true;
    }

    /** @param list<array<string, mixed>> $orders */
    private function latestOrderTime(array $orders, ?string $current): ?string
    {
        $latest = $current ? Carbon::parse($current) : null;
        foreach ($orders as $order) {
            $time = $this->time($order['created_at'] ?? null);
            if ($time && (! $latest || $time->gt($latest))) {
                $latest = $time;
            }
        }

        return $latest?->toIso8601String();
    }

    /**
     * Saves products, applies the read orders to the stored orders, then
     * rebuilds only the product × month sales rows those orders touch.
     *
     * @param  iterable<int, array<string, mixed>>  $products
     * @param  iterable<int, array<string, mixed>>  $orderRows
     * @return array{products: int, orders: int, sales_rows: int, returns: int}
     */
    private function import(SyncRun $run, iterable $products, iterable $orderRows): array
    {
        $productCount = 0;
        foreach ($products as $row) {
            $product = Product::query()->firstOrNew(['store_id' => $this->store->id, 'external_id' => $row['external_id']]);
            $product->fill(array_filter($row, fn ($v) => $v !== null));
            $product->launched_at ??= $row['launched_at'];
            $product->save();
            $productCount++;
        }
        // A changes run reads few products, so order lines resolve against the whole catalog.
        $ids = Product::query()->where('store_id', $this->store->id)->whereNotNull('external_id')->pluck('id', 'external_id')->all();

        $full = ($run->stats['scope'] ?? 'full') === 'full';
        $since = Carbon::parse($run->stats['orders_since'])->startOfMonth()->toDateString();
        $stored = fn () => DB::table('zid_orders')->where('store_id', $this->store->id);
        $touched = [];
        $touch = function (string $month, array $lines) use (&$touched, $ids, $since): void {
            // Months before the 12-month window keep the totals they have.
            foreach ($month >= $since ? $lines : [] as $line) {
                if ($productId = $ids[$line['external_id']] ?? null) {
                    $touched[$productId][$month] = true;
                }
            }
        };
        if ($full) {
            // A full read replaces every stored order; their buckets are rebuilt or cleared.
            foreach ($stored()->cursor() as $old) {
                $touch($old->month, json_decode($old->lines, true, flags: JSON_THROW_ON_ERROR));
            }
            $stored()->delete();
        }

        $seen = [];
        $returns = 0;
        $unnamed = 0;
        foreach (LazyCollection::make(fn () => yield from $orderRows)->chunk(500) as $chunk) {
            // Overlapping reads return an order twice; the later read is newer.
            // Pages staged before orders carried their id still count once each.
            $latest = $chunk->keyBy(function (array $order) use ($run, &$unnamed): string {
                return $order['id'] ?? 'run-'.$run->id.'-'.++$unnamed;
            })->all();
            foreach ($full ? [] : $stored()->whereIn('external_id', array_keys($latest))->get() as $old) {
                $touch($old->month, json_decode($old->lines, true, flags: JSON_THROW_ON_ERROR));
            }
            $live = array_filter($latest, fn ($order) => ! ($order['removed'] ?? false));
            $stored()->whereIn('external_id', array_keys(array_diff_key($latest, $live)))->delete();
            DB::table('zid_orders')->upsert(array_map(fn ($id, $order) => [
                'store_id' => $this->store->id, 'external_id' => (string) $id, 'month' => $order['month'],
                'returned' => $order['returned'], 'lines' => json_encode($order['lines'], JSON_THROW_ON_ERROR),
            ], array_keys($live), $live), ['store_id', 'external_id'], ['month', 'returned', 'lines']);
            foreach ($live as $order) {
                $touch($order['month'], $order['lines']);
                $returns += (int) $order['returned'];
            }
            $seen += array_fill_keys(array_keys($latest), true);
        }
        $stored()->where('month', '<', $since)->delete();

        $buckets = [];
        foreach ($stored()->cursor() as $order) {
            foreach (json_decode($order->lines, true, flags: JSON_THROW_ON_ERROR) as $line) {
                $productId = $ids[$line['external_id']] ?? null;
                if (! $productId || ! isset($touched[$productId][$order->month])) {
                    continue;
                }
                $bucket = $buckets[$productId][$order->month] ?? ['orders' => 0, 'units' => 0, 'revenue' => 0.0, 'returns' => 0];
                $bucket['orders']++;
                $bucket['units'] += $line['units'];
                $bucket['revenue'] += $order->returned ? 0 : $line['revenue'];
                $bucket['returns'] += $order->returned ? $line['units'] : 0;
                $buckets[$productId][$order->month] = $bucket;
            }
        }

        $rows = 0;
        foreach ($touched as $productId => $months) {
            foreach (array_keys($months) as $period) {
                // Zid's API has no page views / add-to-cart; keep any values
                // that came from an analytics file import. A bucket whose
                // orders were all canceled drops to zero.
                // A date object binds as "Y-m-d H:i:s", matching stored rows.
                ProductSale::query()->updateOrCreate(
                    ['product_id' => $productId, 'period' => Carbon::parse($period)->startOfDay()],
                    ['store_id' => $this->store->id, ...($buckets[$productId][$period] ?? ['orders' => 0, 'units' => 0, 'revenue' => 0, 'returns' => 0])],
                );
                $rows++;
            }
        }

        return [
            'products' => $productCount, 'orders' => $full ? $stored()->count() : count($seen),
            'sales_rows' => $rows, 'returns' => $returns,
        ];
    }

    private function notify(string $level, string $titleAr, string $titleEn, string $bodyAr, string $bodyEn, bool $email = false): void
    {
        $notification = new PlatformNotification('sync', $titleAr, $titleEn, $bodyAr, $bodyEn, '/store/settings/integration', $this->store->id, $level, $email);

        $this->store->members()->get()->each->notify($notification);
    }
}
