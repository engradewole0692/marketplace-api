<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AvatarService;
use Illuminate\Http\JsonResponse;

final class UploadAvatarController extends ApiController
{
  public function __invoke(
    UploadAvatarRequest $request,
    AvatarService $avatarService,
  ): JsonResponse {
    $user = $avatarService->upload($request->user(), $request->file('avatar'));
    $user->load(['roles', 'avatarMedia']);

    return $this->responder->success(
      data: ['user' => new UserResource($user)],
      message: 'Avatar uploaded successfully.',
    );
  }
}
