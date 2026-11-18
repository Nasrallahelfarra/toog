<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\Concerns\ShowsMemberProducts;
use App\Services\Team\ProductAssignments;
use App\Support\CurrentStore;
use App\Support\StoreAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A staff member's own products: the ones they added to the store and
 * look after, and how each sells month by month. A product another
 * member holds cannot be taken.
 */
class MyProductsController extends Controller
{
    use ShowsMemberProducts;

    public function __construct(private readonly CurrentStore $current, private readonly StoreAccess $access) {}

    public function index(Request $request): Response|RedirectResponse
    {
        // Owners follow products per member, from the team page.
        if (! $this->access->isStaff()) {
            return to_route('store.team.index');
        }

        return Inertia::render('store/my-products', $this->memberProductsProps($request, $this->current->require(), $request->user(), asOwner: false));
    }

    public function claim(Request $request, ProductAssignments $assignments): RedirectResponse
    {
        abort_unless($this->access->isStaff(), 403);

        $result = $assignments->claim($this->current->require(), $request->user(), $this->productIds($request));

        if ($result['claimed'] === 0) {
            throw ValidationException::withMessages(['ids' => $result['taken'] > 0
                ? __('mojo.product_taken', ['name' => $result['holder'] ?? '—'])
                : __('mojo.products_none_claimed')]);
        }

        Inertia::flash('toast', $result['taken'] > 0
            ? ['type' => 'warning', 'message' => __('mojo.products_claimed_partial', ['count' => $result['claimed'], 'taken' => $result['taken']])]
            : ['type' => 'success', 'message' => trans_choice('mojo.products_claimed', $result['claimed'], ['count' => $result['claimed']])]);

        return back();
    }

    public function release(Request $request, ProductAssignments $assignments): RedirectResponse
    {
        abort_unless($this->access->isStaff(), 403);

        $released = $assignments->release($this->current->require(), $request->user(), $this->productIds($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('mojo.products_released', $released, ['count' => $released])]);

        return back();
    }
}
