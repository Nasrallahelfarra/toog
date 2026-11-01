<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Catalog\DemoCatalogCleanup;
use App\Services\Catalog\DemoCatalogCleanupException;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class DemoCatalogController extends Controller
{
    public function destroy(Request $request, CurrentStore $current, DemoCatalogCleanup $cleanup): RedirectResponse
    {
        abort_unless(config('mojo.demo_catalog_cleanup_enabled'), 404);
        $store = $current->require();
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->id === $store->owner_id, 403);
        $data = $request->validate(['preview_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);

        try {
            $result = $cleanup->run($store, apply: true, actor: $user, previewToken: $data['preview_token']);
        } catch (DemoCatalogCleanupException $e) {
            throw ValidationException::withMessages(['preview_token' => __('store.demo_cleanup_errors.'.$e->reason)]);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['preview_token' => __('store.demo_cleanup_errors.failed')]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('store.'.($result['deleted'] ? 'demo_cleanup_success' : 'demo_cleanup_empty'), [
                'products' => $result['products_count'], 'sales' => $result['sales_count'],
            ]),
        ]);

        return to_route('store.dashboard');
    }
}
