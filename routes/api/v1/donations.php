<?php

declare(strict_types=1);

use App\Modules\Donations\Http\Controllers\Api\V1\Admin\DonationAdminController;
use App\Modules\Donations\Http\Controllers\Api\V1\Public\PayPalCaptureController;
use App\Modules\Donations\Http\Controllers\Api\V1\Public\PublicDonationController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/donations')
  ->name('public.donations.')
  ->middleware('throttle:30,1')
  ->group(function (): void {
    Route::get('/funds', [PublicDonationController::class, 'funds'])->name('funds');
    Route::get('/methods', [PublicDonationController::class, 'methods'])->name('methods');
    Route::post('/checkout', [PublicDonationController::class, 'checkout'])->name('checkout');
    Route::post('/webhooks/{provider}', [PublicDonationController::class, 'webhook'])
      ->middleware('throttle:120,1')
      ->name('webhooks');
    // PayPal return-URL capture (after buyer approves on PayPal)
    Route::post('/paypal/capture', [PayPalCaptureController::class, 'capture'])->name('paypal.capture');
  });

Route::middleware(['auth:sanctum'])
  ->prefix('donations')
  ->name('donations.')
  ->group(function (): void {
    Route::get('/', [DonationAdminController::class, 'index'])->name('index');
    Route::get('/analytics', [DonationAdminController::class, 'analytics'])->name('analytics');
    Route::get('/funds', [DonationAdminController::class, 'funds'])->name('funds.index');
    Route::post('/funds', [DonationAdminController::class, 'storeFund'])->name('funds.store');
    Route::put('/funds/{fund}', [DonationAdminController::class, 'updateFund'])->name('funds.update');
    Route::get('/audit-logs', [DonationAdminController::class, 'auditLogs'])->name('audit-logs');
    Route::get('/countries/{country}/methods', [DonationAdminController::class, 'countryMethods'])->name('countries.methods');
    Route::put('/countries/{country}/methods', [DonationAdminController::class, 'upsertCountryMethod'])->name('countries.methods.upsert');
    Route::post('/countries/{country}/bank-accounts', [DonationAdminController::class, 'storeBankAccount'])->name('countries.bank-accounts.store');
    Route::get('/{donation}', [DonationAdminController::class, 'show'])->name('show');
    Route::post('/{donation}/confirm', [DonationAdminController::class, 'confirm'])->name('confirm');
  });
