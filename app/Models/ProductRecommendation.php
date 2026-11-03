<?php

namespace App\Models;

use App\Services\Catalog\RecommendationProgress;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $store_id
 * @property int $product_id
 * @property int|null $user_id
 * @property string $status
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed>|null $review
 * @property string|null $source_image_path
 * @property string|null $error
 */
#[Fillable(['store_id', 'product_id', 'user_id', 'status', 'snapshot', 'review', 'source_image_path', 'error'])]
class ProductRecommendation extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'review' => 'array'];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function expired(): bool
    {
        if ($this->status === 'processing') {
            $started = $this->review['_progress']['started_at'] ?? $this->updated_at;

            return Carbon::parse($started)->lt(now()->subMinutes(7));
        }

        return $this->status === 'pending' && $this->updated_at->lt(now()->subMinutes(15));
    }

    /** @return array<string, mixed>|null */
    public function visualRecommendation(string $id): ?array
    {
        foreach ($this->review['recommendations'] ?? [] as $recommendation) {
            if ($recommendation['id'] === $id && $recommendation['kind'] === 'visual') {
                return $recommendation;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function presentation(): array
    {
        return app(RecommendationProgress::class)->presentation($this);
    }
}
