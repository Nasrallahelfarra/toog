<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One of the merchant's own pictures for a look: a hand sketch (front or
 * back), a fabric photo or a close-up of a detail. Staged (no request)
 * from upload until the request is sent.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property int|null $design_request_id
 * @property string $role
 * @property int $position
 * @property string $path
 * @property int|null $width
 * @property int|null $height
 * @property string|null $original_name
 * @property-read string $url
 */
#[Fillable(['store_id', 'user_id', 'design_request_id', 'role', 'position', 'path', 'width', 'height', 'original_name'])]
class DesignRequestImage extends Model
{
    /** Roles in the order they are shown and sent, with how many of each. */
    public const ROLES = ['sketch_front' => 1, 'sketch_back' => 1, 'fabric' => 3, 'detail' => 2];

    public const MAX_TOTAL = 6;

    /** Staged pictures never sent are removed after this long. */
    public const STAGED_HOURS = 24;

    /** @return BelongsTo<DesignRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DesignRequest::class, 'design_request_id');
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->path));
    }

    /**
     * @return array{id: int, role: string, url: string, width: int|null, height: int|null}
     */
    public function present(): array
    {
        return ['id' => $this->id, 'role' => $this->role, 'url' => $this->url, 'width' => $this->width, 'height' => $this->height];
    }
}
