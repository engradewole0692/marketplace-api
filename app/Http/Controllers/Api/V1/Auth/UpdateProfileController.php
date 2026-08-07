<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\ProfileService;
use Illuminate\Http\JsonResponse;

final class UpdateProfileController extends ApiController
{
  public function __invoke(
    UpdateProfileRequest $request,
    ProfileService $profileService,
  ): JsonResponse {
    $user = $profileService->update($request->user(), $request->validated());
    $user->load('roles');

    return $this->responder->success(
      data: ['user' => new UserResource($user)],
      message: 'Profile updated successfully.',
    );
  }
}
