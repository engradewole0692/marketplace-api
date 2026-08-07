<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Members\MemberController;
use App\Http\Controllers\Api\V1\Members\MemberInterviewController;
use App\Http\Controllers\Api\V1\Members\MemberLifecycleController;
use App\Http\Controllers\Api\V1\Members\MemberNotificationQueueController;
use App\Http\Controllers\Api\V1\Members\MemberOnboardingController;
use App\Http\Controllers\Api\V1\Members\MemberPhotoController;
use App\Http\Controllers\Api\V1\Members\MemberPortalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
  ->prefix('members')
  ->name('members.')
  ->group(function (): void {
    Route::get('/', [MemberController::class, 'index'])->name('index');
    Route::get('/export', [MemberController::class, 'export'])->name('export');
    Route::post('/', [MemberController::class, 'store'])->name('store');
    Route::post('/bulk', [MemberController::class, 'bulk'])->name('bulk');
    Route::post('/{memberId}/restore', [MemberController::class, 'restore'])->name('restore');
    Route::get('/{member}', [MemberController::class, 'show'])->name('show');
    Route::put('/{member}', [MemberController::class, 'update'])->name('update');
    Route::delete('/{member}', [MemberController::class, 'destroy'])->name('destroy');
    Route::post('/{member}/approve', [MemberController::class, 'approve'])->name('approve');
    Route::post('/{member}/reject', [MemberController::class, 'reject'])->name('reject');
    Route::post('/{member}/transition', [MemberController::class, 'transition'])->name('transition');
    Route::post('/{member}/start-review', [MemberLifecycleController::class, 'startReview'])->name('start-review');
    Route::post('/{member}/require-interview', [MemberLifecycleController::class, 'requireInterview'])->name('require-interview');
    Route::post('/{member}/request-info', [MemberLifecycleController::class, 'requestInfo'])->name('request-info');
    Route::post('/{member}/assign-ministry', [MemberLifecycleController::class, 'assignMinistry'])->name('assign-ministry');
    Route::post('/{member}/complete-orientation', [MemberLifecycleController::class, 'completeOrientation'])->name('complete-orientation');
    Route::post('/{member}/activate', [MemberLifecycleController::class, 'activate'])->name('activate');
    Route::post('/{member}/activate-automation', [MemberLifecycleController::class, 'activateWithAutomation'])->name('activate-automation');
    Route::post('/{member}/photo', [MemberPhotoController::class, 'upload'])->name('photo.upload');
    Route::put('/{member}/photo', [MemberPhotoController::class, 'attach'])->name('photo.attach');
    Route::delete('/{member}/photo', [MemberPhotoController::class, 'destroy'])->name('photo.destroy');
    Route::post('/{member}/interviews', [MemberInterviewController::class, 'store'])->name('interviews.store');
    Route::get('/{member}/onboarding-checklist', [MemberOnboardingController::class, 'index'])->name('onboarding.index');
    Route::put('/{member}/onboarding-checklist/{stepKey}', [MemberOnboardingController::class, 'updateStep'])->name('onboarding.update');
    Route::get('/{member}/timeline', [MemberController::class, 'timeline'])->name('timeline');
    Route::get('/{member}/notes', [MemberController::class, 'notes'])->name('notes.index');
    Route::post('/{member}/notes', [MemberController::class, 'storeNote'])->name('notes.store');
    Route::delete('/{member}/notes/{note}', [MemberController::class, 'destroyNote'])->name('notes.destroy');
    Route::get('/{member}/documents', [MemberController::class, 'documents'])->name('documents.index');
    Route::post('/{member}/documents', [MemberController::class, 'storeDocument'])->name('documents.store');
    Route::delete('/{member}/documents/{document}', [MemberController::class, 'destroyDocument'])->name('documents.destroy');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('member-interviews')
  ->name('member-interviews.')
  ->group(function (): void {
    Route::get('/', [MemberInterviewController::class, 'index'])->name('index');
    Route::put('/{interview}', [MemberInterviewController::class, 'update'])->name('update');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('member-notifications')
  ->name('member-notifications.')
  ->group(function (): void {
    Route::get('/', [MemberNotificationQueueController::class, 'index'])->name('index');
    Route::get('/{notification}', [MemberNotificationQueueController::class, 'show'])->name('show');
    Route::post('/{notification}/sent', [MemberNotificationQueueController::class, 'markSent'])->name('sent');
    Route::post('/{notification}/failed', [MemberNotificationQueueController::class, 'markFailed'])->name('failed');
    Route::post('/{notification}/retry', [MemberNotificationQueueController::class, 'retry'])->name('retry');
    Route::post('/{notification}/cancel', [MemberNotificationQueueController::class, 'cancel'])->name('cancel');
  });

Route::middleware(['auth:sanctum', 'permission:member.portal'])
  ->prefix('member-portal')
  ->name('member-portal.')
  ->group(function (): void {
    Route::get('/dashboard', [MemberPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/profile', [MemberPortalController::class, 'profile'])->name('profile');
    Route::put('/profile', [MemberPortalController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/photo', [MemberPortalController::class, 'uploadPhoto'])->name('profile.photo.upload');
    Route::put('/profile/photo', [MemberPortalController::class, 'attachPhoto'])->name('profile.photo.attach');
    Route::delete('/profile/photo', [MemberPortalController::class, 'destroyPhoto'])->name('profile.photo.destroy');
    Route::get('/my-ministry', [MemberPortalController::class, 'myMinistry'])->name('my-ministry');
    Route::get('/activity', [MemberPortalController::class, 'activity'])->name('activity');
    Route::get('/notifications', [MemberPortalController::class, 'notifications'])->name('notifications');
    Route::post('/notifications/mark-all-read', [MemberPortalController::class, 'markAllNotificationsRead'])->name('notifications.mark-all-read');
    Route::post('/notifications/{notification}/read', [MemberPortalController::class, 'markNotificationRead'])->name('notifications.read');
    Route::post('/notifications/{notification}/archive', [MemberPortalController::class, 'archiveNotification'])->name('notifications.archive');
    Route::delete('/notifications/{notification}', [MemberPortalController::class, 'deleteNotification'])->name('notifications.delete');
    Route::get('/prayer-requests', [MemberPortalController::class, 'prayerRequests'])->name('prayer-requests');
    Route::get('/counselling-requests', [MemberPortalController::class, 'counsellingRequests'])->name('counselling-requests');
    Route::get('/events', [MemberPortalController::class, 'events'])->name('events');
    Route::get('/events/{registration}/check-in-token', [MemberPortalController::class, 'eventCheckInToken'])->name('events.check-in-token');
  });
