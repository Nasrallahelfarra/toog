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
                ->orWhere('features->extracted_by', 'name')
                ->orWhereJsonLength('features->colors', 0));
    }

    /**
     * Resume the latest unfinished catalog, preserving successful products,
     * or create its first run. Repeated clicks join the active run.
     */
    public function start(Store $store, ?int $userId): ?SyncRun
    {
        $this->expire($store);

        [$run, $dispatch] = DB::transaction(function () use ($store): array {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            $active = $store->syncRuns()->where('type', self::TYPE)->whereIn('status', self::ACTIVE)->first();
            if ($active) {
                return [$active, false];
            }
            $pending = $this->pending($store->id)->count();
            if ($pending === 0) {
                return [null, false];
            }
            $previous = $store->syncRuns()->where('type', self::TYPE)->latest('id')->lockForUpdate()->first();
            if ($previous && in_array($previous->status, ['failed', 'completed'], true)) {
                $extracted = (int) ($previous->stats['extracted'] ?? 0);
                $previous->update([
                    'status' => 'queued', 'error' => null, 'finished_at' => null,
                    'stats' => [
                        ...($previous->stats ?? []), 'total' => $extracted + $pending,
                        'done' => $extracted, 'extracted' => $extracted, 'cursor' => 0,
                        'current' => null, 'error_detail' => null, 'consecutive_failures' => 0,
                        'step' => (int) ($previous->stats['step'] ?? 0) + 1, 'step_claimed' => false,
                        'resumed_at' => now()->toIso8601String(), 'resume_done' => $extracted,
                    ],
                ]);

                return [$previous, true];
            }

            return [$store->syncRuns()->create([
                'type' => self::TYPE,
                'status' => 'queued',
                'stats' => ['total' => $pending, 'done' => 0, 'extracted' => 0, 'cursor' => 0, 'step' => 0, 'step_claimed' => false],
            ]), true];
        });

        if (! $run || ! $dispatch) {
            return $run;
        }

        return $this->dispatch($run, $userId);
    }

    private function dispatch(SyncRun $run, ?int $userId): SyncRun
    {
        $step = (int) ($run->stats['step'] ?? 0);
        try {
            ExtractStoreFeatures::dispatch($run->id, $userId, $step);
        } catch (Throwable $e) {
            report($e);
            $this->fail($run, 'queue_failed', step: $step);
        }

        return $run->refresh();
    }

    public function claim(int $runId, int $step): ?SyncRun
    {
        return DB::transaction(function () use ($runId, $step): ?SyncRun {
            $run = SyncRun::query()->where('type', self::TYPE)->lockForUpdate()->find($runId);
            if (! $run || ! in_array($run->status, self::ACTIVE, true)
                || (int) ($run->stats['step'] ?? 0) !== $step || ($run->stats['step_claimed'] ?? false)) {
                return null;
            }
            $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now(),
                'stats' => [...($run->stats ?? []), 'step' => $step, 'step_claimed' => true]]);

            return $run;
        });
    }

    public function current(SyncRun $run, int $step, Product $product): bool
    {
        $stats = [...($run->stats ?? []), 'current' => $product->name];
        $active = $this->stepQuery($run, $step)->where('status', 'running');
        $changed = $active->update(['stats' => json_encode($stats, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $run->stats = $stats;

        return $changed > 0 || $active->exists();
    }

    /** @param array{features: array<string, mixed>, answered: bool} $result */
    public function checkpoint(SyncRun $run, int $step, int $productId, array $result): bool
    {
        return DB::transaction(function () use ($run, $step, $productId, $result): bool {
            $locked = $this->stepQuery($run, $step)->where('status', 'running')->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }
            $product = Product::query()->where('store_id', $run->store_id)->lockForUpdate()->find($productId);
            $stats = [...($locked->stats ?? []), 'cursor' => $productId, 'current' => null];
            if ($product) {
                if (! isset($product->features['extracted_at']) && $result['features'] !== []) {
                    $product->update(['features' => array_merge($product->features ?? [], $result['features'])]);
                }
                $answered = isset($product->features['extracted_at']);
                $stats['done'] = ($stats['done'] ?? 0) + 1;
                $stats['extracted'] = ($stats['extracted'] ?? 0) + (int) $answered;
                $stats['consecutive_failures'] = $answered ? 0 : ($stats['consecutive_failures'] ?? 0) + 1;
            } else {
                $stats['total'] = max($stats['done'] ?? 0, ($stats['total'] ?? 0) - 1);
            }
            $locked->update(['stats' => $stats]);
            $run->stats = $stats;

            return true;
        });
    }

    public function complete(SyncRun $run, int $step): void
    {
        $this->stepQuery($run, $step)->where('status', 'running')->update([
            'status' => 'completed', 'stats' => json_encode([...($run->stats ?? []), 'current' => null], JSON_THROW_ON_ERROR), 'finished_at' => now(),
        ]);
    }

    public function next(SyncRun $run, int $step, ?int $userId): void
    {
        $advanced = $this->stepQuery($run, $step)->where('status', 'running')->update([
            'status' => 'queued', 'stats' => json_encode([...($run->stats ?? []), 'step' => $step + 1, 'step_claimed' => false, 'current' => null], JSON_THROW_ON_ERROR),
        ]);
        if ($advanced) {
            $this->dispatch($run->refresh(), $userId);
        }
    }

    /** @return Builder<SyncRun> */
    private function stepQuery(SyncRun $run, int $step): Builder
    {
        return SyncRun::query()->whereKey($run->id)->where('type', self::TYPE)->where(fn ($q) => $q
            ->where('stats->step', $step)->when($step === 0, fn ($q) => $q->orWhereNull('stats->step')));
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
            'resumed_at' => $run->stats['resumed_at'] ?? null,
            'resume_done' => (int) ($run->stats['resume_done'] ?? 0),
        ] : null;
    }

    public function fail(SyncRun $run, string $code, ?string $detail = null, ?int $step = null): void
    {
        DB::transaction(function () use ($run, $code, $detail, $step): void {
            $locked = ($step === null ? SyncRun::query()->whereKey($run->id) : $this->stepQuery($run, $step))
                ->whereIn('status', self::ACTIVE)->lockForUpdate()->first();
            if ($locked) {
                $locked->update(['status' => 'failed', 'error' => $code, 'finished_at' => now(),
                    'stats' => [...($locked->stats ?? []), 'current' => null, 'error_detail' => $detail !== null ? mb_substr($detail, 0, 250) : null]]);
            }
        });
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
                    $this->fail($run, 'timed_out', step: (int) ($run->stats['step'] ?? 0));
                }

                continue;
            }

            // A short grace covers the moment between saving the run and queueing its job.
            $own = $this->ownJob($run, (clone $jobs)->where('payload', 'like', '%ExtractStoreFeatures%')->get(['id', 'payload', 'reserved_at']));
            if ($own?->reserved_at && $run->updated_at?->lt(now()->subMinutes(15))) {
                $this->fail($run, 'timed_out', step: (int) ($run->stats['step'] ?? 0));
            } elseif ($run->updated_at?->lt(now()->subMinutes(2)) && ! $own) {
                $this->fail($run, 'job_failed', $this->failure($run), (int) ($run->stats['step'] ?? 0));
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
     * @param  iterable<\stdClass>  $jobs
     */
    private function ownJob(SyncRun $run, iterable $jobs): ?\stdClass
    {
        foreach ($jobs as $job) {
            if ($this->runId($job->payload) === $run->id && $this->jobStep($job->payload) === (int) ($run->stats['step'] ?? 0)) {
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
            ->first(fn ($job) => $this->runId($job->payload) === $run->id && $this->jobStep($job->payload) === (int) ($run->stats['step'] ?? 0));

        return $failed ? (strtok((string) $failed->exception, "\n") ?: null) : null;
    }

    private function runId(string $payload): ?int
    {
        $command = json_decode($payload, true)['data']['command'] ?? '';

        return preg_match('/"runId";i:(\d+);/', $command, $match) ? (int) $match[1] : null;
    }

    private function jobStep(string $payload): int
    {
        $command = json_decode($payload, true)['data']['command'] ?? '';

        return preg_match('/"step";i:(\d+);/', $command, $match) ? (int) $match[1] : 0;
    }
}
