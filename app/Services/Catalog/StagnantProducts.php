<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

class StagnantProducts
{
    /** @return Builder<Product> */
    public function query(Store $store): Builder
    {
        return Product::query()->where('store_id', $store->id)->where('performance', Product::STAGNANT);
    }

    /** @return array{total: int, stock: int, stock_value: float} */
    public function summary(Store $store): array
    {
        $totals = $this->query($store)->selectRaw('COUNT(*) as total, COALESCE(SUM(stock), 0) as stock, COALESCE(SUM(stock * price), 0) as stock_value')->firstOrFail();

        return ['total' => (int) $totals->getAttribute('total'), 'stock' => (int) $totals->stock, 'stock_value' => (float) $totals->getAttribute('stock_value')];
    }

    /** @return array<string, mixed> */
    public function product(Product $product): array
    {
        return [...$product->only(['id', 'name', 'sku', 'category', 'image_url', 'price', 'stock', 'units_sold', 'turnover_days', 'performance_score']),
            'recovery' => $product->recovery?->presentation()];
    }

    /** @return array<string, mixed> */
    public function dashboard(Store $store): array
    {
        return [...$this->summary($store), 'products' => $this->query($store)->with('recovery')
            ->orderByDesc('stock')->orderBy('performance_score')->orderBy('id')->limit(4)->get()->map(fn (Product $p) => $this->product($p))];
    }
}
