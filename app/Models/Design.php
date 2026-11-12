<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A generated design in the library (FR-4.8).
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $design_request_id
 * @property int|null $design_collection_id
 * @property int|null $user_id
 * @property int|null $evaluation_id
 * @property string $title
 * @property string $image_path
 * @property string $output_type
 * @property string $aspect_ratio
 * @property list<string>|null $palette
 * @property list<string>|null $tags
 * @property string $status
 * @property bool $is_favorite
 * @property int|null $score
 * @property string|null $provider
 * @property string|null $model
 * @property string $cost
 * @property string|null $prompt
 * @property-read string $image_url
 */
#[Fillable([
    'store_id', 'design_request_id', 'user_id', 'evaluation_id', 'title', 'image_path', 'output_type',
    'aspect_ratio', 'palette', 'tags', 'status', 'is_favorite', 'score', 'provider', 'model', 'cost', 'prompt',
    'design_collection_id',
])]
#[Appends(['image_url'])]
class Design extends Model
{
    public const STATUSES = ['draft', 'in_review', 'approved', 'rejected'];

    protected function casts(): array
    {
        return [
            'palette' => 'array',
            'tags' => 'array',
            'is_favorite' => 'boolean',
            'cost' => 'decimal:6',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->image_path));
    }

    /**
     * @return BelongsTo<DesignRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DesignRequest::class, 'design_request_id');
    }

    /**
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }
}
