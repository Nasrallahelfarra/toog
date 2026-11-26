<?php

namespace App\Http\Controllers\Store\Concerns;

use App\Http\Controllers\Store\TeamController;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogBrowser;
use App\Services\Team\TeamReport;
use App\Support\RevenueVisibility;
use Illuminate\Http\Request;

/**
 * The "products of one member" page, shared by the member's own view and
 * the owner's view of that member.
 */
trait ShowsMemberProducts
{
    /**
     * @return array<string, mixed>
     */
    protected function memberProductsProps(Request $request, Store $store, User $member, bool $asOwner): array
    {
        $report = app(TeamReport::class);
        // How the products sell is the store manager's to see. A staff
        // member gets their list of products and nothing about its sales.
        [$months, $month, $available] = $asOwner ? TeamController::period($request, $store, app(CatalogBrowser::class)) : [12, null, []];
        $sort = $asOwner && $request->input('sort') === 'units' ? 'units' : 'newest';
        $tab = $request->input('tab') === 'add' ? 'add' : 'mine';
        $filters = $request->only(['search', 'category']);
        // The manager's view: only the products created in one month.
        if ($asOwner && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('uploaded'))) {
            $filters['uploaded'] = (string) $request->input('uploaded');
        }
        // The owner can move a product from another member, so they always
        // see taken products. A member sees them only when searching, to
        // learn who holds the product they were looking for.
        $withTaken = $asOwner || filled($filters['search'] ?? null);

        return [
            'member' => ['id' => $member->id, 'name' => $member->name],
            'asOwner' => $asOwner,
            'months' => $months,
            'month' => $month,
            'availableMonths' => $available,
            'tab' => $tab,
            'filters' => $filters,
            'sort' => $sort,
            'stats' => fn () => $asOwner ? RevenueVisibility::apply($store, $report->member($store, $member, $months, $month)) : null,
            'counts' => fn () => $report->counts($store, $member),
            'uploadSummary' => fn () => isset($filters['uploaded']) ? $report->uploadSummary($store, $member, $filters['uploaded']) : null,
            // Months to pick from for "brought in": the last year.
            'uploadMonths' => $asOwner ? array_map(fn (int $i) => now()->startOfMonth()->subMonths($i)->format('Y-m'), range(0, 11)) : [],
            'products' => fn () => RevenueVisibility::apply($store, $report->products($store, $member, $tab, $filters, $months, $month, $withTaken, withSales: $asOwner, sort: $sort)),
        ];
    }

    /**
     * @return list<int>
     */
    protected function productIds(Request $request): array
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        return array_values(array_unique(array_map('intval', $data['ids'])));
    }
}
