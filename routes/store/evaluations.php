<?php

use App\Http\Controllers\Store\DesignController;
use App\Http\Controllers\Store\DesignImprovementController;
use App\Http\Controllers\Store\EvaluationBatchController;
use App\Http\Controllers\Store\EvaluationController;
use App\Http\Controllers\Store\StudioController;
use Illuminate\Support\Facades\Route;

/*
| Evaluations (module 3) and design studio (module 4) — prefix /store, name store.*
*/

Route::get('evaluations', [EvaluationController::class, 'index'])->name('evaluations.index');
Route::get('evaluations/create', [EvaluationController::class, 'create'])->name('evaluations.create');
Route::post('evaluations', [EvaluationController::class, 'store'])->name('evaluations.store');
Route::get('evaluations/{evaluation}', [EvaluationController::class, 'show'])->name('evaluations.show');
Route::get('evaluations/{evaluation}/compare', [EvaluationController::class, 'compare'])->name('evaluations.compare');
Route::post('evaluations/{evaluation}/decide', [EvaluationController::class, 'decide'])->name('evaluations.decide');
Route::post('evaluations/{evaluation}/retry', [EvaluationController::class, 'retry'])->name('evaluations.retry');
Route::post('evaluations/{evaluation}/improvements', [DesignImprovementController::class, 'store'])->name('improvements.store');
Route::post('improvements/{improvement}/retry', [DesignImprovementController::class, 'retry'])->name('improvements.retry');
Route::post('improvements/{improvement}/regenerate', [DesignImprovementController::class, 'regenerate'])->name('improvements.regenerate');
Route::post('improvements/{improvement}/approve', [DesignImprovementController::class, 'approve'])->name('improvements.approve');
Route::delete('evaluations/{evaluation}', [EvaluationController::class, 'destroy'])->name('evaluations.destroy');

Route::get('batches/create', [EvaluationBatchController::class, 'create'])->name('batches.create');
Route::post('batches', [EvaluationBatchController::class, 'store'])->name('batches.store');
Route::get('batches/{batch}', [EvaluationBatchController::class, 'show'])->name('batches.show');

Route::get('studio', [StudioController::class, 'index'])->name('studio.index');
Route::post('studio', [StudioController::class, 'store'])->name('studio.store');
Route::post('studio/{designRequest}/retry', [StudioController::class, 'retry'])->name('studio.retry');

Route::get('library', [DesignController::class, 'index'])->name('library.index');
Route::patch('designs/{design}', [DesignController::class, 'update'])->name('designs.update');
Route::post('designs/{design}/refine', [StudioController::class, 'refine'])->name('designs.refine');
Route::get('designs/{design}/download', [DesignController::class, 'download'])->name('designs.download');
Route::delete('designs/{design}', [DesignController::class, 'destroy'])->name('designs.destroy');
