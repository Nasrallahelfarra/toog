<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The AI designer's latest reading of the store: alerts and advice drawn
 * from the store's sales, competitors and the market.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property array<string, mixed> $advice
 * @property bool $from_model
 * @property Carbon|null $created_at
 */
#[Fillable(['store_id', 'user_id', 'advice', 'from_model'])]
class DesignerBriefing extends Model
{
    protected function casts(): array
    {
        return ['advice' => 'array', 'from_model' => 'boolean'];
    }
}
