<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum treats a request as stateful only when Origin or Referer matches
 * configured SPA hosts. Same-origin fetch GETs often omit both headers even
 * though the session cookie is present. Mark trusted XMLHttpRequest API calls
 * with the configured frontend origin before Sanctum evaluates statefulness.
 */
final class EnsureSpaStatefulOrigin
{
  public function handle(Request $request, Closure $next): Response
  {
    if (
      ($request->is('api/*') || $request->is('sanctum/*'))
      && ! $request->headers->has('referer')
      && ! $request->headers->has('origin')
      && $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
    ) {
      $request->headers->set('Origin', (string) config('app-frontend.url'));
    }

    return $next($request);
  }
}
