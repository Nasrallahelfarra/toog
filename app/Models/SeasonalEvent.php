<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Saudi seasonal calendar entry. A null store_id is a platform-wide event.
 *
 * @property int $id
 * @property int|null $store_id
 * @property string|null $season_key
 * @property string $name_ar
 * @property string $name_en
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int $prep_weeks
 * @property list<string>|null $categories
 * @property list<string>|null $palette
 * @property string|null $notes
 * @property bool $is_active
 */
#[Fillable(['store_id', 'season_key', 'name_ar', 'name_en', 'starts_on', 'ends_on', 'prep_weeks', 'categories', 'palette', 'notes', 'is_active'])]
class SeasonalEvent extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'categories' => 'array',
            'palette' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Platform-wide events plus the store's own.
     *
     * @param  Builder<SeasonalEvent>  $query
     */
    public function scopeForStore(Builder $query, Store $store): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('store_id')->orWhere('store_id', $store->id))
            ->where('is_active', true);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
