<?php

use App\Http\Controllers\Store\MyProductsController;
use App\Http\Controllers\Store\TeamController;
use App\Http\Controllers\Store\TeamProductController;
use Illuminate\Support\Facades\Route;

/*
| The store team — prefix /store, name store.*
|
| team.* manages the staff (section "team"); team.report, team.export and a
| member's page show how their products sell ("team_report"). The owner
| holds both and can give them to a member. my-products.* is each staff
| member's own list.
| Who may open what is decided in App\Support\StoreSections.
*/

Route::get('team', [TeamController::class, 'index'])->name('team.index');
// How each member's products sell, side by side
Route::get('team/report', [TeamController::class, 'report'])->name('team.report');
Route::post('team', [TeamController::class, 'store'])->name('team.store');
// The comparison as a printable page the browser saves to PDF
Route::get('team/export', [TeamController::class, 'export'])->name('team.export');
Route::put('team/{member}', [TeamController::class, 'update'])->whereNumber('member')->name('team.update');
// The pages a member may open, changed on their own
Route::put('team/{member}/permissions', [TeamController::class, 'permissions'])->whereNumber('member')->name('team.permissions');
Route::put('team/{member}/password', [TeamController::class, 'password'])->whereNumber('member')->name('team.password');
Route::post('team/{member}/disable', [TeamController::class, 'disable'])->whereNumber('member')->name('team.disable');
Route::post('team/{member}/enable', [TeamController::class, 'enable'])->whereNumber('member')->name('team.enable');
Route::delete('team/{member}/two-factor', [TeamController::class, 'resetTwoFactor'])->whereNumber('member')->name('team.two-factor.reset');
// The owner's switch: staff must sign in with an authenticator app code
Route::put('team/security', [TeamController::class, 'security'])->name('team.security');
Route::delete('team/{member}', [TeamController::class, 'destroy'])->whereNumber('member')->name('team.destroy');

Route::get('team/{member}', [TeamProductController::class, 'show'])->whereNumber('member')->name('team.products');
Route::post('team/{member}/products', [TeamProductController::class, 'assign'])->whereNumber('member')->name('team.products.assign');
Route::delete('team/{member}/products', [TeamProductController::class, 'release'])->whereNumber('member')->name('team.products.release');

Route::get('my-products', [MyProductsController::class, 'index'])->name('my-products.index');
Route::post('my-products', [MyProductsController::class, 'claim'])->name('my-products.claim');
Route::delete('my-products', [MyProductsController::class, 'release'])->name('my-products.release');
