<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A candidate product or design run through the quality gate (module 3).
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property int|null $batch_id
 * @property int|null $parent_id
 * @property int|null $root_id
 * @property int $version
 * @property int|null $design_id
 * @property string $source
 * @property string $name
 * @property string|null $category
 * @property string|null $price
 * @property string|null $material
 * @property list<string>|null $colors
 * @property string|null $pattern
 * @property string|null $style
 * @property string|null $season
 * @property string|null $occasion
 * @property string|null $description
 * @property string|null $image_path
 * @property string $status
 * @property int|null $score
 * @property array<string, mixed>|null $breakdown
 * @property string|null $recommendation
 * @property string|null $explanation_ar
 * @property string|null $explanation_en
 * @property list<array<string, mixed>>|null $top_factors
 * @property list<array<string, mixed>>|null $modifications
 * @property int|null $expected_score
 * @property list<array<string, mixed>>|null $cultural_flags
 * @property list<array<string, mixed>>|null $alternatives
 * @property string|null $suggested_launch
 * @property bool $is_novel
 * @property string|null $final_decision
 * @property string|null $override_reason
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string $ai_cost
 * @property string|null $error
 * @property Carbon|null $created_at
 */
#[Fillable([
    'store_id', 'user_id', 'batch_id', 'parent_id', 'root_id', 'version', 'design_id', 'source', 'name',
    'category', 'price', 'material', 'colors', 'pattern', 'style', 'season', 'occasion', 'description',
    'image_path', 'status', 'score', 'breakdown', 'recommendation', 'explanation_ar', 'explanation_en',
    'top_factors', 'modifications', 'expected_score', 'cultural_flags', 'alternatives', 'suggested_launch',
    'is_novel', 'final_decision', 'override_reason', 'decided_by', 'decided_at', 'ai_cost', 'error',
])]
class Evaluation extends Model
{
    public const ACCEPT = 'accept';

    public const REJECT = 'reject';

    public const MODIFY = 'modify';

    public const CRITERIA = ['success_similarity', 'trend_fit', 'saudi_fit', 'failure_risk', 'saturation', 'price_fit'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'colors' => 'array',
            'breakdown' => 'array',
            'top_factors' => 'array',
            'modifications' => 'array',
            'cultural_flags' => 'array',
            'alternatives' => 'array',
            'is_novel' => 'boolean',
            'decided_at' => 'datetime',
            'ai_cost' => 'decimal:6',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<EvaluationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(EvaluationBatch::class, 'batch_id');
    }

    /**
     * @return BelongsTo<Evaluation, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class, 'parent_id');
    }

    /**
     * All versions of the same candidate, oldest first (FR-3.7).
     *
     * @return HasMany<Evaluation, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'root_id', 'root_id')->orderBy('version');
    }

    /**
     * @return BelongsTo<Design, $this>
     */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }
}
