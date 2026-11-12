<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The AI reading of the competitor radar for one time window: what product
 * each standout post shows, why it likely engaged, and the takeaways.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property int $days
 * @property array<string, mixed> $report
 * @property bool $from_model
 * @property Carbon|null $created_at
 */
#[Fillable(['store_id', 'user_id', 'days', 'report', 'from_model'])]
class CompetitorReading extends Model
{
    protected function casts(): array
    {
        return ['report' => 'array', 'from_model' => 'boolean'];
    }
}
