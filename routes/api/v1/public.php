<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Public\MembershipApplicationPublicController;
use App\Modules\Cms\Http\Controllers\Api\V1\Public\PublicFormController;
use App\Modules\Cms\Http\Controllers\Api\V1\Public\PublicSiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')
  ->name('public.')
  ->middleware('throttle:60,1')
  ->group(function (): void {
    Route::get('/site', [PublicSiteController::class, 'bootstrap'])->name('site.bootstrap');
    Route::get('/home', [PublicSiteController::class, 'home'])->name('home');
    Route::get('/pages/{slug}', [PublicSiteController::class, 'page'])->name('pages.show');
    Route::get('/countries', [PublicSiteController::class, 'countries'])->name('countries.index');
    Route::get('/countries/{slug}', [PublicSiteController::class, 'country'])->name('countries.show');
    Route::get('/ministries', [PublicSiteController::class, 'ministries'])->name('ministries.index');
    Route::get('/ministries/{slug}', [PublicSiteController::class, 'ministry'])->name('ministries.show');
    Route::get('/leadership', [PublicSiteController::class, 'leadership'])->name('leadership.index');
    Route::get('/testimonials', [PublicSiteController::class, 'testimonials'])->name('testimonials.index');
    Route::get('/partners', [PublicSiteController::class, 'partners'])->name('partners.index');
    Route::get('/catalog/{type}', [PublicSiteController::class, 'catalog'])->name('catalog.index');
    Route::get('/catalog/resource/{slug}/download', [PublicSiteController::class, 'resourceDownload'])->name('catalog.resource.download');
    Route::get('/catalog/{type}/{slug}', [PublicSiteController::class, 'catalogItem'])->name('catalog.show');
    Route::get('/vlog/feed', [PublicSiteController::class, 'vlogFeed'])->name('vlog.feed');

    Route::get('/membership/status', [MembershipApplicationPublicController::class, 'status'])
      ->middleware('throttle:30,1')
      ->name('membership.status');
    Route::post('/membership/interview/confirm', [MembershipApplicationPublicController::class, 'confirmInterview'])
      ->middleware('throttle:20,1')
      ->name('membership.interview.confirm');
    Route::get('/membership/interviews/{interviewUuid}/ics', [MembershipApplicationPublicController::class, 'interviewIcs'])
      ->middleware('throttle:30,1')
      ->name('membership.interview.ics');

    Route::prefix('forms')->name('forms.')->middleware('throttle:20,1')->group(function (): void {
      Route::post('/contact', [PublicFormController::class, 'contact'])->name('contact');
      Route::post('/counseling', [PublicFormController::class, 'counseling'])->name('counseling');
      Route::post('/newsletter', [PublicFormController::class, 'newsletter'])->name('newsletter');
      Route::post('/newsletter/unsubscribe', [PublicFormController::class, 'newsletterUnsubscribe'])->name('newsletter.unsubscribe');
      Route::post('/partnership', [PublicFormController::class, 'partnership'])->name('partnership');
      Route::post('/volunteer', [PublicFormController::class, 'volunteer'])->name('volunteer');
      Route::post('/donation-interest', [PublicFormController::class, 'donationInterest'])->name('donation-interest');
      Route::post('/prayer', [PublicFormController::class, 'prayer'])->name('prayer');
      Route::post('/testimony', [PublicFormController::class, 'testimony'])->name('testimony');
      Route::post('/membership', [PublicFormController::class, 'membership'])->name('membership');
    });
  });
