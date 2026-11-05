<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One generation request in the design studio (module 4).
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property int|null $evaluation_id
 * @property int|null $product_recommendation_id
 * @property string $type
 * @property string|null $title
 * @property string $prompt
 * @property string|null $category
 * @property string $output_type
 * @property string $aspect_ratio
 * @property int $variants_count
 * @property string|null $source_image_path
 * @property string $status
 * @property string|null $error
 */
#[Fillable([
    'store_id', 'user_id', 'evaluation_id', 'type', 'title', 'prompt', 'category', 'output_type',
    'aspect_ratio', 'variants_count', 'source_image_path', 'status', 'error', 'product_recommendation_id', 'parent_design_id',
])]
class DesignRequest extends Model
{
    public const OUTPUT_TYPES = ['product_photo', 'print_file', 'mockup_white', 'mockup_model', 'lifestyle', 'social'];

    /** A request that edits an earlier design (parent_design_id). */
    public const REFINEMENT = 'refinement';

    /**
     * @return HasMany<Design, $this>
     */
    public function designs(): HasMany
    {
        return $this->hasMany(Design::class);
    }

    /**
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /** @return BelongsTo<Design, $this> */
    public function parentDesign(): BelongsTo
    {
        return $this->belongsTo(Design::class, 'parent_design_id');
    }

    /** @return BelongsTo<ProductRecommendation, $this> */
    public function productRecommendation(): BelongsTo
    {
        return $this->belongsTo(ProductRecommendation::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
