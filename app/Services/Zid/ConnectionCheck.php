<?php

namespace App\Services\Zid;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Store;
use App\Models\SyncRun;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Health check for a store's Zid connection, run after connecting and on
 * demand from the integration settings. Each step says whether it passed,
 * and the UI shows a fix for each failure:
 *
 *   app → authorized → token → profile → products → orders → order_lines
 *   → imported → analysis
 *
 * Steps that cannot run because an earlier one failed are "skip".
 */
class ConnectionCheck
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const SKIP = 'skip';

    /** How many products / orders to read as a sample. */
    private const SAMPLE = 5;

    /**
     * Run every step and record the result as a SyncRun ("connection_check").
     *
     * @return array{ok: bool, checked_at: string, counts: array<string, int>, checks: list<array{key: string, status: string, params: array<string, mixed>}>}
     */
    public function run(Store $store): array
    {
        $checks = [];
        $add = function (string $key, string $status, array $params = []) use (&$checks) {
            $checks[] = ['key' => $key, 'status' => $status, 'params' => $params];

            return $status;
        };

        $apiReady = true;

        if (! ZidClient::configured()) {
            $add('app', self::FAIL);
            $apiReady = false;
        } else {
            $add('app', self::PASS);
        }

        if (blank($store->zid_access_token) || blank($store->zid_authorization)) {
            $add('authorized', self::FAIL);
            $apiReady = false;
        } else {
            $add('authorized', self::PASS);
        }

        // Token lifetime (Zid issues 3-year tokens; refresh tokens last 1 year).
        $expires = $store->zid_token_expires_at;
        match (true) {
            ! $apiReady => $add('token', self::SKIP),
            $expires === null => $add('token', self::WARN, ['reason' => 'unknown']),
            $expires->isPast() => $add('token', self::FAIL, ['expires_at' => $expires->toDateString()]),
            $expires->isBefore(now()->addDays(30)) => $add('token', self::WARN, ['expires_at' => $expires->toDateString(), 'reason' => 'soon']),
            default => $add('token', self::PASS, ['expires_at' => $expires->toDateString()]),
        };

        $client = new ZidClient($store, timeout: 20);
        $orders = [];

        // 1. The token is accepted and identifies the store.
        if ($apiReady) {
            try {
                $profile = $client->profile();
                $add('profile', self::PASS, [
                    'store_name' => data_get($profile, 'user.store.title') ?? data_get($profile, 'store.title') ?? data_get($profile, 'user.store.name'),
                    'store_id' => data_get($profile, 'user.store.id') ?? data_get($profile, 'store.id') ?? $store->platform_store_id,
                ]);
            } catch (Throwable $e) {
                $add('profile', self::FAIL, $this->error($e));
                $apiReady = false;
            }
        } else {
            $add('profile', self::SKIP);
        }

        // 2. Products can be read (needs the products.read scope).
        if ($apiReady) {
            try {
                $sample = $this->take($client->products());
                $add('products', $sample ? self::PASS : self::WARN, ['sample' => count($sample), 'reason' => $sample ? null : 'none']);
            } catch (Throwable $e) {
                $add('products', self::FAIL, $this->error($e));
            }
        } else {
            $add('products', self::SKIP);
        }

        // 3. Orders can be read (needs the orders.read scope)…
        if ($apiReady) {
            try {
                $orders = $this->take($client->orders(now()->subMonths(12)->startOfMonth()));
                $add('orders', $orders ? self::PASS : self::WARN, ['sample' => count($orders), 'reason' => $orders ? null : 'none']);
            } catch (Throwable $e) {
                $add('orders', self::FAIL, $this->error($e));
            }
        } else {
            $add('orders', self::SKIP);
        }

        // …and include their products, which the best-seller analysis needs.
        if ($orders) {
            $withLines = collect($orders)->filter(fn ($o) => ! empty($o['products'] ?? $o['items'] ?? []))->count();
            $add('order_lines', $withLines > 0 ? self::PASS : self::FAIL, ['with_lines' => $withLines, 'sample' => count($orders)]);
        } else {
            $add('order_lines', self::SKIP);
        }

        // 4. Data actually arrived in طوق.
        $products = Product::query()->where('store_id', $store->id)->count();
        $salesRows = ProductSale::query()->where('store_id', $store->id)->count();
        $lastSync = SyncRun::query()->where('store_id', $store->id)->where('type', 'zid_sync')->latest('id')->first();

        match (true) {
            $lastSync?->status === 'failed' => $add('imported', self::FAIL, ['error' => $lastSync->error, 'products' => $products, 'sales_rows' => $salesRows]),
            $lastSync?->status === 'running' => $add('imported', self::WARN, ['reason' => 'running', 'products' => $products, 'sales_rows' => $salesRows]),
            $products > 0 && $salesRows > 0 => $add('imported', self::PASS, ['products' => $products, 'sales_rows' => $salesRows, 'synced_at' => $store->last_synced_at?->toIso8601String()]),
            $products > 0 => $add('imported', self::WARN, ['reason' => 'no_sales', 'products' => $products, 'sales_rows' => 0]),
            default => $add('imported', $lastSync ? self::WARN : self::SKIP, ['reason' => 'empty', 'products' => 0, 'sales_rows' => 0]),
        };

        // 5. Products were classified (best sellers / average / slow movers).
        $classes = Product::query()->where('store_id', $store->id)->whereNotNull('performance')
            ->selectRaw('performance, count(*) as c')->groupBy('performance')->pluck('c', 'performance');
        $add('analysis', $classes->isNotEmpty() ? self::PASS : ($products ? self::WARN : self::SKIP), [
            'winner' => (int) ($classes['winner'] ?? 0),
            'average' => (int) ($classes['average'] ?? 0),
            'stagnant' => (int) ($classes['stagnant'] ?? 0),
        ]);

        $failed = collect($checks)->where('status', self::FAIL)->count();

        $result = [
            'ok' => $failed === 0 && collect($checks)->where('status', self::PASS)->isNotEmpty(),
            'checked_at' => now()->toIso8601String(),
            'counts' => [
                'pass' => collect($checks)->where('status', self::PASS)->count(),
                'warn' => collect($checks)->where('status', self::WARN)->count(),
                'fail' => $failed,
            ],
            'checks' => $checks,
        ];

        SyncRun::query()->create([
            'store_id' => $store->id,
            'type' => 'connection_check',
            'status' => $result['ok'] ? 'completed' : 'failed',
            'stats' => $result,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return $result;
    }

    /**
     * The latest check result for a store, if any.
     *
     * @return array<string, mixed>|null
     */
    public static function latest(Store $store): ?array
    {
        return SyncRun::query()->where('store_id', $store->id)->where('type', 'connection_check')->latest('id')->value('stats');
    }

    /**
     * @param  iterable<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function take(iterable $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = $item;

            if (count($out) >= self::SAMPLE) {
                break;
            }
        }

        return $out;
    }

    /**
     * HTTP status and message, so the UI can say "permission missing" (403),
     * "token rejected" (401) or "Zid unreachable".
     *
     * @return array{http: int|null, message: string}
     */
    private function error(Throwable $e): array
    {
        $previous = $e instanceof RequestException ? $e : $e->getPrevious();
        $status = $previous instanceof RequestException ? $previous->response->status() : null;

        return ['http' => $status, 'message' => mb_substr($e->getMessage(), 0, 300)];
    }
}
