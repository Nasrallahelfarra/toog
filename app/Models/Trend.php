<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A detected market trend (FR-2.5) — e.g. a colour, pattern or style.
 *
 * @property int $id
 * @property int $store_id
 * @property string $type
 * @property string $name_ar
 * @property string $name_en
 * @property string|null $value
 * @property int $momentum
 * @property string $growth
 * @property int $saturation
 * @property list<string>|null $platforms
 * @property list<string>|null $categories
 * @property bool $in_catalog
 * @property string $status
 * @property Carbon|null $detected_at
 */
#[Fillable([
    'store_id', 'type', 'name_ar', 'name_en', 'value', 'momentum', 'growth', 'saturation', 'platforms',
    'categories', 'in_catalog', 'status', 'detected_at',
])]
class Trend extends Model
{
    public const TYPES = ['color', 'pattern', 'style', 'shape', 'material', 'theme'];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'categories' => 'array',
            'in_catalog' => 'boolean',
            'growth' => 'decimal:1',
            'detected_at' => 'datetime',
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
