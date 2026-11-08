<?php

use App\Http\Controllers\Store\ActivityController;
use App\Http\Controllers\Store\BalanceController;
use App\Http\Controllers\Store\CalendarController;
use App\Http\Controllers\Store\CompetitorController;
use App\Http\Controllers\Store\MarketController;
use App\Http\Controllers\Store\NotificationController;
use App\Http\Controllers\Store\ReportController;
use Illuminate\Support\Facades\Route;

/*
| Market trends, competitors, catalog balance, calendar, reports — prefix /store, name store.*
*/

Route::get('market', [MarketController::class, 'index'])->name('market');
Route::post('market/trends/refresh', [MarketController::class, 'refreshTrends'])->name('market.refresh');
Route::post('market/reviews/analyze', [MarketController::class, 'analyzeReviews'])->name('market.analyze');

Route::get('competitors', [CompetitorController::class, 'index'])->name('competitors.index');
Route::post('competitors', [CompetitorController::class, 'store'])->name('competitors.store');
Route::post('competitors/scan', [CompetitorController::class, 'scanAll'])->name('competitors.scan-all');
Route::put('competitors/social-settings', [CompetitorController::class, 'updateSocialSettings'])->name('competitors.social-settings');
Route::put('competitors/{competitor}', [CompetitorController::class, 'update'])->name('competitors.update');
Route::delete('competitors/{competitor}', [CompetitorController::class, 'destroy'])->name('competitors.destroy');
Route::post('competitors/{competitor}/scan', [CompetitorController::class, 'scan'])->name('competitors.scan');

Route::get('balance', [BalanceController::class, 'index'])->name('balance');

Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
Route::post('calendar', [CalendarController::class, 'store'])->name('calendar.store');
Route::put('calendar/{event}', [CalendarController::class, 'update'])->name('calendar.update');
Route::delete('calendar/{event}', [CalendarController::class, 'destroy'])->name('calendar.destroy');

Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('reports/{type}/export', [ReportController::class, 'export'])
    ->whereIn('type', ['performance', 'market', 'evaluations', 'balance'])
    ->name('reports.export');

Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
