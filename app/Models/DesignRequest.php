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
 * @property array{mode: string, face: string, background: string, back_view: bool}|null $presentation
 * @property array<string, mixed>|null $plan
 * @property list<array{variant: int, view: string, error: string}>|null $failures
 */
#[Fillable([
    'store_id', 'user_id', 'evaluation_id', 'type', 'title', 'prompt', 'category', 'output_type',
    'aspect_ratio', 'variants_count', 'source_image_path', 'status', 'error', 'product_recommendation_id', 'parent_design_id',
    'presentation', 'plan', 'failures',
])]
class DesignRequest extends Model
{
    public const OUTPUT_TYPES = ['product_photo', 'print_file', 'mockup_white', 'mockup_model', 'lifestyle', 'social'];

    /** A request that edits an earlier design (parent_design_id). */
    public const REFINEMENT = 'refinement';

    /**
     * The free idea as a look: the merchant's sketches and fabric photos
     * made into a photograph of the garment, worn or shown as she chose.
     */
    public const LOOK = 'look';

    protected function casts(): array
    {
        return ['presentation' => 'array', 'plan' => 'array', 'failures' => 'array'];
    }

    /**
     * The merchant's own pictures, in the order they are sent.
     *
     * @return HasMany<DesignRequestImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(DesignRequestImage::class)->orderBy('position');
    }

    /** How many images a look makes: each variant, and its back when asked. */
    public function slots(): int
    {
        return max(1, $this->variants_count) * (($this->presentation['back_view'] ?? false) ? 2 : 1);
    }

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
