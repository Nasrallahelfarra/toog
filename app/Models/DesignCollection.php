<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A collection from the AI fashion designer: the merchant's brief and the
 * design concepts it produced, each with a design sheet and an image.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property string $status
 * @property string|null $error
 * @property array<string, mixed> $brief
 * @property string|null $fabric_image_path
 * @property string|null $title_ar
 * @property string|null $title_en
 * @property string|null $story_ar
 * @property string|null $story_en
 * @property list<array<string, mixed>>|null $concepts
 * @property bool $from_model
 * @property string $cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'store_id', 'user_id', 'status', 'error', 'brief', 'fabric_image_path', 'title_ar', 'title_en',
    'story_ar', 'story_en', 'concepts', 'from_model', 'cost',
])]
class DesignCollection extends Model
{
    public const ACTIVE = ['pending', 'processing'];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'concepts' => 'array',
            'from_model' => 'boolean',
            'cost' => 'decimal:6',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
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

    /**
     * @return HasMany<Design, $this>
     */
    public function designs(): HasMany
    {
        return $this->hasMany(Design::class);
    }
}
