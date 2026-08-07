<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResendVerificationController extends ApiController
{
  public function __invoke(Request $request): JsonResponse
  {
    $user = $request->user();

    if ($user === null) {
      throw new BusinessException(
        'Authentication is required.',
        ApiErrorCode::Unauthorized,
        null,
        401,
      );
    }

    if ($user->hasVerifiedEmail()) {
      return $this->responder->success(
        message: 'Email address is already verified.',
      );
    }

    $user->sendEmailVerificationNotification();

    return $this->responder->success(
      message: 'Verification link sent.',
    );
  }
}
