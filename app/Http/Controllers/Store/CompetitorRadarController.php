<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\CompetitorProduct;
use App\Models\CompetitorReading;
use App\Services\Market\CompetitorRadar;
use App\Services\Market\CompetitorReader;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Actions on the competitor radar: the AI reading of what engages, and
 * watching a competitor product.
 */
class CompetitorRadarController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function read(Request $request, CompetitorReader $reader): RedirectResponse
    {
        $store = $this->current->require();
        $days = in_array($request->integer('days'), CompetitorRadar::WINDOWS, true) ? $request->integer('days') : 30;

        $reading = $reader->read($store, $days, $request->user()->id);

        Activity::log('competitor.radar_read', $store, ['days' => $days]);
        Inertia::flash('toast', $reading === null
            ? ['type' => 'info', 'message' => __('market.radar_nothing_to_read')]
            : ($reading->from_model
                ? ['type' => 'success', 'message' => __('market.radar_read')]
                : ['type' => 'error', 'message' => __('market.radar_read_failed')]));

        return back();
    }

    /**
     * Watch a competitor product (or stop watching it); watched products
     * feed the radar's alerts.
     */
    public function watch(CompetitorProduct $product): RedirectResponse
    {
        abort_unless($product->store_id === $this->current->id(), 404);

        $product->update(['watched_at' => $product->watched_at ? null : now()]);

        return back();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function reading(int $storeId, int $days): ?array
    {
        $reading = CompetitorReading::query()->where('store_id', $storeId)->where('days', $days)->latest('id')->first();

        return $reading ? [
            ...$reading->report,
            'from_model' => $reading->from_model,
            'created_at' => $reading->created_at?->toIso8601String(),
        ] : null;
    }
}
