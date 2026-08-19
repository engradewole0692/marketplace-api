<?php

declare(strict_types=1);

use App\Modules\Communications\Http\Controllers\AnnouncementController;
use App\Modules\Communications\Http\Controllers\Api\V1\Admin\CommunicationAdminController;
use App\Modules\Communications\Http\Controllers\BulkEmailController;
use App\Modules\Communications\Http\Controllers\MessagingController;
use App\Modules\Communications\Http\Controllers\NotificationController;
use App\Modules\Communications\Models\PlatformConversation;
use App\Modules\Communications\Models\PlatformMessage;
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

    // ── Notifications (in-app bell) ───────────────────────────────────────
    Route::prefix('notifications')->name('notifications.')->group(function (): void {
      Route::get('/', [NotificationController::class, 'index'])->name('index');
      Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
      Route::post('/send', [NotificationController::class, 'send'])->name('send');
      Route::post('/{uuid}/read', [NotificationController::class, 'markRead'])->name('read');
      Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
    });

    // ── Announcements ─────────────────────────────────────────────────────
    Route::prefix('announcements')->name('announcements.')->group(function (): void {
      Route::get('/', [AnnouncementController::class, 'index'])->name('index');
      Route::post('/', [AnnouncementController::class, 'store'])->name('store');
      Route::get('/{announcement:uuid}', [AnnouncementController::class, 'show'])->name('show');
      Route::put('/{announcement:uuid}', [AnnouncementController::class, 'update'])->name('update');
      Route::post('/{announcement:uuid}/publish', [AnnouncementController::class, 'publish'])->name('publish');
      Route::delete('/{announcement:uuid}', [AnnouncementController::class, 'destroy'])->name('destroy');
    });

    // ── In-App Messaging ──────────────────────────────────────────────────
    Route::prefix('conversations')->name('conversations.')->group(function (): void {
      Route::get('/', [MessagingController::class, 'conversations'])->name('index');
      Route::post('/', [MessagingController::class, 'startConversation'])->name('store');
      Route::get('/{conversation:uuid}/messages', [MessagingController::class, 'messages'])->name('messages.index');
      Route::post('/{conversation:uuid}/messages', [MessagingController::class, 'sendMessage'])->name('messages.store');
      Route::delete('/messages/{message:uuid}', [MessagingController::class, 'deleteMessage'])->name('messages.destroy');
    });

    // ── Bulk Email ────────────────────────────────────────────────────────
    Route::prefix('bulk-email')->name('bulk-email.')->group(function (): void {
      Route::get('/', [BulkEmailController::class, 'index'])->name('index');
      Route::post('/estimate', [BulkEmailController::class, 'estimate'])->name('estimate');
      Route::post('/', [BulkEmailController::class, 'store'])->name('store');
      Route::get('/{bulkEmailJob:uuid}', [BulkEmailController::class, 'show'])->name('show');
      Route::post('/{bulkEmailJob:uuid}/cancel', [BulkEmailController::class, 'cancel'])->name('cancel');
    });
  });

// ── Public: active announcements ─────────────────────────────────────────────
Route::prefix('public/announcements')
  ->name('public.announcements.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/', [AnnouncementController::class, 'publicIndex'])->name('index');
  });
