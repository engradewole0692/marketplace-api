<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;

final class ChangePasswordController extends ApiController
{
  public function __invoke(
    ChangePasswordRequest $request,
    PasswordService $passwordService,
  ): JsonResponse {
    $user = $request->user();

    $passwordService->changePassword(
      user: $user,
      currentPassword: $request->validated('current_password'),
      newPassword: $request->validated('password'),
    );

    if ($request->hasSession()) {
      $request->session()->regenerate();
    }

    return $this->responder->success(
      message: 'Password changed successfully. Other sessions have been invalidated.',
    );
  }
}
