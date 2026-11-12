<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $store_id
 * @property int|null $competitor_id
 * @property string|null $external_id
 * @property string $platform
 * @property string|null $url
 * @property string|null $caption
 * @property string|null $thumbnail_url
 * @property int $views
 * @property int $likes
 * @property int $shares
 * @property int $comments
 * @property string $engagement_rate
 * @property array<string, mixed>|null $features
 * @property array<string, mixed>|null $evidence
 * @property Carbon|null $posted_at
 * @property array<string, mixed>|null $ai_reading per language, kept across scans
 */
#[Fillable([
    'store_id', 'competitor_id', 'external_id', 'platform', 'url', 'caption', 'thumbnail_url', 'views', 'likes', 'shares',
    'comments', 'engagement_rate', 'features', 'evidence', 'posted_at', 'ai_reading',
])]
class SocialPost extends Model
{
    /**
     * The engagement rate, or null when Apify supplied neither followers
     * nor views to measure it against. Demo and legacy posts keep theirs.
     */
    public function measuredEngagement(): ?float
    {
        return $this->evidence !== null && ($this->evidence['engagement_basis'] ?? null) === null
            ? null : (float) $this->engagement_rate;
    }

    /**
     * Posts whose engagement rate was measured, so averages and rankings
     * are not pulled down by posts with nothing to measure against.
     *
     * @param  Builder<SocialPost>  $query
     */
    public function scopeMeasured(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('evidence')->orWhereNotNull('evidence->engagement_basis'));
    }

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'evidence' => 'array',
            'ai_reading' => 'array',
            'engagement_rate' => 'decimal:2',
            'posted_at' => 'datetime',
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
