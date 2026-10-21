<?php

declare(strict_types=1);

use App\Modules\Communications\Http\Controllers\AnnouncementController;
use App\Modules\Communications\Http\Controllers\Api\V1\Admin\CommunicationAdminController;
use App\Modules\Communications\Http\Controllers\BulkEmailController;
use App\Modules\Communications\Http\Controllers\CommunicationComposeController;
use App\Modules\Communications\Http\Controllers\CommunicationConsentController;
use App\Modules\Communications\Http\Controllers\CommunicationProviderController;
use App\Modules\Communications\Http\Controllers\CommunicationWebhookController;
use App\Modules\Communications\Http\Controllers\WhatsAppTemplateController;
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
    Route::get('/event-keys', [CommunicationAdminController::class, 'eventKeys'])->name('event-keys');
    Route::get('/health', [CommunicationAdminController::class, 'health'])->name('health');

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
    Route::get('/activity', [CommunicationComposeController::class, 'activity'])->name('activity');
    Route::get('/audience-modules', [CommunicationComposeController::class, 'modules'])->name('audience-modules');
    Route::get('/recipients/search', [CommunicationComposeController::class, 'search'])->name('recipients.search');
    Route::post('/send', [CommunicationComposeController::class, 'sendSingle'])->middleware('throttle:30,1')->name('send');
    Route::post('/channels/test', [CommunicationComposeController::class, 'testChannel'])->middleware('throttle:10,1')->name('channels.test');

    Route::get('/providers', [CommunicationProviderController::class, 'index'])->name('providers.index');
    Route::post('/providers/{channel}/check', [CommunicationProviderController::class, 'check'])->middleware('throttle:10,1')->name('providers.check');

    Route::get('/whatsapp-templates', [WhatsAppTemplateController::class, 'index'])->name('whatsapp-templates.index');
    Route::post('/whatsapp-templates', [WhatsAppTemplateController::class, 'store'])->name('whatsapp-templates.store');
    Route::post('/whatsapp-templates/sync', [WhatsAppTemplateController::class, 'sync'])->middleware('throttle:5,1')->name('whatsapp-templates.sync');
    Route::put('/whatsapp-templates/{template:uuid}', [WhatsAppTemplateController::class, 'update'])->name('whatsapp-templates.update');
    Route::delete('/whatsapp-templates/{template:uuid}', [WhatsAppTemplateController::class, 'destroy'])->name('whatsapp-templates.destroy');

    Route::get('/consents', [CommunicationConsentController::class, 'index'])->name('consents.index');
    Route::post('/consents', [CommunicationConsentController::class, 'store'])->middleware('throttle:60,1')->name('consents.store');
    Route::get('/consents/{consent:uuid}', [CommunicationConsentController::class, 'history'])->name('consents.history');

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
      Route::post('/estimate', [BulkEmailController::class, 'estimate'])->middleware('throttle:60,1')->name('estimate');
      Route::post('/render-preview', [BulkEmailController::class, 'renderPreview'])->middleware('throttle:60,1')->name('render-preview');
      Route::post('/test-send', [BulkEmailController::class, 'testSend'])->middleware('throttle:10,1')->name('test-send');
      Route::post('/', [BulkEmailController::class, 'store'])->middleware('throttle:20,1')->name('store');
      Route::get('/{bulkEmailJob:uuid}', [BulkEmailController::class, 'show'])->name('show');
      Route::put('/{bulkEmailJob:uuid}', [BulkEmailController::class, 'update'])->name('update');
      Route::post('/{bulkEmailJob:uuid}/send', [BulkEmailController::class, 'send'])->middleware('throttle:20,1')->name('send');
      Route::get('/{bulkEmailJob:uuid}/recipients', [BulkEmailController::class, 'recipients'])->name('recipients');
      Route::get('/{bulkEmailJob:uuid}/export', [BulkEmailController::class, 'export'])->middleware('throttle:10,1')->name('export');
      Route::post('/{bulkEmailJob:uuid}/cancel', [BulkEmailController::class, 'cancel'])->name('cancel');
      Route::post('/{bulkEmailJob:uuid}/resume', [BulkEmailController::class, 'resume'])->middleware('throttle:10,1')->name('resume');
    });
  });

// ── Public: provider callbacks (signature-verified) and unsubscribe ─────────
Route::prefix('communications')
  ->name('communications.public.')
  ->middleware('throttle:600,1')
  ->group(function (): void {
    Route::post('/webhooks/twilio', [CommunicationWebhookController::class, 'twilio'])->name('webhooks.twilio');
    Route::post('/webhooks/termii', [CommunicationWebhookController::class, 'termii'])->name('webhooks.termii');
    Route::get('/webhooks/meta', [CommunicationWebhookController::class, 'metaVerify'])->name('webhooks.meta.verify');
    Route::post('/webhooks/meta', [CommunicationWebhookController::class, 'meta'])->name('webhooks.meta');
    Route::match(['get', 'post'], '/unsubscribe', [CommunicationWebhookController::class, 'unsubscribe'])->name('unsubscribe');
  });

// ── Public: active announcements ─────────────────────────────────────────────
Route::prefix('public/announcements')
  ->name('public.announcements.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/', [AnnouncementController::class, 'publicIndex'])->name('index');
  });
