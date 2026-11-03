<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return in_array($this->status, ['pending', 'processing'], true) && $this->updated_at->lt(now()->subMinutes(15));
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
        return ['id' => $this->id, 'status' => $this->expired() ? 'failed' : $this->status,
            'review' => $this->review, 'error' => $this->expired() ? 'timed_out' : $this->error,
            'created_at' => $this->created_at->toIso8601String()];
    }
}
