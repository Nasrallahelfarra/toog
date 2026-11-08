<?php

namespace App\Services\Market;

use App\Jobs\ScanCompetitor;
use App\Jobs\ScanCompetitors;
use App\Models\Competitor;
use App\Models\CompetitorScan;
use App\Models\Store;
use App\Services\Market\Fetchers\FetchResult;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompetitorScanState
{
    /** Completed stages out of five, not an estimate of remaining time. */
    private const PROGRESS = ['rules' => 0, 'storefront' => 20, 'products' => 40, 'saving' => 60, 'analysis' => 80];

    private const SOCIAL_FAILURES = ['apify_unauthorized', 'apify_payment', 'apify_timeout', 'apify_unavailable', 'no_posts'];

    public function reserve(Competitor $competitor): CompetitorScan
    {
        return DB::transaction(function () use ($competitor): CompetitorScan {
            Competitor::query()->whereKey($competitor->id)->lockForUpdate()->firstOrFail();
            $this->expire($competitor->id);

            return CompetitorScan::query()->where('competitor_id', $competitor->id)
                ->whereIn('status', ['queued', 'running'])->first()
                ?? CompetitorScan::query()->create(['competitor_id' => $competitor->id,
                    'status' => 'queued', 'stage' => 'queued', 'progress' => 0, 'metrics' => ['pages' => 0, 'products' => 0]]);
        });
    }

    public function enqueue(Competitor $competitor): CompetitorScan
    {
        $scan = $this->reserve($competitor);
        if (! $scan->wasRecentlyCreated) {
            return $scan;
        }
        $this->dispatch([$scan], fn () => ScanCompetitor::dispatch($scan->id));

        return $scan->refresh();
    }

    public function enqueueStore(Store $store): void
    {
        $new = [];
        foreach ($store->competitors()->where('is_active', true)->get() as $competitor) {
            $scan = $this->reserve($competitor);
            if ($scan->wasRecentlyCreated) {
                $new[] = $scan;
            }
        }
        if ($new) {
            // Retain the batch totals and trend refresh performed by the existing job.
            $this->dispatch($new, fn () => ScanCompetitors::dispatch($store->id));
        }
    }

    /** Run an already reserved scan in its own job. A second job for it exits. */
    public function handOff(CompetitorScan $scan): void
    {
        if ($scan->status === 'queued') {
            $this->dispatch([$scan], fn () => ScanCompetitor::dispatch($scan->id));
        }
    }

    /** @param list<CompetitorScan> $scans */
    private function dispatch(array $scans, Closure $dispatch): void
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (in_array($driver, ['sync', 'null', 'deferred'], true)) {
            foreach ($scans as $scan) {
                $this->fail($scan, 'queue_failed');
            }

            return;
        }
        try {
            $dispatch();
        } catch (Throwable $e) {
            report($e);
            foreach ($scans as $scan) {
                $this->fail($scan, 'queue_failed');
            }
        }
    }

    public function claim(CompetitorScan $scan): bool
    {
        return CompetitorScan::query()->whereKey($scan->id)->where('status', 'queued')->update([
            'status' => 'running', 'stage' => 'rules', 'started_at' => now(), 'updated_at' => now(),
        ]) === 1;
    }

    /** @param array<string, int> $metrics */
    public function advance(CompetitorScan $scan, string $stage, array $metrics = []): void
    {
        $scan->refresh();
        if ($scan->status !== 'running') {
            throw new \RuntimeException('This scan is no longer active.');
        }
        $scan->update(['stage' => $stage, 'progress' => self::PROGRESS[$stage],
            'metrics' => array_merge($scan->metrics ?? [], $metrics)]);
    }

    public function finish(CompetitorScan $scan, FetchResult $result): void
    {
        if ($result->status !== FetchResult::OK) {
            // Apify failures keep their own code so the merchant knows what to fix.
            $code = match (true) {
                $result->reason === 'needs_apify', in_array($result->reason, self::SOCIAL_FAILURES, true) => $result->reason,
                $result->status === FetchResult::UNSUPPORTED => 'unsupported',
                default => 'fetch_failed',
            };
            $this->fail($scan, $code, $result->message);

            return;
        }
        CompetitorScan::query()->whereKey($scan->id)->where('status', 'running')->update([
            'status' => 'completed', 'stage' => 'completed', 'progress' => 100, 'finished_at' => now(),
        ]);
    }

    public function fail(CompetitorScan $scan, string $code, ?string $detail = null): void
    {
        $changed = CompetitorScan::query()->whereKey($scan->id)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error_code' => $code, 'error' => $detail ? mb_substr($detail, 0, 250) : null, 'finished_at' => now(),
        ]);
        if ($changed && ($competitor = $scan->competitor) && ($competitor->analysis['ai']['status'] ?? null) === 'processing') {
            $analysis = $competitor->analysis;
            $analysis['ai']['status'] = 'unavailable';
            $competitor->update(['analysis' => $analysis]);
        }
    }

    public function expire(int $competitorId): void
    {
        $stale = CompetitorScan::query()->where('competitor_id', $competitorId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'running')->where('started_at', '<', now()->subMinutes(11)))
                ->orWhere(fn ($q) => $q->where('status', 'queued')->where('created_at', '<', now()->subMinutes(15))))->get();
        foreach ($stale as $scan) {
            $this->fail($scan, $scan->status === 'queued' ? 'queue_timeout' : 'timed_out');
        }
    }
}
