<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A product from the store's own catalog (imported from Zid or a file).
 *
 * @property int $id
 * @property int $store_id
 * @property string|null $external_id
 * @property string|null $sku
 * @property string $name
 * @property string|null $category
 * @property string|null $price
 * @property string|null $image_url
 * @property array<string, mixed>|null $features
 * @property string|null $performance
 * @property int|null $performance_score
 * @property int $units_sold
 * @property string $revenue
 * @property int $views
 * @property string $add_to_cart_rate
 * @property string $return_rate
 * @property int|null $turnover_days
 * @property int $stock
 * @property bool $is_novel
 * @property Carbon|null $launched_at
 */
#[Fillable([
    'store_id', 'external_id', 'sku', 'name', 'category', 'price', 'image_url', 'features',
    'performance', 'performance_score', 'units_sold', 'revenue', 'views', 'add_to_cart_rate',
    'return_rate', 'turnover_days', 'stock', 'is_novel', 'launched_at',
])]
class Product extends Model
{
    public const WINNER = 'winner';

    public const AVERAGE = 'average';

    public const STAGNANT = 'stagnant';

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price' => 'decimal:2',
            'revenue' => 'decimal:2',
            'add_to_cart_rate' => 'decimal:2',
            'return_rate' => 'decimal:2',
            'is_novel' => 'boolean',
            'launched_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<ProductSale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(ProductSale::class);
    }
}
