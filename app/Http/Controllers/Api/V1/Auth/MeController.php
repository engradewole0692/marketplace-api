<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\UserResource;
use App\Services\Iam\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends ApiController
{
  public function __invoke(Request $request, AuthorizationService $authorizationService): JsonResponse
  {
    $user = $request->user();
    $user?->load(['roles', 'avatarMedia']);

    return $this->responder->success(
      data: [
        'user' => new UserResource($user),
        'permissions' => $user ? $authorizationService->permissionSlugsForUser($user) : [],
      ],
      message: 'Authenticated user retrieved.',
    );
  }
}
