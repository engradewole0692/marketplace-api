<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\CsrfTokenController;
use App\Http\Controllers\Api\V1\Auth\DeleteAvatarController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\UpdateProfileController;
use App\Http\Controllers\Api\V1\Auth\UploadAvatarController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api'])
  ->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    Route::prefix('auth')->name('auth.')->group(function (): void {
      Route::get('/csrf-token', CsrfTokenController::class)->name('csrf-token');

      Route::post('/login', LoginController::class)
        ->middleware('throttle:auth-login')
        ->name('login');

      Route::post('/forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:auth-password')
        ->name('password.email');

      Route::post('/reset-password', ResetPasswordController::class)
        ->middleware('throttle:auth-password')
        ->name('password.reset');

      Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware('signed')
        ->name('verification.verify');

      Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', LogoutController::class)->name('logout');
        Route::get('/me', MeController::class)->name('me');
        Route::post('/email/verification-notification', ResendVerificationController::class)
          ->middleware('throttle:6,1')
          ->name('verification.send');
        Route::post('/change-password', ChangePasswordController::class)->name('password.change');
        Route::put('/profile', UpdateProfileController::class)->name('profile.update');
        Route::post('/avatar', UploadAvatarController::class)->name('avatar.upload');
        Route::delete('/avatar', DeleteAvatarController::class)->name('avatar.delete');
      });
    });

    require base_path('routes/api/v1/iam.php');
    require base_path('routes/api/v1/members.php');
    require base_path('routes/api/v1/cms.php');
    require base_path('routes/api/v1/donations.php');
    require base_path('routes/api/v1/events.php');
    require base_path('routes/api/v1/lms.php');
    require base_path('routes/api/v1/counselling.php');
    require base_path('routes/api/v1/communications.php');
    require base_path('routes/api/v1/public.php');
    require base_path('routes/api/v1/dashboard.php');
  });
