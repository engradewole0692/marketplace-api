<?php

declare(strict_types=1);

use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsCatalogController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsCountryController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsDashboardController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsFormSubmissionController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsLeadershipController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsMediaController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsMenuController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsMinistryController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsNotificationController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsPageController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsPageSectionController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsPartnerController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsSeoController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsSettingController;
use App\Modules\Cms\Http\Controllers\Api\V1\Admin\CmsTestimonialController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
  ->prefix('cms')
  ->name('cms.')
  ->group(function (): void {
    Route::get('/dashboard', [CmsDashboardController::class, 'overview'])->name('dashboard');

    Route::get('/pages', [CmsPageController::class, 'index'])->name('pages.index');
    Route::post('/pages', [CmsPageController::class, 'store'])->name('pages.store');
    Route::get('/pages/{page}', [CmsPageController::class, 'show'])->name('pages.show');
    Route::put('/pages/{page}', [CmsPageController::class, 'update'])->name('pages.update');
    Route::delete('/pages/{page}', [CmsPageController::class, 'destroy'])->name('pages.destroy');
    Route::get('/pages/{page}/versions', [CmsPageController::class, 'versions'])->name('pages.versions');
    Route::post('/pages/{page}/versions/{version}/restore', [CmsPageController::class, 'restoreVersion'])->name('pages.versions.restore');
    Route::get('/pages/{page}/versions/{from}/compare/{to}', [CmsPageController::class, 'compareVersions'])->name('pages.versions.compare');
    Route::post('/pages/{page}/publish', [CmsPageController::class, 'publish'])->name('pages.publish');
    Route::post('/pages/{page}/unpublish', [CmsPageController::class, 'unpublish'])->name('pages.unpublish');
    Route::post('/pages/{page}/archive', [CmsPageController::class, 'archive'])->name('pages.archive');
    Route::post('/pages/{page}/duplicate', [CmsPageController::class, 'duplicate'])->name('pages.duplicate');

    Route::get('/countries', [CmsCountryController::class, 'index'])->name('countries.index');
    Route::post('/countries', [CmsCountryController::class, 'store'])->name('countries.store');
    Route::post('/countries/{country}/image', [CmsCountryController::class, 'uploadImage'])->name('countries.image');
    Route::put('/countries/{country}', [CmsCountryController::class, 'update'])->name('countries.update');
    Route::delete('/countries/{country}', [CmsCountryController::class, 'destroy'])->name('countries.destroy');
    Route::post('/countries/reorder', [CmsCountryController::class, 'reorder'])->name('countries.reorder');

    Route::get('/ministries', [CmsMinistryController::class, 'index'])->name('ministries.index');
    Route::post('/ministries', [CmsMinistryController::class, 'store'])->name('ministries.store');
    Route::post('/ministries/{ministry}/image', [CmsMinistryController::class, 'uploadImage'])->name('ministries.image');
    Route::put('/ministries/{ministry}', [CmsMinistryController::class, 'update'])->name('ministries.update');
    Route::delete('/ministries/{ministry}', [CmsMinistryController::class, 'destroy'])->name('ministries.destroy');
    Route::post('/ministries/reorder', [CmsMinistryController::class, 'reorder'])->name('ministries.reorder');

    Route::get('/leadership', [CmsLeadershipController::class, 'index'])->name('leadership.index');
    Route::post('/leadership', [CmsLeadershipController::class, 'store'])->name('leadership.store');
    Route::post('/leadership/{profile}/photo', [CmsLeadershipController::class, 'uploadPhoto'])->name('leadership.photo');
    Route::put('/leadership/{profile}', [CmsLeadershipController::class, 'update'])->name('leadership.update');
    Route::delete('/leadership/{profile}', [CmsLeadershipController::class, 'destroy'])->name('leadership.destroy');
    Route::post('/leadership/reorder', [CmsLeadershipController::class, 'reorder'])->name('leadership.reorder');

    Route::get('/partners', [CmsPartnerController::class, 'index'])->name('partners.index');
    Route::post('/partners', [CmsPartnerController::class, 'store'])->name('partners.store');
    Route::put('/partners/{partner}', [CmsPartnerController::class, 'update'])->name('partners.update');
    Route::delete('/partners/{partner}', [CmsPartnerController::class, 'destroy'])->name('partners.destroy');
    Route::post('/partners/reorder', [CmsPartnerController::class, 'reorder'])->name('partners.reorder');
    Route::post('/partners/bulk-update', [CmsPartnerController::class, 'bulkUpdate'])->name('partners.bulk-update');
    Route::post('/partners/bulk-delete', [CmsPartnerController::class, 'bulkDestroy'])->name('partners.bulk-delete');

    Route::get('/testimonials', [CmsTestimonialController::class, 'index'])->name('testimonials.index');
    Route::post('/testimonials', [CmsTestimonialController::class, 'store'])->name('testimonials.store');
    Route::put('/testimonials/{testimonial}', [CmsTestimonialController::class, 'update'])->name('testimonials.update');
    Route::post('/testimonials/{testimonial}/approve', [CmsTestimonialController::class, 'approve'])->name('testimonials.approve');
    Route::post('/testimonials/{testimonial}/reject', [CmsTestimonialController::class, 'reject'])->name('testimonials.reject');
    Route::delete('/testimonials/{testimonial}', [CmsTestimonialController::class, 'destroy'])->name('testimonials.destroy');
    Route::post('/testimonials/reorder', [CmsTestimonialController::class, 'reorder'])->name('testimonials.reorder');
    Route::post('/testimonials/bulk-update', [CmsTestimonialController::class, 'bulkUpdate'])->name('testimonials.bulk-update');
    Route::post('/testimonials/bulk-delete', [CmsTestimonialController::class, 'bulkDestroy'])->name('testimonials.bulk-delete');

    Route::get('/menus', [CmsMenuController::class, 'index'])->name('menus.index');
    Route::get('/menus/{menu}', [CmsMenuController::class, 'show'])->name('menus.show');
    Route::put('/menus/{menu}', [CmsMenuController::class, 'update'])->name('menus.update');
    Route::put('/menus/{menu}/items', [CmsMenuController::class, 'syncItems'])->name('menus.items.sync');

    Route::get('/seo', [CmsSeoController::class, 'index'])->name('seo.index');
    Route::post('/seo', [CmsSeoController::class, 'store'])->name('seo.store');
    Route::put('/seo/{seo}', [CmsSeoController::class, 'update'])->name('seo.update');
    Route::delete('/seo/{seo}', [CmsSeoController::class, 'destroy'])->name('seo.destroy');

    Route::prefix('catalog/{type}')->where(['type' => 'blog|gallery|resource|vlog'])->group(function (): void {
      Route::get('/', [CmsCatalogController::class, 'index'])->name('catalog.index');
      Route::post('/', [CmsCatalogController::class, 'store'])->name('catalog.store');
      Route::post('/reorder', [CmsCatalogController::class, 'reorder'])->name('catalog.reorder');
      Route::put('/{item}', [CmsCatalogController::class, 'update'])->name('catalog.update');
      Route::post('/{item}/media', [CmsCatalogController::class, 'uploadMedia'])->name('catalog.media');
      Route::post('/{item}/file', [CmsCatalogController::class, 'uploadResourceFile'])->name('catalog.file');
      Route::delete('/{item}', [CmsCatalogController::class, 'destroy'])->name('catalog.destroy');
    });

    Route::get('/settings', [CmsSettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [CmsSettingController::class, 'bulkUpdate'])->name('settings.bulk');

    Route::get('/sections', [CmsPageSectionController::class, 'index'])->name('sections.index');
    Route::put('/sections/reorder', [CmsPageSectionController::class, 'reorder'])->name('sections.reorder');
    Route::put('/sections/{section}', [CmsPageSectionController::class, 'update'])->name('sections.update');
    Route::post('/sections/{section}/submit-review', [CmsPageSectionController::class, 'submitReview'])->name('sections.submit-review');
    Route::post('/sections/{section}/publish', [CmsPageSectionController::class, 'publish'])->name('sections.publish');
    Route::get('/sections/{section}/versions', [CmsPageSectionController::class, 'versions'])->name('sections.versions');
    Route::post('/sections/{section}/versions/{version}/restore', [CmsPageSectionController::class, 'restoreVersion'])->name('sections.versions.restore');

    Route::get('/form-submissions', [CmsFormSubmissionController::class, 'index'])->name('form-submissions.index');
    Route::get('/form-submissions/export', [CmsFormSubmissionController::class, 'export'])->name('form-submissions.export');
    Route::get('/form-submissions/{submission}', [CmsFormSubmissionController::class, 'show'])->name('form-submissions.show');
    Route::put('/form-submissions/{submission}', [CmsFormSubmissionController::class, 'update'])->name('form-submissions.update');
    Route::post('/form-submissions/{submission}/notes', [CmsFormSubmissionController::class, 'addNote'])->name('form-submissions.notes.store');
    Route::delete('/form-submissions/{submission}', [CmsFormSubmissionController::class, 'destroy'])->name('form-submissions.destroy');
    Route::post('/form-submissions/{submission}/restore', [CmsFormSubmissionController::class, 'restore'])->name('form-submissions.restore');

    Route::get('/notifications', [CmsNotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread-count', [CmsNotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::post('/notifications/{notification}/read', [CmsNotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [CmsNotificationController::class, 'markAllRead'])->name('notifications.read-all');

    Route::get('/media/folders', [CmsMediaController::class, 'indexFolders'])->name('media.folders.index');
    Route::post('/media/folders', [CmsMediaController::class, 'storeFolder'])->name('media.folders.store');
    Route::put('/media/folders/{folder}', [CmsMediaController::class, 'updateFolder'])->name('media.folders.update');
    Route::delete('/media/folders/{folder}', [CmsMediaController::class, 'destroyFolder'])->name('media.folders.destroy');

    Route::get('/media/statistics', [CmsMediaController::class, 'statistics'])->name('media.statistics');
    Route::get('/media/broken', [CmsMediaController::class, 'broken'])->name('media.broken');
    Route::get('/media/unused', [CmsMediaController::class, 'unused'])->name('media.unused');
    Route::get('/media', [CmsMediaController::class, 'index'])->name('media.index');
    Route::post('/media', [CmsMediaController::class, 'store'])->name('media.store');
    Route::post('/media/bulk-upload', [CmsMediaController::class, 'bulkUpload'])->name('media.bulk-upload');
    Route::post('/media/bulk-delete', [CmsMediaController::class, 'bulkDestroy'])->name('media.bulk-delete');
    Route::post('/media/bulk-restore', [CmsMediaController::class, 'bulkRestore'])->name('media.bulk-restore');
    Route::post('/media/bulk-force-delete', [CmsMediaController::class, 'bulkForceDestroy'])->name('media.bulk-force-delete');
    Route::post('/media/bulk-move', [CmsMediaController::class, 'bulkMove'])->name('media.bulk-move');
    Route::get('/media/{media}', [CmsMediaController::class, 'show'])->name('media.show');
    Route::post('/media/{media}/replace', [CmsMediaController::class, 'replace'])->name('media.replace');
    Route::post('/media/{media}/duplicate', [CmsMediaController::class, 'duplicate'])->name('media.duplicate');
    Route::post('/media/{media}/crop', [CmsMediaController::class, 'crop'])->name('media.crop');
    Route::post('/media/{media}/resize', [CmsMediaController::class, 'resize'])->name('media.resize');
    Route::post('/media/{media}/optimize', [CmsMediaController::class, 'optimize'])->name('media.optimize');
    Route::post('/media/{media}/restore', [CmsMediaController::class, 'restore'])->name('media.restore');
    Route::delete('/media/{media}/force', [CmsMediaController::class, 'forceDestroy'])->name('media.force-destroy');
    Route::put('/media/{media}', [CmsMediaController::class, 'update'])->name('media.update');
    Route::delete('/media/{media}', [CmsMediaController::class, 'destroy'])->name('media.destroy');
  });
