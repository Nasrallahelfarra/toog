<?php

use App\Http\Controllers\Store\CatalogController;
use App\Http\Controllers\Store\DashboardController;
use App\Http\Controllers\Store\DemoCatalogController;
use App\Http\Controllers\Store\ImportController;
use App\Http\Controllers\Store\IntegrationController;
use App\Http\Controllers\Store\PerformanceController;
use App\Http\Controllers\Store\ProductController;
use App\Http\Controllers\Store\RevenueAuditController;
use App\Http\Controllers\Store\SettingsController;
use App\Http\Controllers\Store\StagnantProductController;
use App\Support\CurrentStore;
use Illuminate\Support\Facades\Route;

/*
| Store data, performance, dashboard and settings — prefix /store, name store.*
*/

Route::get('/', DashboardController::class)->name('dashboard');
Route::post('catalog/clear-demo', [DemoCatalogController::class, 'destroy'])->name('catalog.clear-demo');

// Module 1 — store data & sales analysis
// Catalog browser — categories, their products and sales
Route::get('catalog', [CatalogController::class, 'index'])->name('catalog');
Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');

Route::get('performance', [PerformanceController::class, 'index'])->name('performance');
Route::get('stagnant', [StagnantProductController::class, 'index'])->name('stagnant.index');
Route::post('stagnant/{product}/recommendation', [StagnantProductController::class, 'recommend'])->middleware('throttle:20,1')->name('stagnant.recommend');
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
// Revenue audit: one month of Zid orders against what the store sees here
Route::get('settings/integration/revenue-audit', [RevenueAuditController::class, 'show'])->name('settings.revenue-audit');
Route::post('settings/integration/revenue-audit', [RevenueAuditController::class, 'store'])->middleware('throttle:10,1')->name('settings.revenue-audit.run');
Route::get('integrations/zid/redirect', [IntegrationController::class, 'redirect'])->name('integrations.zid.redirect');
Route::get('integrations/zid/callback', [IntegrationController::class, 'callback'])->name('integrations.zid.callback');
Route::delete('integrations/zid', [IntegrationController::class, 'disconnect'])->name('integrations.zid.disconnect');
Route::post('integrations/zid/sync', [IntegrationController::class, 'sync'])->name('integrations.zid.sync');
Route::post('integrations/zid/sync/{run}/resume', [IntegrationController::class, 'resume'])->whereNumber('run')->name('integrations.zid.sync.resume');
Route::post('integrations/zid/check', [IntegrationController::class, 'check'])->name('integrations.zid.check');
