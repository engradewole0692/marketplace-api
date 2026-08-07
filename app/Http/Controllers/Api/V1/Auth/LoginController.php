<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Contracts\ApiResponderContract;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Iam\AuthorizationService;
use Illuminate\Http\JsonResponse;

final class LoginController extends ApiController
{
  public function __invoke(
    LoginRequest $request,
    AuthService $authService,
    AuthorizationService $authorizationService,
  ): JsonResponse {
    $result = $authService->login(
      email: $request->validated('email'),
      password: $request->validated('password'),
      remember: $request->boolean('remember'),
    );

    if ($request->hasSession()) {
      $request->session()->regenerate();
    }

    $result['user']->load('roles');

    return $this->responder->success(
      data: [
        'user' => new UserResource($result['user']),
        'permissions' => $authorizationService->permissionSlugsForUser($result['user']),
      ],
      message: 'Login successful.',
    );
  }
}
