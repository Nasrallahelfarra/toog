<?php

namespace App\Services\Zid;

use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Durable normalized pages, shared by workers; never stores customer details. */
class ZidSyncStaging
{
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
}
