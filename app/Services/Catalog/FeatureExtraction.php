<?php

namespace App\Services\Catalog;

use App\Jobs\ExtractStoreFeatures;
use App\Models\Product;
use App\Models\Store;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Store-wide attribute extraction (FR-1.4), tracked as a sync run so one
 * click covers the whole catalog and the performance page can show progress.
 */
class FeatureExtraction
{
    public const TYPE = 'feature_extraction';

    public const ACTIVE = ['queued', 'running'];

    /**
     * Products missing colours or pattern that the model has not read yet.
     * A product it already answered for stays out even when the image shows
     * no pattern, so each run moves on through the catalog.
     *
     * @return Builder<Product>
     */
    public function pending(int $storeId): Builder
    {
        return Product::query()
            ->where('store_id', $storeId)
            ->whereNull('features->extracted_at')
            ->where(fn (Builder $q) => $q->whereNull('features')
                ->orWhereNull('features->pattern')
                ->orWhereNull('features->colors')
                ->orWhereJsonLength('features->colors', 0));
    }

    /**
     * Queue a run over every pending product, or return the run already in
     * progress. Null when nothing is left to extract.
     */
    public function start(Store $store, ?int $userId): ?SyncRun
    {
        $this->expire($store);

        $run = DB::transaction(function () use ($store): ?SyncRun {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            $active = $store->syncRuns()->where('type', self::TYPE)->whereIn('status', self::ACTIVE)->first();
            $total = $active ? 0 : $this->pending($store->id)->count();

            return $active ?? ($total === 0 ? null : $store->syncRuns()->create([
                'type' => self::TYPE,
                'status' => 'queued',
                'stats' => ['total' => $total, 'done' => 0, 'extracted' => 0, 'cursor' => 0],
            ]));
        });

        if (! $run?->wasRecentlyCreated) {
            return $run;
        }

        try {
            ExtractStoreFeatures::dispatch($run->id, $userId);
        } catch (Throwable $e) {
            report($e);
            $this->fail($run, 'queue_failed');
        }

        return $run->refresh();
    }

    /**
     * The latest run, for the progress panel.
     *
     * @return array<string, mixed>|null
     */
    public function latest(Store $store): ?array
    {
        $this->expire($store);
        $run = $store->syncRuns()->where('type', self::TYPE)->latest('id')->first();

        return $run ? [
            'id' => $run->id,
            'status' => $run->status,
            'total' => (int) ($run->stats['total'] ?? 0),
            'done' => (int) ($run->stats['done'] ?? 0),
            'extracted' => (int) ($run->stats['extracted'] ?? 0),
            'current' => $run->stats['current'] ?? null,
            'error' => $run->error,
            'error_detail' => $run->stats['error_detail'] ?? null,
            'queue' => $this->queue($run),
            // Keep the panel for a day after the run ends, then fall back to the prompt.
            'recent' => in_array($run->status, self::ACTIVE, true) || (bool) $run->finished_at?->gt(now()->subDay()),
            'queued_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ] : null;
    }

    public function fail(SyncRun $run, string $code, ?string $detail = null): void
    {
        $updates = ['status' => 'failed', 'error' => $code, 'finished_at' => now(), 'updated_at' => now()];

        if ($detail !== null) {
            $updates['stats'] = json_encode([...($run->fresh()->stats ?? []), 'error_detail' => mb_substr($detail, 0, 250)]);
        }

        SyncRun::query()->whereKey($run->id)->whereIn('status', self::ACTIVE)->update($updates);
    }

    /**
     * Where an active run's job stands in a database queue, so the panel can
     * say why it waits: jobs ahead of it and what the worker is busy with.
     * Null on other queue drivers.
     *
     * @return array{found: bool, reserved: bool, ahead: int, busy: string|null, busy_since: string|null}|null
     */
    private function queue(SyncRun $run): ?array
    {
        $jobs = $this->jobs();

        if (! $jobs || ! in_array($run->status, self::ACTIVE, true)) {
            return null;
        }

        $own = $this->ownJob($run, (clone $jobs)->where('payload', 'like', '%ExtractStoreFeatures%')->get(['id', 'payload', 'reserved_at']));
        $busy = (clone $jobs)->whereNotNull('reserved_at')->orderBy('reserved_at')->first(['payload', 'reserved_at']);

        return [
            'found' => $own !== null,
            'reserved' => $own?->reserved_at !== null,
            'ahead' => $own ? (clone $jobs)->whereNull('reserved_at')->where('id', '<', $own->id)->count() : 0,
            'busy' => $busy ? class_basename(json_decode($busy->payload, true)['displayName'] ?? '') : null,
            'busy_since' => $busy ? now()->setTimestamp((int) $busy->reserved_at)->toIso8601String() : null,
        ];
    }

    /**
     * Release a run whose job is gone so the button works again. On a
     * database queue that means the job left the queue without finishing the
     * run (it failed or was cleared); elsewhere, 15 quiet minutes.
     */
    private function expire(Store $store): void
    {
        $runs = $store->syncRuns()->where('type', self::TYPE)->whereIn('status', self::ACTIVE)->get();

        foreach ($runs as $run) {
            $jobs = $this->jobs();

            if (! $jobs) {
                if ($run->updated_at?->lt(now()->subMinutes(15))) {
                    $this->fail($run, 'timed_out');
                }

                continue;
            }

            // A short grace covers the moment between saving the run and queueing its job.
            if ($run->updated_at?->lt(now()->subMinutes(2)) && ! $this->ownJob($run, (clone $jobs)->where('payload', 'like', '%ExtractStoreFeatures%')->get(['id', 'payload', 'reserved_at']))) {
                $this->fail($run, 'job_failed', $this->failure($run));
            }
        }
    }

    /**
     * Pending jobs on the default database queue, or null for other drivers.
     */
    private function jobs(): ?QueryBuilder
    {
        $config = config('queue.connections.'.config('queue.default'));

        if (($config['driver'] ?? null) !== 'database') {
            return null;
        }

        return DB::connection($config['connection'] ?? null)->table($config['table'] ?? 'jobs')->where('queue', $config['queue'] ?? 'default');
    }

    /**
     * @param  iterable<object>  $jobs
     */
    private function ownJob(SyncRun $run, iterable $jobs): ?object
    {
        foreach ($jobs as $job) {
            if ($this->runId($job->payload) === $run->id) {
                return $job;
            }
        }

        return null;
    }

    /** The first line of the worker's exception for this run's job, if it failed. */
    private function failure(SyncRun $run): ?string
    {
        $failed = DB::table('failed_jobs')->where('payload', 'like', '%ExtractStoreFeatures%')
            ->latest('id')->limit(20)->get(['payload', 'exception'])
            ->first(fn ($job) => $this->runId($job->payload) === $run->id);

        return $failed ? strtok((string) $failed->exception, "\n") : null;
    }

    private function runId(string $payload): ?int
    {
        $command = json_decode($payload, true)['data']['command'] ?? '';

        return preg_match('/"runId";i:(\d+);/', $command, $match) ? (int) $match[1] : null;
    }
}
