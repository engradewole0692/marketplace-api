<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exposes the plain CSRF token for cross-origin SPAs.
 *
 * Browsers cannot read the XSRF-TOKEN cookie set on the API host from a
 * different frontend origin. Clients should send this value as X-CSRF-TOKEN.
 * Call GET /sanctum/csrf-cookie first so the session + encrypted cookie exist.
 */
final class CsrfTokenController extends ApiController
{
  public function __invoke(Request $request): JsonResponse
  {
    if ($request->hasSession()) {
      $request->session()->token();
    }

    return $this->responder->success(
      data: [
        'csrf_token' => csrf_token(),
      ],
      message: 'CSRF token ready.',
    );
  }
}
