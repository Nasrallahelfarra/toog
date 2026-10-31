<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string $platform
 * @property string|null $url
 * @property string|null $handle
 * @property string|null $category
 * @property bool $is_active
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property string|null $notes
 * @property array<string, mixed>|null $analysis
 */
#[Fillable(['store_id', 'name', 'platform', 'url', 'handle', 'category', 'is_active', 'last_checked_at', 'last_error', 'notes', 'analysis'])]
class Competitor extends Model
{
    public const STORE_PLATFORMS = ['website', 'salla', 'zid', 'shopify'];

    public const SOCIAL_PLATFORMS = ['instagram', 'tiktok', 'snapchat'];

    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function isSocial(): bool
    {
        return in_array($this->platform, self::SOCIAL_PLATFORMS, true);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<CompetitorProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(CompetitorProduct::class);
    }

    /**
     * @return HasMany<SocialPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }
}
