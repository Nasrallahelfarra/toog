<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Store;
use App\Services\Analytics\PerformanceClassifier;
use Illuminate\Database\Eloquent\Builder;

class StagnantProducts
{
    /** @return Builder<Product> */
    public function query(Store $store): Builder
    {
        return Product::query()->where('store_id', $store->id)->whereIn('performance', [Product::STAGNANT, Product::AT_RISK]);
    }

    /** @return array<string, mixed> */
    public function summary(Store $store): array
    {
        $totals = $this->query($store)->selectRaw('COUNT(*) as total, SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS stagnant, SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS at_risk, COALESCE(SUM(stock), 0) as stock, COALESCE(SUM(stock * price), 0) as stock_value', [Product::STAGNANT, Product::AT_RISK])->firstOrFail();

        return ['total' => (int) $totals->getAttribute('total'), 'stock' => (int) $totals->stock, 'stock_value' => (float) $totals->getAttribute('stock_value'),
            'stagnant' => (int) $totals->getAttribute('stagnant'), 'at_risk' => (int) $totals->getAttribute('at_risk'),
            'needs_reclassification' => ($store->settings['performance_classification_version'] ?? 0) < PerformanceClassifier::VERSION && $store->products()->whereNotNull('performance')->exists(),
            'unclassified' => $store->products()->whereNull('performance')->count()];
    }

    /** @return array<string, mixed> */
    public function product(Product $product): array
    {
        return [...$product->only(['id', 'name', 'sku', 'category', 'image_url', 'price', 'stock', 'units_sold', 'turnover_days', 'performance_score', 'performance']),
            'classification_reason' => $product->performanceReason(),
            'recovery' => $product->recovery?->presentation()];
    }

    /** @return array<string, mixed> */
    public function dashboard(Store $store): array
    {
        return [...$this->summary($store), 'products' => $this->query($store)->with('recovery')
            ->orderByDesc('stock')->orderBy('performance_score')->orderBy('id')->limit(4)->get()->map(fn (Product $p) => $this->product($p))];
    }
}
