<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $store_id
 * @property int|null $competitor_id
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
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'store_id', 'competitor_id', 'platform', 'url', 'caption', 'thumbnail_url', 'views', 'likes', 'shares',
    'comments', 'engagement_rate', 'features', 'posted_at',
])]
class SocialPost extends Model
{
    protected function casts(): array
    {
        return [
            'features' => 'array',
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
