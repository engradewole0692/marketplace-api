<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Api\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
return Application::configure(basePath: dirname(__DIR__))
  ->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    apiPrefix: env('API_PREFIX', 'api'),
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
  )
  ->withMiddleware(function (Middleware $middleware): void {
    $trustedProxies = env('TRUSTED_PROXIES');
    if (is_string($trustedProxies) && $trustedProxies !== '') {
      $at = $trustedProxies === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
      $middleware->trustProxies(
        at: $at,
        headers: Request::HEADER_X_FORWARDED_FOR
          | Request::HEADER_X_FORWARDED_HOST
          | Request::HEADER_X_FORWARDED_PORT
          | Request::HEADER_X_FORWARDED_PROTO
          | Request::HEADER_X_FORWARDED_AWS_ELB,
      );
    }

    $middleware->statefulApi();

    $middleware->api(prepend: [
      \App\Http\Middleware\EnsureSpaStatefulOrigin::class,
      \App\Http\Middleware\ForceJsonResponse::class,
    ]);

    $middleware->append(SecurityHeaders::class);

    $middleware->alias([
      'permission' => \App\Http\Middleware\EnsurePermission::class,
      'auth.sanctum.optional' => \App\Http\Middleware\OptionalSanctumAuth::class,
    ]);
  })
  ->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (\Throwable $exception, Request $request) {
      $handler = app(ApiExceptionHandler::class);

      if ($handler->shouldRender($request, $exception)) {
        return $handler->render($request, $exception);
      }

      return null;
    });
  })
  ->create();
