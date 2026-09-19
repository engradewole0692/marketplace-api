<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Cms\Notifications\LogSmsNotifier;
use App\Modules\Cms\Notifications\LogWhatsAppNotifier;
use App\Modules\Cms\Notifications\MetaWhatsAppNotifier;
use App\Modules\Cms\Notifications\TwilioSmsNotifier;
use App\Modules\Cms\Notifications\TwilioWhatsAppNotifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->app->singleton(SmsNotifierContract::class, function () {
      return match (strtolower((string) config('communications.sms_provider', 'log'))) {
        'twilio' => new TwilioSmsNotifier(),
        default => new LogSmsNotifier(),
      };
    });

    $this->app->singleton(WhatsAppNotifierContract::class, function () {
      return match (strtolower((string) config('communications.whatsapp_provider', 'log'))) {
        'twilio' => new TwilioWhatsAppNotifier(),
        'meta', 'whatsapp_cloud', 'cloud' => new MetaWhatsAppNotifier(),
        default => new LogWhatsAppNotifier(),
      };
    });
  }

  public function boot(): void
  {
    RateLimiter::for('auth-login', function (Request $request) {
      $email = strtolower((string) $request->input('email'));

      return Limit::perMinute(5)->by($email.'|'.$request->ip())->response(function () {
        throw new ApiException(
          ApiErrorCode::TooManyRequests,
          'Too many login attempts. Please try again later.',
          null,
          429,
        );
      });
    });

    RateLimiter::for('auth-password', function (Request $request) {
      $key = strtolower((string) $request->input('email', (string) $request->ip()));

      return Limit::perMinute(5)->by($key.'|'.$request->ip())->response(function () {
        throw new ApiException(
          ApiErrorCode::TooManyRequests,
          'Too many password reset attempts. Please try again later.',
          null,
          429,
        );
      });
    });
  }
}
