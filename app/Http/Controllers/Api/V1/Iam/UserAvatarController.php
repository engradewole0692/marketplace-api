<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Iam;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\Profile\ProfilePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserAvatarController extends ApiController
{
  public function upload(Request $request, User $user, ProfilePhotoService $service): JsonResponse
  {
    $this->authorize('update', $user);

    $validated = $request->validate([
      'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
    ]);

    $user = $service->uploadForUser($user, $validated['avatar'], $request->user());

    return $this->responder->success(
      data: ['user' => new AdminUserResource($user->load('roles', 'avatarMedia'))],
      message: 'Avatar uploaded.',
    );
  }

  public function attach(Request $request, User $user, ProfilePhotoService $service): JsonResponse
  {
    $this->authorize('update', $user);

    $validated = $request->validate([
      'media_id' => ['required', 'string'],
    ]);

    $media = $service->resolveMedia($validated['media_id']);
    $user = $service->attachMediaToUser($user, $media);

    return $this->responder->success(
      data: ['user' => new AdminUserResource($user->load('roles', 'avatarMedia'))],
      message: 'Avatar attached from media library.',
    );
  }

  public function destroy(Request $request, User $user, ProfilePhotoService $service): JsonResponse
  {
    $this->authorize('update', $user);

    $user = $service->clearUser($user);

    return $this->responder->success(
      data: ['user' => new AdminUserResource($user->load('roles', 'avatarMedia'))],
      message: 'Avatar removed.',
    );
  }
}
