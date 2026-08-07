<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attempt Sanctum authentication when a bearer token or SPA session is present,
 * without rejecting unauthenticated visitors.
 */
final class OptionalSanctumAuth
{
  public function handle(Request $request, Closure $next): Response
  {
    if ($request->user('sanctum') === null) {
      Auth::shouldUse('sanctum');
      Auth::guard('sanctum')->user();
    }

    return $next($request);
  }
}
