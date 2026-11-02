<?php

use App\Http\Controllers\Store\DashboardController;
use App\Http\Controllers\Store\DemoCatalogController;
use App\Http\Controllers\Store\ImportController;
use App\Http\Controllers\Store\IntegrationController;
use App\Http\Controllers\Store\PerformanceController;
use App\Http\Controllers\Store\SettingsController;
use App\Support\CurrentStore;
use Illuminate\Support\Facades\Route;

/*
| Store data, performance, dashboard and settings — prefix /store, name store.*
*/

Route::get('/', DashboardController::class)->name('dashboard');
Route::post('catalog/clear-demo', [DemoCatalogController::class, 'destroy'])->name('catalog.clear-demo');

// Module 1 — store data & sales analysis
Route::get('performance', [PerformanceController::class, 'index'])->name('performance');
Route::post('performance/reclassify', [PerformanceController::class, 'reclassify'])->name('performance.reclassify');
Route::post('performance/extract-features', [PerformanceController::class, 'extract'])->name('performance.extract');

// Settings
// Open on the Zid connection until the store is connected.
Route::get('settings', fn (CurrentStore $current) => to_route(
    $current->require()->isConnected() ? 'store.settings.general' : 'store.settings.integration',
))->name('settings');
Route::get('settings/general', [SettingsController::class, 'general'])->name('settings.general');
Route::post('settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
Route::get('settings/evaluation', [SettingsController::class, 'evaluation'])->name('settings.evaluation');
Route::put('settings/evaluation', [SettingsController::class, 'updateEvaluation'])->name('settings.evaluation.update');
Route::get('settings/brand', [SettingsController::class, 'brand'])->name('settings.brand');
Route::put('settings/brand', [SettingsController::class, 'updateBrand'])->name('settings.brand.update');

// Integration: Zid + file import
Route::get('settings/integration', [IntegrationController::class, 'show'])->name('settings.integration');
Route::put('settings/integration/frequency', [IntegrationController::class, 'updateFrequency'])->name('settings.integration.frequency');
Route::post('settings/integration/import/{type}', [ImportController::class, 'store'])->whereIn('type', ['products', 'sales'])->name('settings.import');
Route::get('settings/integration/template/{type}', [ImportController::class, 'template'])->whereIn('type', ['products', 'sales'])->name('settings.template');
Route::get('integrations/zid/redirect', [IntegrationController::class, 'redirect'])->name('integrations.zid.redirect');
Route::get('integrations/zid/callback', [IntegrationController::class, 'callback'])->name('integrations.zid.callback');
Route::delete('integrations/zid', [IntegrationController::class, 'disconnect'])->name('integrations.zid.disconnect');
Route::post('integrations/zid/sync', [IntegrationController::class, 'sync'])->name('integrations.zid.sync');
Route::post('integrations/zid/sync/{run}/resume', [IntegrationController::class, 'resume'])->whereNumber('run')->name('integrations.zid.sync.resume');
Route::post('integrations/zid/check', [IntegrationController::class, 'check'])->name('integrations.zid.check');
