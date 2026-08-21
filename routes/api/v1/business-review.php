<?php

declare(strict_types=1);

use App\Modules\BusinessReview\Http\Controllers\Api\V1\Admin\BusinessReviewAdminController;
use App\Modules\BusinessReview\Http\Controllers\Api\V1\Public\BusinessReviewPublicController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/forms')
    ->name('public.forms.')
    ->middleware('throttle:10,1')
    ->group(function (): void {
        Route::post('/business-review', [BusinessReviewPublicController::class, 'store'])
            ->name('business-review.store');
    });

Route::prefix('business-review')
    ->name('business-review.')
    ->middleware(['auth:sanctum'])
    ->group(function (): void {
        Route::get('/', [BusinessReviewAdminController::class, 'index'])->name('index');
        Route::get('/export', [BusinessReviewAdminController::class, 'export'])->name('export');
        Route::get('/assignees', [BusinessReviewAdminController::class, 'assignees'])->name('assignees');
        Route::get('/{businessReview}', [BusinessReviewAdminController::class, 'show'])->name('show');
        Route::patch('/{businessReview}/status', [BusinessReviewAdminController::class, 'updateStatus'])->name('status');
        Route::patch('/{businessReview}/assign', [BusinessReviewAdminController::class, 'assign'])->name('assign');
        Route::post('/{businessReview}/notes', [BusinessReviewAdminController::class, 'addNote'])->name('notes.store');
        Route::post('/{businessReview}/conversation', [BusinessReviewAdminController::class, 'openConversation'])->name('conversation');
    });
