<?php

declare(strict_types=1);

use App\Modules\Events\Http\Controllers\Api\V1\Admin\AttendanceAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\CertificateAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\CertificateTemplateAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\CheckInTokenAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\CouponAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\EventAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\EventCategoryAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\EventSessionAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\ExportAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\NotificationAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\RegistrationAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\RegistrationPaymentAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\ReportAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\SpeakerAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\VenueAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\VolunteerAssignmentAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Admin\VolunteerRoleAdminController;
use App\Modules\Events\Http\Controllers\Api\V1\Public\PublicCertificateController;
use App\Modules\Events\Http\Controllers\Api\V1\Public\PublicEventController;
use App\Modules\Events\Http\Controllers\Api\V1\Public\PublicRegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/events')
  ->name('public.events.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/', [PublicEventController::class, 'index'])->name('index');
    Route::post('/registrations', [PublicRegistrationController::class, 'store'])
      ->middleware('throttle:20,1')
      ->name('registrations.store');
    Route::get('/certificates/verify/{code}', [PublicCertificateController::class, 'verify'])
      ->name('certificates.verify');
    Route::get('/{event}', [PublicEventController::class, 'show'])->name('show');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('events')
  ->name('events.')
  ->group(function (): void {
    Route::get('/categories', [EventCategoryAdminController::class, 'index'])->name('categories.index');
    Route::post('/categories', [EventCategoryAdminController::class, 'store'])->name('categories.store');
    Route::put('/categories/{category}', [EventCategoryAdminController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [EventCategoryAdminController::class, 'destroy'])->name('categories.destroy');

    Route::get('/registrations', [RegistrationAdminController::class, 'index'])->name('registrations.index');
    Route::get('/registrations/{registration}', [RegistrationAdminController::class, 'show'])->name('registrations.show');
    Route::put('/registrations/{registration}/status', [RegistrationAdminController::class, 'updateStatus'])->name('registrations.status');
    Route::post('/registrations/{registration}/check-in', [RegistrationAdminController::class, 'checkIn'])->name('registrations.check-in');
    Route::post('/registrations/{registration}/check-out', [RegistrationAdminController::class, 'checkOut'])->name('registrations.check-out');
    Route::post('/registrations/{registration}/check-in-token', [CheckInTokenAdminController::class, 'issue'])->name('registrations.check-in-token');
    Route::post('/registrations/{registration}/payments/offline', [RegistrationPaymentAdminController::class, 'offline'])->name('registrations.payments.offline');
    Route::post('/registrations/{registration}/payments/approve', [RegistrationPaymentAdminController::class, 'approve'])->name('registrations.payments.approve');
    Route::post('/registrations/{registration}/payments/waive', [RegistrationPaymentAdminController::class, 'waive'])->name('registrations.payments.waive');
    Route::post('/registrations/{registration}/payments/coupon', [RegistrationPaymentAdminController::class, 'coupon'])->name('registrations.payments.coupon');
    Route::delete('/registrations/{registration}', [RegistrationAdminController::class, 'destroy'])->name('registrations.destroy');

    Route::post('/check-in/scan', [CheckInTokenAdminController::class, 'scanIn'])->name('check-in.scan');
    Route::post('/check-out/scan', [CheckInTokenAdminController::class, 'scanOut'])->name('check-out.scan');

    Route::get('/attendance', [AttendanceAdminController::class, 'index'])->name('attendance.index');

    Route::get('/reports', [ReportAdminController::class, 'index'])->name('reports.index');
    Route::post('/reports', [ReportAdminController::class, 'generate'])->name('reports.generate');
    Route::get('/reports/{snapshot}/download', [ReportAdminController::class, 'download'])->name('reports.download');

    Route::get('/exports', [ExportAdminController::class, 'index'])->name('exports.index');
    Route::post('/exports', [ExportAdminController::class, 'store'])->name('exports.store');
    Route::get('/exports/{export}/download', [ExportAdminController::class, 'download'])->name('exports.download');

    Route::get('/notification-templates', [NotificationAdminController::class, 'templates'])->name('notification-templates.index');
    Route::post('/notification-templates', [NotificationAdminController::class, 'storeTemplate'])->name('notification-templates.store');
    Route::post('/notifications/announce', [NotificationAdminController::class, 'sendAnnouncement'])->name('notifications.announce');

    Route::get('/certificate-templates', [CertificateTemplateAdminController::class, 'index'])->name('certificate-templates.index');
    Route::post('/certificate-templates', [CertificateTemplateAdminController::class, 'store'])->name('certificate-templates.store');
    Route::put('/certificate-templates/{template}', [CertificateTemplateAdminController::class, 'update'])->name('certificate-templates.update');
    Route::delete('/certificate-templates/{template}', [CertificateTemplateAdminController::class, 'destroy'])->name('certificate-templates.destroy');

    Route::get('/certificates', [CertificateAdminController::class, 'index'])->name('certificates.index');
    Route::post('/certificates/issue', [CertificateAdminController::class, 'issue'])->name('certificates.issue');
    Route::post('/certificates/batch', [CertificateAdminController::class, 'batch'])->name('certificates.batch');
    Route::post('/certificates/reissue/{issuance}', [CertificateAdminController::class, 'reissue'])->name('certificates.reissue');

    Route::get('/volunteer-assignments', [VolunteerAssignmentAdminController::class, 'index'])->name('volunteer-assignments.index');
    Route::get('/volunteer-assignments/interested', [VolunteerAssignmentAdminController::class, 'interested'])->name('volunteer-assignments.interested');
    Route::post('/volunteer-assignments', [VolunteerAssignmentAdminController::class, 'store'])->name('volunteer-assignments.store');
    Route::put('/volunteer-assignments/{assignment}', [VolunteerAssignmentAdminController::class, 'update'])->name('volunteer-assignments.update');
    Route::delete('/volunteer-assignments/{assignment}', [VolunteerAssignmentAdminController::class, 'destroy'])->name('volunteer-assignments.destroy');

    Route::get('/{event}/sessions', [EventSessionAdminController::class, 'index'])->name('sessions.index');
    Route::post('/{event}/sessions', [EventSessionAdminController::class, 'store'])->name('sessions.store');
    Route::put('/sessions/{session}', [EventSessionAdminController::class, 'update'])->name('sessions.update');
    Route::delete('/sessions/{session}', [EventSessionAdminController::class, 'destroy'])->name('sessions.destroy');

    Route::get('/{event}/volunteer-roles', [VolunteerRoleAdminController::class, 'index'])->name('volunteer-roles.index');
    Route::post('/{event}/volunteer-roles', [VolunteerRoleAdminController::class, 'store'])->name('volunteer-roles.store');
    Route::put('/volunteer-roles/{role}', [VolunteerRoleAdminController::class, 'update'])->name('volunteer-roles.update');
    Route::delete('/volunteer-roles/{role}', [VolunteerRoleAdminController::class, 'destroy'])->name('volunteer-roles.destroy');

    Route::get('/{event}/coupons', [CouponAdminController::class, 'index'])->name('coupons.index');
    Route::post('/{event}/coupons', [CouponAdminController::class, 'store'])->name('coupons.store');
    Route::put('/coupons/{coupon}', [CouponAdminController::class, 'update'])->name('coupons.update');
    Route::delete('/coupons/{coupon}', [CouponAdminController::class, 'destroy'])->name('coupons.destroy');

    Route::get('/', [EventAdminController::class, 'index'])->name('index');
    Route::post('/', [EventAdminController::class, 'store'])->name('store');
    Route::get('/{event}', [EventAdminController::class, 'show'])->name('show');
    Route::put('/{event}', [EventAdminController::class, 'update'])->name('update');
    Route::delete('/{event}', [EventAdminController::class, 'destroy'])->name('destroy');
    Route::post('/{event}/publish', [EventAdminController::class, 'publish'])->name('publish');
    Route::post('/{event}/unpublish', [EventAdminController::class, 'unpublish'])->name('unpublish');
    Route::post('/{event}/archive', [EventAdminController::class, 'archive'])->name('archive');
    Route::post('/{event}/duplicate', [EventAdminController::class, 'duplicate'])->name('duplicate');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('venues')
  ->name('venues.')
  ->group(function (): void {
    Route::get('/', [VenueAdminController::class, 'index'])->name('index');
    Route::post('/', [VenueAdminController::class, 'store'])->name('store');
    Route::get('/{venue}', [VenueAdminController::class, 'show'])->name('show');
    Route::put('/{venue}', [VenueAdminController::class, 'update'])->name('update');
    Route::delete('/{venue}', [VenueAdminController::class, 'destroy'])->name('destroy');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('speakers')
  ->name('speakers.')
  ->group(function (): void {
    Route::get('/', [SpeakerAdminController::class, 'index'])->name('index');
    Route::post('/', [SpeakerAdminController::class, 'store'])->name('store');
    Route::get('/{speaker}', [SpeakerAdminController::class, 'show'])->name('show');
    Route::put('/{speaker}', [SpeakerAdminController::class, 'update'])->name('update');
    Route::delete('/{speaker}', [SpeakerAdminController::class, 'destroy'])->name('destroy');
  });
