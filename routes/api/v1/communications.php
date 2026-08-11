<?php

declare(strict_types=1);

use App\Modules\Communications\Http\Controllers\Api\V1\Admin\CommunicationAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('communications')
  ->name('communications.')
  ->middleware(['auth:sanctum'])
  ->group(function (): void {
    Route::get('/settings', [CommunicationAdminController::class, 'settingsShow'])->name('settings.show');
    Route::put('/settings', [CommunicationAdminController::class, 'settingsUpdate'])->name('settings.update');

    Route::get('/routes', [CommunicationAdminController::class, 'routesIndex'])->name('routes.index');
    Route::post('/routes', [CommunicationAdminController::class, 'routesStore'])->name('routes.store');
    Route::put('/routes/{route:uuid}', [CommunicationAdminController::class, 'routesUpdate'])->name('routes.update');
    Route::delete('/routes/{route:uuid}', [CommunicationAdminController::class, 'routesDestroy'])->name('routes.destroy');

    Route::get('/templates', [CommunicationAdminController::class, 'templatesIndex'])->name('templates.index');
    Route::get('/templates/{template:uuid}', [CommunicationAdminController::class, 'templatesShow'])->name('templates.show');
    Route::post('/templates', [CommunicationAdminController::class, 'templatesStore'])->name('templates.store');
    Route::put('/templates/{template:uuid}', [CommunicationAdminController::class, 'templatesUpdate'])->name('templates.update');
    Route::post('/templates/{template:uuid}/duplicate', [CommunicationAdminController::class, 'templatesDuplicate'])->name('templates.duplicate');
    Route::post('/templates/{template:uuid}/reset', [CommunicationAdminController::class, 'templatesReset'])->name('templates.reset');
    Route::post('/templates/{template:uuid}/preview', [CommunicationAdminController::class, 'templatesPreview'])->name('templates.preview');
    Route::post('/templates/{template:uuid}/test-send', [CommunicationAdminController::class, 'templatesTestSend'])->name('templates.test-send');

    Route::get('/logs', [CommunicationAdminController::class, 'logsIndex'])->name('logs.index');
    Route::get('/logs/{log:uuid}', [CommunicationAdminController::class, 'logsShow'])->name('logs.show');
  });
