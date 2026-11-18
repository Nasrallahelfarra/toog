<?php

use App\Http\Controllers\StoreSwitchController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::post('locale/{locale}', [StoreSwitchController::class, 'locale'])->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [StoreSwitchController::class, 'home'])->name('dashboard');
    Route::post('stores/{store}/enter', [StoreSwitchController::class, 'enter'])->name('stores.enter');
    Route::post('stores/exit', [StoreSwitchController::class, 'exit'])->name('stores.exit');
});

// Platform admin panel
Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(base_path('routes/admin.php'));

// Store dashboard (the owner, staff inside the sections the owner granted
// them, or the admin acting inside a store)
Route::middleware(['auth', 'verified', 'store', 'store.access'])
    ->prefix('store')
    ->name('store.')
    ->group(function () {
        require base_path('routes/store/data.php');
        require base_path('routes/store/evaluations.php');
        require base_path('routes/store/market.php');
        require base_path('routes/store/team.php');
    });

require __DIR__.'/settings.php';
