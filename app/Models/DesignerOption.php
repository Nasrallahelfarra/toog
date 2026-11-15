<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A designer choice the merchant added: a fabric not in the built-in list,
 * or a store section added by hand and designed as one of the garments.
 *
 * @property int $id
 * @property int $store_id
 * @property string $type
 * @property string $label
 * @property string $normalized
 * @property string|null $garment
 */
#[Fillable(['store_id', 'type', 'label', 'normalized', 'garment'])]
class DesignerOption extends Model
{
    public const FABRIC = 'fabric';

    public const SECTION = 'section';

    /** How many of each a store keeps, so the lists stay easy to scan. */
    public const LIMITS = [self::FABRIC => 20, self::SECTION => 12];

    /**
     * A name in the form used to spot duplicates: no diacritics or
     * tatweel, one form of alef, yaa and taa marbuta, lower case, single
     * spaces. The frontend applies the same rules.
     */
    public static function normalize(string $name): string
    {
        $name = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $name) ?? $name;
        $name = strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($name)) ?? '');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
