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
        // Counted by sales alone: how many never sold in the year measured,
        // and the longest any of them has gone without a sale.
        $totals = $this->query($store)->selectRaw('COUNT(*) as total, SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS stagnant, SUM(CASE WHEN performance = ? THEN 1 ELSE 0 END) AS at_risk, SUM(CASE WHEN units_sold = 0 THEN 1 ELSE 0 END) AS never_sold, MAX(no_sales_days) AS longest_quiet', [Product::STAGNANT, Product::AT_RISK])->firstOrFail();

        return ['total' => (int) $totals->getAttribute('total'), 'never_sold' => (int) $totals->getAttribute('never_sold'),
            'longest_quiet' => $totals->getAttribute('longest_quiet') !== null ? (int) $totals->getAttribute('longest_quiet') : null,
            'stagnant' => (int) $totals->getAttribute('stagnant'), 'at_risk' => (int) $totals->getAttribute('at_risk'),
            'needs_reclassification' => ($store->settings['performance_classification_version'] ?? 0) < PerformanceClassifier::VERSION && $store->products()->whereNotNull('performance')->exists(),
            'unclassified' => $store->products()->whereNull('performance')->count()];
    }

    /** @return array<string, mixed> */
    public function product(Product $product): array
    {
        return [...$product->only(['id', 'name', 'sku', 'category', 'image_url', 'price', 'units_sold', 'no_sales_days', 'performance_score', 'performance']),
            'classification_reason' => $product->performanceReason(),
            'recovery' => $product->recovery?->presentation()];
    }

    /** @return array<string, mixed> */
    public function dashboard(Store $store): array
    {
        return [...$this->summary($store), 'products' => $this->query($store)->with('recovery')
            ->orderByRaw('COALESCE(no_sales_days, 0) DESC')->orderBy('performance_score')->orderBy('id')->limit(4)->get()->map(fn (Product $p) => $this->product($p))];
    }
}
