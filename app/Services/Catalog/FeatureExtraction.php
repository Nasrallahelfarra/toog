<?php

namespace App\Services\Catalog;

use App\Jobs\ExtractStoreFeatures;
use App\Models\Product;
use App\Models\Store;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Builder;
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
            // Keep the panel for a day after the run ends, then fall back to the prompt.
            'recent' => in_array($run->status, self::ACTIVE, true) || (bool) $run->finished_at?->gt(now()->subDay()),
            'queued_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ] : null;
    }

    public function fail(SyncRun $run, string $code): void
    {
        SyncRun::query()->whereKey($run->id)->whereIn('status', self::ACTIVE)
            ->update(['status' => 'failed', 'error' => $code, 'finished_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Release a run whose worker died so the button works again. The job
     * touches the run after every product, so 15 quiet minutes means it stopped.
     */
    private function expire(Store $store): void
    {
        $store->syncRuns()->where('type', self::TYPE)->whereIn('status', self::ACTIVE)
            ->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'error' => 'timed_out', 'finished_at' => now(), 'updated_at' => now()]);
    }
}
