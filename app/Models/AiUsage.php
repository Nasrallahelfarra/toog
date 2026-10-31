<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AI call, with its token usage and cost — feeds the admin cost dashboard.
 *
 * @property int $id
 * @property int|null $store_id
 * @property int|null $user_id
 * @property string $provider
 * @property string $model
 * @property string $operation
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $images
 * @property string $cost
 * @property bool $success
 * @property string|null $error
 * @property int $latency_ms
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
#[Fillable([
    'store_id', 'user_id', 'provider', 'model', 'operation', 'input_tokens', 'output_tokens', 'images',
    'cost', 'success', 'error', 'latency_ms', 'meta', 'created_at',
])]
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    public const OPERATIONS = ['evaluation', 'attributes', 'sentiment', 'trend_vision', 'design', 'idea'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:6',
            'success' => 'boolean',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
