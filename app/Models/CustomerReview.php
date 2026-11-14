<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Customer review from the store or a competitor (FR-2.4).
 *
 * Reviews imported from Zid carry Zid's id in external_id; seeded sample
 * reviews have none and are never shown in a store connected to Zid.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $competitor_id
 * @property string $source
 * @property string|null $external_id
 * @property string|null $product_name
 * @property int|null $product_id
 * @property string $text
 * @property int|null $rating
 * @property string|null $language
 * @property string|null $sentiment
 * @property string|null $sentiment_score
 * @property list<string>|null $topics
 * @property Carbon|null $reviewed_at
 * @property array<string, mixed>|null $evidence
 * @property Carbon|null $analyzed_at
 */
#[Fillable([
    'store_id', 'competitor_id', 'source', 'external_id', 'product_name', 'product_id', 'text', 'rating', 'language',
    'sentiment', 'sentiment_score', 'topics', 'reviewed_at', 'evidence', 'analyzed_at',
])]
class CustomerReview extends Model
{
    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'evidence' => 'array',
            'sentiment_score' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    /**
     * A store whose reviews come from Zid: its own credentials are set.
     */
    public static function fromZid(Store $store): bool
    {
        return $store->platform === 'zid' && filled($store->zid_access_token);
    }

    /**
     * The reviews to show and analyse for a store: real imports only once
     * the store is connected to Zid, everything for a sample store.
     *
     * @param  Builder<CustomerReview>  $query
     */
    public function scopeShownFor(Builder $query, Store $store): void
    {
        $query->where('store_id', $store->id)
            ->when(self::fromZid($store), fn (Builder $q) => $q->whereNotNull('external_id'));
    }

    /**
     * @return BelongsTo<Competitor, $this>
     */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
