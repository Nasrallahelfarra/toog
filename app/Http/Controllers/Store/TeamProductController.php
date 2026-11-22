<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\Concerns\ShowsMemberProducts;
use App\Services\Team\ProductAssignments;
use App\Support\CurrentStore;
use App\Support\StoreSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's view of one member's products. The owner can give the
 * member any product, taking it from whoever holds it, and take products
 * back.
 */
class TeamProductController extends Controller
{
    use ShowsMemberProducts;

    public function __construct(private readonly CurrentStore $current) {}

    public function show(Request $request, int $member): Response
    {
        $store = $this->current->require();

        $user = TeamController::member($store, $member);

        return Inertia::render('store/team/show', [
            ...$this->memberProductsProps($request, $store, $user, asOwner: true),
            // The owner changes the member's pages from here too.
            'pages' => [
                'granted' => StoreSections::stored($user->pivot->permissions),
                'groups' => StoreSections::GROUPS,
            ],
        ]);
    }

    public function assign(Request $request, int $member, ProductAssignments $assignments): RedirectResponse
    {
        $store = $this->current->require();
        $user = TeamController::member($store, $member);
        $moved = $assignments->assign($store, $user, $this->productIds($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('mojo.products_assigned', $moved, ['count' => $moved, 'name' => $user->name])]);

        return back();
    }

    public function release(Request $request, int $member, ProductAssignments $assignments): RedirectResponse
    {
        $store = $this->current->require();
        $released = $assignments->release($store, TeamController::member($store, $member), $this->productIds($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('mojo.products_released', $released, ['count' => $released])]);

        return back();
    }
}
