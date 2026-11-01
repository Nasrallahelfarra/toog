<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'evaluation_id', 'user_id', 'request_key', 'status', 'recommendations', 'source_snapshot', 'source_image_path', 'design_id', 'result_evaluation_id', 'verification', 'error', 'approved_at', 'parent_improvement_id', 'custom_instruction'])]
class DesignImprovement extends Model
{
    public const ACTIVE = ['queued', 'generating', 'review_queued', 'reviewing'];

    protected function casts(): array
    {
        return ['recommendations' => 'array', 'source_snapshot' => 'array', 'verification' => 'array', 'approved_at' => 'datetime'];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class, 'result_evaluation_id');
    }

    public function parentImprovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_improvement_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
