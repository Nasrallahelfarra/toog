<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AiCostController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SeasonalEventController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
| Platform admin panel — prefix /admin, name admin.*, middleware auth+admin.
*/

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
Route::get('stores/create', [StoreController::class, 'create'])->name('stores.create');
Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
Route::get('stores/{store}', [StoreController::class, 'show'])->name('stores.show');
Route::put('stores/{store}', [StoreController::class, 'update'])->name('stores.update');
Route::post('stores/{store}/suspend', [StoreController::class, 'suspend'])->name('stores.suspend');
Route::post('stores/{store}/activate', [StoreController::class, 'activate'])->name('stores.activate');

Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::post('users', [UserController::class, 'store'])->name('users.store');
Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
Route::post('users/{user}/disable', [UserController::class, 'disable'])->name('users.disable');
Route::post('users/{user}/enable', [UserController::class, 'enable'])->name('users.enable');
Route::put('users/{user}/password', [UserController::class, 'password'])->name('users.password');
Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

Route::get('ai-costs', [AiCostController::class, 'index'])->name('ai-costs.index');
Route::get('ai-costs/export', [AiCostController::class, 'export'])->name('ai-costs.export');

Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('settings/events', [SeasonalEventController::class, 'store'])->name('events.store');
Route::put('settings/events/{event}', [SeasonalEventController::class, 'update'])->name('events.update');
Route::delete('settings/events/{event}', [SeasonalEventController::class, 'destroy'])->name('events.destroy');
