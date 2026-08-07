<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Services\CmsMediaAdminService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ProfilePhotoService implements ServiceContract
{
  public function __construct(
    private readonly CmsMediaAdminService $mediaAdminService,
  ) {}

  public function attachMediaToUser(User $user, CmsMedia $media): User
  {
    $user->forceFill([
      'avatar_media_id' => $media->id,
      'avatar' => $media->path,
    ])->save();

    return $user->fresh(['avatarMedia']);
  }

  public function uploadForUser(User $user, UploadedFile $file, User $actor): User
  {
    $result = $this->mediaAdminService->upload($file, $actor, null, trim(($user->name ?? 'user').' avatar'));

    return $this->attachMediaToUser($user, $result['media']);
  }

  public function clearUser(User $user): User
  {
    if ($user->avatar !== null && $user->avatar_media_id === null) {
      Storage::disk('public')->delete($user->avatar);
    }

    $user->forceFill([
      'avatar_media_id' => null,
      'avatar' => null,
    ])->save();

    return $user->fresh(['avatarMedia']);
  }

  public function attachMediaToMember(Member $member, CmsMedia $media): Member
  {
    $member->forceFill([
      'photo_media_id' => $media->id,
      'photo_path' => $media->path,
    ])->save();

    return $member->fresh(['photoMedia']);
  }

  public function uploadForMember(Member $member, UploadedFile $file, User $actor): Member
  {
    $result = $this->mediaAdminService->upload($file, $actor, null, trim($member->fullName().' photo'));

    return $this->attachMediaToMember($member, $result['media']);
  }

  public function clearMember(Member $member): Member
  {
    if ($member->photo_path !== null && $member->photo_media_id === null) {
      Storage::disk('public')->delete($member->photo_path);
    }

    $member->forceFill([
      'photo_media_id' => null,
      'photo_path' => null,
    ])->save();

    return $member->fresh(['photoMedia']);
  }

  public function resolveMedia(string|int $mediaId): CmsMedia
  {
    if (is_numeric($mediaId)) {
      return CmsMedia::query()->findOrFail((int) $mediaId);
    }

    return CmsMedia::query()->where('uuid', (string) $mediaId)->firstOrFail();
  }
}
