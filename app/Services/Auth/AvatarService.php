<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Services\Profile\ProfilePhotoService;
use Illuminate\Http\UploadedFile;

final class AvatarService implements ServiceContract
{
  public function __construct(
    private readonly ProfilePhotoService $profilePhotoService,
  ) {}

  public function upload(User $user, UploadedFile $file): User
  {
    return $this->profilePhotoService->uploadForUser($user, $file, $user);
  }

  public function delete(User $user): User
  {
    return $this->profilePhotoService->clearUser($user);
  }
}
