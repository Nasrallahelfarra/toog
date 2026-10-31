<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Monthly sales aggregate for one product (FR-1.2).
 *
 * @property int $id
 * @property int $store_id
 * @property int $product_id
 * @property Carbon $period
 * @property int $orders
 * @property int $units
 * @property string $revenue
 * @property int $views
 * @property int $add_to_cart
 * @property int $returns
 */
#[Fillable(['store_id', 'product_id', 'period', 'orders', 'units', 'revenue', 'views', 'add_to_cart', 'returns'])]
class ProductSale extends Model
{
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'revenue' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
