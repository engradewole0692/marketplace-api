<?php

declare(strict_types=1);

use App\Modules\Counselling\Http\Controllers\Api\V1\Admin\CounsellingAdminController;
use App\Modules\Counselling\Http\Controllers\Api\V1\Portal\ClientCounsellingController;
use App\Modules\Counselling\Http\Controllers\Api\V1\Portal\CounsellorPortalController;
use App\Modules\Counselling\Http\Controllers\Api\V1\Public\PublicCounsellingController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/counselling')
  ->name('public.counselling.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/categories', [PublicCounsellingController::class, 'categories'])->name('categories');
    Route::get('/services', [PublicCounsellingController::class, 'services'])->name('services.index');
    Route::get('/services/{slug}', [PublicCounsellingController::class, 'showService'])->name('services.show');
    Route::post('/request', [PublicCounsellingController::class, 'request'])
      ->middleware(['auth:sanctum', 'throttle:20,1'])
      ->name('request');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('counselling')
  ->name('counselling.')
  ->group(function (): void {
    Route::get('/dashboard', [CounsellingAdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/reports', [CounsellingAdminController::class, 'reports'])->name('reports');

    Route::get('/categories', [CounsellingAdminController::class, 'indexCategories'])->name('categories.index');
    Route::post('/categories', [CounsellingAdminController::class, 'storeCategory'])->name('categories.store');
    Route::put('/categories/{category}', [CounsellingAdminController::class, 'updateCategory'])->name('categories.update');
    Route::delete('/categories/{category}', [CounsellingAdminController::class, 'destroyCategory'])->name('categories.destroy');

    Route::get('/services', [CounsellingAdminController::class, 'indexServices'])->name('services.index');
    Route::post('/services', [CounsellingAdminController::class, 'storeService'])->name('services.store');
    Route::put('/services/{service}', [CounsellingAdminController::class, 'updateService'])->name('services.update');
    Route::delete('/services/{service}', [CounsellingAdminController::class, 'destroyService'])->name('services.destroy');

    Route::get('/cases', [CounsellingAdminController::class, 'indexCases'])->name('cases.index');
    Route::get('/cases/{counsellingCase}', [CounsellingAdminController::class, 'showCase'])->name('cases.show');
    Route::post('/cases/{counsellingCase}/assign', [CounsellingAdminController::class, 'assignCase'])->name('cases.assign');
    Route::post('/cases/{counsellingCase}/transition', [CounsellingAdminController::class, 'transitionCase'])->name('cases.transition');
    Route::post('/cases/{counsellingCase}/cancel', [CounsellingAdminController::class, 'cancelCase'])->name('cases.cancel');
    Route::post('/cases/{counsellingCase}/appointments', [CounsellingAdminController::class, 'scheduleAppointment'])->name('cases.appointments.schedule');
    Route::post('/cases/{counsellingCase}/payments/mark-paid', [CounsellingAdminController::class, 'markPaymentPaid'])->name('cases.payments.mark-paid');
    Route::post('/cases/{counsellingCase}/notes', [CounsellingAdminController::class, 'storeNote'])->name('cases.notes.store');
    Route::get('/cases/{counsellingCase}/messages', [CounsellingAdminController::class, 'listMessages'])->name('cases.messages.index');
    Route::post('/cases/{counsellingCase}/messages', [CounsellingAdminController::class, 'sendMessage'])->name('cases.messages.store');
    Route::get('/cases/{counsellingCase}/documents', [CounsellingAdminController::class, 'listDocuments'])->name('cases.documents.index');
    Route::post('/cases/{counsellingCase}/documents', [CounsellingAdminController::class, 'uploadDocument'])->name('cases.documents.store');
    Route::post('/cases/{counsellingCase}/require-payment', [CounsellingAdminController::class, 'requirePayment'])->name('cases.require-payment');
    Route::post('/cases/{counsellingCase}/waive-payment', [CounsellingAdminController::class, 'waivePayment'])->name('cases.waive-payment');
    Route::get('/appointments', [CounsellingAdminController::class, 'indexAppointments'])->name('appointments.index');
    Route::get('/assignments', [CounsellingAdminController::class, 'indexAssignments'])->name('assignments.index');
    Route::get('/settings', [CounsellingAdminController::class, 'settings'])->name('settings');
    Route::put('/settings', [CounsellingAdminController::class, 'updateSettings'])->name('settings.update');

    Route::put('/appointments/{appointment}/reschedule', [CounsellingAdminController::class, 'rescheduleAppointment'])->name('appointments.reschedule');
    Route::post('/appointments/{appointment}/confirm', [CounsellingAdminController::class, 'confirmAppointment'])->name('appointments.confirm');
    Route::post('/appointments/{appointment}/complete', [CounsellingAdminController::class, 'completeAppointment'])->name('appointments.complete');
    Route::post('/appointments/{appointment}/missed', [CounsellingAdminController::class, 'missedAppointment'])->name('appointments.missed');

    Route::get('/counsellors', [CounsellingAdminController::class, 'indexCounsellors'])->name('counsellors.index');
    Route::post('/counsellors', [CounsellingAdminController::class, 'storeCounsellor'])->name('counsellors.store');
    Route::put('/counsellors/{counsellor}', [CounsellingAdminController::class, 'updateCounsellor'])->name('counsellors.update');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('portal/counselling')
  ->name('portal.counselling.')
  ->group(function (): void {
    Route::get('/cases', [ClientCounsellingController::class, 'myCases'])->name('cases.index');
    Route::get('/cases/{counsellingCase}', [ClientCounsellingController::class, 'showCase'])->name('cases.show');
    Route::post('/cases/{counsellingCase}/pay', [ClientCounsellingController::class, 'payCase'])->name('cases.pay');
    Route::put('/cases/{counsellingCase}/schedule-preference', [ClientCounsellingController::class, 'updateSchedulePreference'])->name('cases.schedule-preference');
    Route::post('/cases/{counsellingCase}/request-reschedule', [ClientCounsellingController::class, 'requestReschedule'])->name('cases.request-reschedule');
    Route::post('/cases/{counsellingCase}/cancel', [ClientCounsellingController::class, 'cancelCase'])->name('cases.cancel');
    Route::get('/cases/{counsellingCase}/messages', [ClientCounsellingController::class, 'listMessages'])->name('cases.messages.index');
    Route::post('/cases/{counsellingCase}/messages', [ClientCounsellingController::class, 'sendMessage'])->name('cases.messages.store');
    Route::get('/cases/{counsellingCase}/notes', [ClientCounsellingController::class, 'listNotes'])->name('cases.notes.index');
    Route::post('/cases/{counsellingCase}/feedback', [ClientCounsellingController::class, 'submitFeedback'])->name('cases.feedback.store');
    Route::get('/cases/{counsellingCase}/documents', [ClientCounsellingController::class, 'listDocuments'])->name('cases.documents.index');
    Route::post('/cases/{counsellingCase}/documents', [ClientCounsellingController::class, 'uploadDocument'])->name('cases.documents.store');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('counsellor')
  ->name('counsellor.')
  ->group(function (): void {
    Route::get('/cases', [CounsellorPortalController::class, 'myCases'])->name('cases.index');
    Route::get('/cases/{counsellingCase}', [CounsellorPortalController::class, 'showCase'])->name('cases.show');
    Route::get('/appointments', [CounsellorPortalController::class, 'appointments'])->name('appointments.index');
    Route::get('/appointments/today', [CounsellorPortalController::class, 'todayAppointments'])->name('appointments.today');
    Route::get('/availability', [CounsellorPortalController::class, 'availability'])->name('availability.show');
    Route::put('/availability', [CounsellorPortalController::class, 'updateAvailability'])->name('availability.update');
    Route::post('/cases/{counsellingCase}/notes', [CounsellorPortalController::class, 'addCounsellorNote'])->name('cases.notes.store');
    Route::get('/cases/{counsellingCase}/notes', [CounsellorPortalController::class, 'listNotes'])->name('cases.notes.index');
    Route::post('/cases/{counsellingCase}/messages', [CounsellorPortalController::class, 'sendMessage'])->name('cases.messages.store');
    Route::get('/cases/{counsellingCase}/messages', [CounsellorPortalController::class, 'listMessages'])->name('cases.messages.index');
    Route::post('/cases/{counsellingCase}/recommend-follow-up', [CounsellorPortalController::class, 'recommendFollowUp'])->name('cases.recommend-follow-up');
    Route::post('/cases/{counsellingCase}/close-session', [CounsellorPortalController::class, 'closeSession'])->name('cases.close-session');
    Route::post('/appointments/{appointment}/attendance', [CounsellorPortalController::class, 'markAppointmentAttendance'])->name('appointments.attendance');
  });
