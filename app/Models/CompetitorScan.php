<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $competitor_id
 * @property string $status
 * @property string $stage
 * @property int $progress
 * @property array<string, int>|null $metrics
 * @property string|null $error_code
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['competitor_id', 'status', 'stage', 'progress', 'metrics', 'error_code', 'error', 'started_at', 'finished_at'])]
class CompetitorScan extends Model
{
    protected function casts(): array
    {
        return ['metrics' => 'array', 'progress' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<Competitor, $this> */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
