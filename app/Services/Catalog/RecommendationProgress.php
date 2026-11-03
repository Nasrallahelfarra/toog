<?php

namespace App\Services\Catalog;

use App\Models\ProductRecommendation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Actual queue/processing state for an interactive expert recommendation. */
class RecommendationProgress
{
    /** @var Collection<int, \stdClass>|null */
    private ?Collection $queued = null;

    public function refresh(ProductRecommendation $run): ProductRecommendation
    {
        if (! in_array($run->status, ['pending', 'processing'], true)) {
            return $run;
        }
        $queue = $run->status === 'pending' ? $this->queue($run) : null;
        $error = null;
        if ($run->expired() && ($run->status === 'processing' || ! ($queue['found'] ?? false))) {
            $error = 'timed_out';
        } elseif ($run->status === 'pending' && $queue !== null && ! $queue['found'] && $run->updated_at->lt(now()->subMinutes(2))) {
            $error = 'job_lost';
        }
        if ($error) {
            ProductRecommendation::query()->whereKey($run->id)->where('status', $run->status)
                ->where('updated_at', $run->updated_at)->update(['status' => 'failed', 'error' => $error]);
            $run->refresh();
        }

        return $run;
    }

    /** @return array<string, mixed> */
    public function presentation(ProductRecommendation $run): array
    {
        $this->refresh($run);
        $progress = $run->review['_progress'] ?? [];

        return ['id' => $run->id, 'status' => $run->status, 'error' => $run->error,
            'review' => $run->status === 'completed' ? array_diff_key($run->review ?? [], ['_progress' => true]) : null,
            'phase' => $run->status === 'pending' ? 'queued' : ($progress['phase'] ?? ($run->source_image_path ? 'model' : 'context')),
            'created_at' => $run->created_at->toIso8601String(),
            'started_at' => $progress['started_at'] ?? ($run->status === 'processing' ? $run->updated_at->toIso8601String() : null),
            'queue' => $run->status === 'pending' ? $this->queue($run) : null];
    }

    public function advance(ProductRecommendation $run, string $phase): bool
    {
        $review = ['_progress' => ['phase' => $phase,
            'started_at' => $run->review['_progress']['started_at'] ?? now()->toIso8601String()]];
        $saved = ProductRecommendation::query()->whereKey($run->id)->where('status', 'processing')
            ->update(['review' => json_encode($review, JSON_THROW_ON_ERROR)]);
        if ($saved) {
            $run->review = $review;
        }

        return (bool) $saved;
    }

    public function fail(int $id, string $error): void
    {
        ProductRecommendation::query()->whereKey($id)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error' => $error]);
    }

    /** @return array{found: bool, ahead: int, busy: string|null}|null */
    private function queue(ProductRecommendation $run): ?array
    {
        $config = config('queue.connections.'.config('queue.default'));
        if (($config['driver'] ?? null) !== 'database') {
            return null;
        }
        $jobs = DB::connection($config['connection'] ?? null)->table($config['table'] ?? 'jobs')
            ->where('queue', $config['queue'] ?? 'default');
        // Request-scoped cache: do not reload all recommendation payloads for each card.
        $this->queued ??= (clone $jobs)->where('payload', 'like', '%RecommendStagnantProduct%')->get(['id', 'payload']);
        $own = $this->queued->first(function ($job) use ($run): bool {
            $command = json_decode($job->payload, true)['data']['command'] ?? '';

            return (bool) preg_match('/"recommendationId";i:'.$run->id.';/', $command);
        });
        $busy = (clone $jobs)->whereNotNull('reserved_at')->orderBy('reserved_at')->value('payload');

        return ['found' => $own !== null,
            'ahead' => $own ? (clone $jobs)->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp)->where('id', '<', $own->id)->count() : 0,
            'busy' => $busy ? class_basename(json_decode($busy, true)['displayName'] ?? '') : null];
    }
}
