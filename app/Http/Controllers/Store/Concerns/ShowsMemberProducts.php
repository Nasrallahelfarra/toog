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
        [$months, $month, $available] = TeamController::period($request, $store, app(CatalogBrowser::class));
        $tab = $request->input('tab') === 'add' ? 'add' : 'mine';
        $filters = $request->only(['search', 'category']);
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
            'stats' => fn () => RevenueVisibility::apply($store, $report->member($store, $member, $months, $month)),
            'counts' => fn () => $report->counts($store, $member),
            'products' => fn () => RevenueVisibility::apply($store, $report->products($store, $member, $tab, $filters, $months, $month, $withTaken)),
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
