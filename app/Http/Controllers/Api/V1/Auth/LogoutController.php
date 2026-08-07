<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LogoutController extends ApiController
{
  public function __invoke(Request $request, AuthService $authService): JsonResponse
  {
    $authService->logout();

    return $this->responder->success(
      message: 'Logout successful.',
    );
  }
}
