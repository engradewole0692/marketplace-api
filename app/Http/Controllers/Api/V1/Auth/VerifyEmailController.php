<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VerifyEmailController extends ApiController
{
  public function __invoke(Request $request, string $id, string $hash): JsonResponse
  {
    $user = User::query()->findOrFail($id);

    if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
      throw new ApiException(
        ApiErrorCode::Forbidden,
        'Invalid verification link.',
        null,
        403,
      );
    }

    if (! $request->hasValidSignature()) {
      throw new ApiException(
        ApiErrorCode::Forbidden,
        'Verification link has expired or is invalid.',
        null,
        403,
      );
    }

    if ($user->hasVerifiedEmail()) {
      return $this->responder->success(
        message: 'Email address is already verified.',
      );
    }

    if ($user->markEmailAsVerified()) {
      event(new Verified($user));
    }

    return $this->responder->success(
      message: 'Email address verified successfully.',
    );
  }
}
