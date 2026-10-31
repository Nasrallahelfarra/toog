<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A background data job: Zid sync, file import, competitor scan.
 *
 * @property int $id
 * @property int $store_id
 * @property string $type
 * @property string $status
 * @property array<string, mixed>|null $stats
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['store_id', 'type', 'status', 'stats', 'error', 'started_at', 'finished_at'])]
class SyncRun extends Model
{
    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
