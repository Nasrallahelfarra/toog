<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\ActivityLog;
use App\Models\AiUsage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Shared shaping of activity rows and AI-usage time series for the admin pages.
 */
trait AdminData
{
    /**
     * @return array<string, mixed>
     */
    protected function activityRow(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email] : null,
            'store' => $log->store ? ['id' => $log->store->id, 'name' => $log->store->name] : null,
            'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
            'subject_id' => $log->subject_id,
            'properties' => $log->properties,
            'acting_as_admin' => $log->acting_as_admin,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, ActivityLog>  $paginator
     * @return array<string, mixed>
     */
    protected function activityPage(LengthAwarePaginator $paginator): array
    {
        $page = $paginator->toArray();
        $page['data'] = collect($paginator->items())->map(fn (ActivityLog $log) => $this->activityRow($log))->all();

        return $page;
    }

    /**
     * Daily AI cost from $from to today, every day present (zeros filled).
     * With $byOperation each row also carries one key per operation.
     *
     * @return list<array<string, mixed>>
     */
    protected function dailyAiCost(CarbonImmutable $from, ?int $storeId = null, bool $byOperation = false): array
    {
        $rows = AiUsage::query()
            ->selectRaw('DATE(created_at) as day, operation, SUM(cost) as cost, COUNT(*) as calls')
            ->where('created_at', '>=', $from)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->groupBy('day', 'operation')
            ->get()
            ->groupBy('day');

        $series = [];
        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo(now()); $day = $day->addDay()) {
            $key = $day->toDateString();
            /** @var Collection<int, AiUsage> $items */
            $items = $rows->get($key, collect());

            $row = [
                'date' => $key,
                'cost' => round((float) $items->sum('cost'), 4),
                'calls' => (int) $items->sum('calls'),
            ];

            if ($byOperation) {
                foreach (AiUsage::OPERATIONS as $op) {
                    $row[$op] = round((float) $items->where('operation', $op)->sum('cost'), 4);
                }
            }

            $series[] = $row;
        }

        return $series;
    }
}
