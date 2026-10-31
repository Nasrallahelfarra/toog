<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Customer review from the store or a competitor (FR-2.4).
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $competitor_id
 * @property string $source
 * @property string|null $product_name
 * @property string $text
 * @property int|null $rating
 * @property string|null $language
 * @property string|null $sentiment
 * @property string|null $sentiment_score
 * @property list<string>|null $topics
 * @property Carbon|null $analyzed_at
 */
#[Fillable([
    'store_id', 'competitor_id', 'source', 'product_name', 'text', 'rating', 'language', 'sentiment',
    'sentiment_score', 'topics', 'analyzed_at',
])]
class CustomerReview extends Model
{
    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'sentiment_score' => 'decimal:2',
            'analyzed_at' => 'datetime',
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
