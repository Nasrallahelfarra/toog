<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $store_id
 * @property int $competitor_id
 * @property string|null $external_id
 * @property string $name
 * @property string|null $category
 * @property string|null $price
 * @property string|null $previous_price
 * @property string|null $image_url
 * @property string|null $url
 * @property bool $is_best_seller
 * @property array<string, mixed>|null $features
 * @property array<string, mixed>|null $evidence
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $watched_at
 */
#[Fillable([
    'store_id', 'competitor_id', 'external_id', 'name', 'category', 'price', 'previous_price', 'image_url',
    'url', 'is_best_seller', 'features', 'first_seen_at', 'last_seen_at', 'evidence', 'watched_at',
])]
class CompetitorProduct extends Model
{
    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'features' => 'array',
            'price' => 'decimal:2',
            'previous_price' => 'decimal:2',
            'is_best_seller' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'watched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Competitor, $this>
     */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }
}
