<?php

namespace App\Services\Zid;

use App\Models\Store;
use App\Models\SyncRun;
use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Durable normalized pages, shared by workers; never stores customer details. */
class ZidSyncStaging
{
    public function hasCheckpoint(SyncRun $run): bool
    {
        $phase = $run->stats['cursor_phase'] ?? null;
        if (! in_array($phase, ['products', 'orders', 'saving'], true)) {
            return false;
        }
        // Reject missing or incomplete old checkpoints instead of skipping
        // their pages and presenting a partial catalog as a complete import.
        foreach (['products' => 'product_pages', 'orders' => 'order_pages'] as $kind => $key) {
            $expected = $run->stats[$key] ?? null;
            if (! is_int($expected) || $expected < 0) {
                return false;
            }
            $summary = DB::table('zid_sync_pages')->where('sync_run_id', $run->id)->where('phase', $kind)
                ->selectRaw('COUNT(*) as pages, MIN(page) as first_page, MAX(page) as last_page')->first();
            if ((int) $summary->pages !== $expected
                || ($expected > 0 && ((int) $summary->first_page !== 1 || (int) $summary->last_page !== $expected))) {
                return false;
            }
        }
        $next = $run->stats['next_page'] ?? null;

        // Order pages restart at 1 in each date window, so only the product
        // cursor can be matched against its staged page count.
        return is_int($next) && $next >= 1 && match ($phase) {
            'products' => $run->stats['order_pages'] === 0 && $next === $run->stats['product_pages'] + 1,
            'orders' => $run->stats['product_pages'] > 0,
            'saving' => $run->stats['product_pages'] > 0 && $run->stats['order_pages'] > 0,
        };
    }

    /** @param list<array<string, mixed>> $rows */
    public function save(int $runId, string $phase, int $page, string $fingerprint, array $rows): void
    {
        if (DB::table('zid_sync_pages')->where('sync_run_id', $runId)->where('phase', $phase)->where('fingerprint', $fingerprint)->exists()) {
            throw new RuntimeException('Zid repeated a page; import stopped to avoid duplicate totals.');
        }
        DB::table('zid_sync_pages')->insert([
            'sync_run_id' => $runId, 'phase' => $phase, 'page' => $page,
            'fingerprint' => $fingerprint, 'rows' => json_encode($rows, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return Generator<int, array<string, mixed>> */
    public function rows(int $runId, string $phase): Generator
    {
        foreach (DB::table('zid_sync_pages')->where('sync_run_id', $runId)->where('phase', $phase)->orderBy('page')->cursor() as $page) {
            foreach (json_decode($page->rows, true, flags: JSON_THROW_ON_ERROR) as $row) {
                yield $row;
            }
        }
    }

    public function clear(int $runId): void
    {
        DB::table('zid_sync_pages')->where('sync_run_id', $runId)->delete();
    }

    public function clearStore(Store $store): void
    {
        DB::table('zid_sync_pages')->whereIn('sync_run_id', $store->syncRuns()->where('type', 'zid_sync')->select('id'))->delete();
    }
}
