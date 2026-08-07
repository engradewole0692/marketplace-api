<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;

final class ResetPasswordController extends ApiController
{
  public function __invoke(
    ResetPasswordRequest $request,
    PasswordService $passwordService,
  ): JsonResponse {
    $message = $passwordService->resetPassword($request->validated());

    return $this->responder->success(
      message: $message,
    );
  }
}
