<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;

final class ForgotPasswordController extends ApiController
{
  public function __invoke(
    ForgotPasswordRequest $request,
    PasswordService $passwordService,
  ): JsonResponse {
    $message = $passwordService->sendResetLink($request->validated('email'));

    return $this->responder->success(
      message: $message,
    );
  }
}
