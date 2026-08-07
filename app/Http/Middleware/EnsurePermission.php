<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePermission
{
  public function handle(Request $request, Closure $next, string $permission): Response
  {
    $user = $request->user();

    if ($user === null || ! $user->hasPermission($permission)) {
      throw new BusinessException(
        'You do not have permission to perform this action.',
        ApiErrorCode::Forbidden,
        null,
        403,
      );
    }

    return $next($request);
  }
}
