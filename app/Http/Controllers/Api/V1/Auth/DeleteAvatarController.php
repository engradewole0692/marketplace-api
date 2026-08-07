<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\UserResource;
use App\Services\Auth\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeleteAvatarController extends ApiController
{
  public function __invoke(Request $request, AvatarService $avatarService): JsonResponse
  {
    $user = $avatarService->delete($request->user());
    $user->load(['roles', 'avatarMedia']);

    return $this->responder->success(
      data: ['user' => new UserResource($user)],
      message: 'Avatar removed successfully.',
    );
  }
}
