<?php

use App\Jobs\AnalyzeReviews;
use App\Jobs\CheckBalance;
use App\Jobs\ScanCompetitors;
use App\Models\Store;
use Illuminate\Support\Facades\Schedule;

/*
| Market monitoring (module 2) and catalog balance alerts (module 5).
| ScanCompetitors also queues trend detection once the scan finishes.
*/

$forActiveStores = fn (callable $dispatch) => fn () => Store::query()
    ->where('status', Store::STATUS_ACTIVE)
    ->pluck('id')
    ->each($dispatch);

Schedule::call($forActiveStores(fn (int $id) => ScanCompetitors::dispatch($id)))
    ->dailyAt('03:00')
    ->name('market:scan-competitors')
    ->withoutOverlapping();

Schedule::call($forActiveStores(fn (int $id) => AnalyzeReviews::dispatch($id)))
    ->dailyAt('04:30')
    ->name('market:analyze-reviews')
    ->withoutOverlapping();

Schedule::call($forActiveStores(fn (int $id) => CheckBalance::dispatch($id)))
    ->dailyAt('05:00')
    ->name('catalog:check-balance')
    ->withoutOverlapping();
