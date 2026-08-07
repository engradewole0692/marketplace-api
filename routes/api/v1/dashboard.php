<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
  ->prefix('dashboard')
  ->name('dashboard.')
  ->group(function (): void {
    Route::get('/overview', [DashboardController::class, 'overview'])->name('overview');
    Route::get('/activity', [DashboardController::class, 'activity'])->name('activity');
    Route::get('/search', [DashboardController::class, 'search'])->name('search');
  });
