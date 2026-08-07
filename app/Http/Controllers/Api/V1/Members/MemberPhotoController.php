<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Services\Profile\ProfilePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberPhotoController extends ApiController
{
  public function upload(Request $request, Member $member, ProfilePhotoService $service): JsonResponse
  {
    $this->authorize('update', $member);

    $validated = $request->validate([
      'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
    ]);

    $member = $service->uploadForMember($member, $validated['photo'], $request->user());

    return $this->responder->success(
      data: ['member' => new MemberResource($member->loadMissing(['photoMedia']))],
      message: 'Member photo uploaded.',
    );
  }

  public function attach(Request $request, Member $member, ProfilePhotoService $service): JsonResponse
  {
    $this->authorize('update', $member);

    $validated = $request->validate([
      'media_id' => ['required', 'string'],
    ]);

    $media = $service->resolveMedia($validated['media_id']);
    $member = $service->attachMediaToMember($member, $media);

    return $this->responder->success(
      data: ['member' => new MemberResource($member->loadMissing(['photoMedia']))],
      message: 'Member photo attached from media library.',
    );
  }

  public function destroy(Request $request, Member $member, ProfilePhotoService $service): JsonResponse
  {
    $this->authorize('update', $member);

    $member = $service->clearMember($member);

    return $this->responder->success(
      data: ['member' => new MemberResource($member->loadMissing(['photoMedia']))],
      message: 'Member photo removed.',
    );
  }
}
