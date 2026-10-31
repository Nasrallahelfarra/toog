<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Catalog\BalanceService;
use App\Support\CurrentStore;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "توازن الكتالوج" (module 5): diversity, proven vs novel, launch pace, alerts.
 */
class BalanceController extends Controller
{
    public function index(CurrentStore $current, BalanceService $balance): Response
    {
        $store = $current->require();

        return Inertia::render('store/balance', [
            'analysis' => $balance->analyze($store),
            'repetitiveBelow' => BalanceService::REPETITIVE_BELOW,
        ]);
    }
}
