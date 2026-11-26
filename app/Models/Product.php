<?php

namespace App\Models;

use App\Services\Analytics\PerformanceClassifier;
use App\Support\ExcludedProducts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A product from the store's own catalog (imported from Zid or a file).
 *
 * @property int $id
 * @property int $store_id
 * @property bool $excluded An accessory: left out of every page, total and prompt.
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
 * @property int|null $turnover_days Retired: was stock-based, now always null.
 * @property int|null $no_sales_days Days without a sale, when the sales data can show it.
 * @property int $stock Retired: no longer imported, read or shown. Pieces are made to order, so it was never real.
 * @property bool $is_novel
 * @property Carbon|null $launched_at
 * @property Carbon|null $listed_at When it was created in the store, to the second ("newest first" sorts by it)
 * @property int|null $assigned_user_id
 * @property Carbon|null $assigned_at
 */
// assigned_user_id / assigned_at are deliberately not fillable: the sync
// and the file import mass-assign product rows and must never touch who
// is responsible for a product. Only ProductAssignments writes them.
#[Fillable([
    'store_id', 'external_id', 'sku', 'name', 'category', 'price', 'image_url', 'features',
    'performance', 'performance_score', 'units_sold', 'revenue', 'views', 'add_to_cart_rate',
    'return_rate', 'turnover_days', 'no_sales_days', 'stock', 'is_novel', 'launched_at', 'listed_at',
])]
class Product extends Model
{
    public const WINNER = 'winner';

    public const AVERAGE = 'average';

    public const AT_RISK = 'at_risk';

    public const STAGNANT = 'stagnant';

    protected static function booted(): void
    {
        // Accessories are left out of the platform. Every query skips them
        // unless it asks for them with withExcluded().
        static::addGlobalScope('visible', fn (Builder $query) => $query->where($query->qualifyColumn('excluded'), false));

        // The mark follows the product's own name and section, so a renamed
        // or re-filed product is re-judged on its next save.
        static::saving(function (Product $product): void {
            if (! $product->exists || $product->isDirty(['name', 'category'])) {
                $product->excluded = ExcludedProducts::matches($product->category, $product->name);
            }
        });
    }

    /**
     * Every product, accessories included: for the writes that must find
     * a row that is hidden (the sync and the file import).
     *
     * @return Builder<static>
     */
    public static function withExcluded(): Builder
    {
        return static::withoutGlobalScope('visible');
    }

    /**
     * Newest first, as the store lists them in Zid: by the moment each was
     * created there, then by launch day for products not yet synced with a
     * time; undated products last. The id settles exact ties, so a page
     * never shuffles between visits.
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder<self>|\Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function newestFirst($query)
    {
        return $query
            ->orderByRaw('CASE WHEN COALESCE(products.listed_at, products.launched_at) IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(products.listed_at, products.launched_at) DESC')
            ->orderByDesc('products.id');
    }

    /**
     * Why the product is stagnant or at risk, in terms of its sales only.
     */
    public function performanceReason(): ?string
    {
        if ($this->performance === self::STAGNANT) {
            return 'no_sales_90';
        }
        if ($this->performance !== self::AT_RISK) {
            return null;
        }

        return match (true) {
            $this->units_sold === 0 => 'early_no_sales',
            (int) $this->no_sales_days >= PerformanceClassifier::QUIET_AFTER_DAYS => 'quiet_period',
            default => 'low_performance',
        };
    }

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price' => 'decimal:2',
            'revenue' => 'decimal:2',
            'add_to_cart_rate' => 'decimal:2',
            'return_rate' => 'decimal:2',
            'is_novel' => 'boolean',
            'excluded' => 'boolean',
            'launched_at' => 'date',
            'listed_at' => 'datetime',
            'assigned_at' => 'datetime',
        ];
    }

    /**
     * The store member responsible for this product, if anyone claimed it.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
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

    /** @return HasOne<ProductRecommendation, $this> */
    public function recovery(): HasOne
    {
        return $this->hasOne(ProductRecommendation::class)->latestOfMany();
    }
}
