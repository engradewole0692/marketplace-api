<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ApiResponderContract;
use App\Support\Api\ApiExceptionHandler;
use App\Support\Api\ApiResponse;
use Illuminate\Support\ServiceProvider;

final class ApiServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->app->singleton(ApiResponderContract::class, ApiResponse::class);
    $this->app->singleton(ApiExceptionHandler::class);
  }

  public function boot(): void
  {
    //
  }
}
