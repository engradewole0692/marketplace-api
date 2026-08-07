<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Modules\Cms\Models\CmsMinistry;
use App\Services\Membership\MemberActivationService;
use App\Services\Membership\MemberManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberLifecycleController extends ApiController
{
  public function startReview(Request $request, Member $member, MemberManagementService $service): JsonResponse
  {
    $this->authorize('approve', $member);
    $member = $service->startReview($member, $request->user(), $request->input('reason'));

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Application review started.',
    );
  }

  public function requireInterview(Request $request, Member $member, MemberManagementService $service): JsonResponse
  {
    $this->authorize('approve', $member);
    $member = $service->requireInterview($member, $request->user(), $request->input('reason'));

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Interview required for this application.',
    );
  }

  public function requestInfo(Request $request, Member $member, MemberManagementService $service): JsonResponse
  {
    $this->authorize('update', $member);
    $validated = $request->validate(['message' => ['required', 'string', 'max:5000']]);
    $member = $service->requestMoreInformation($member, $request->user(), $validated['message']);

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Information request recorded.',
    );
  }

  public function assignMinistry(Request $request, Member $member, MemberActivationService $activationService): JsonResponse
  {
    $this->authorize('update', $member);
    $validated = $request->validate([
      'ministry_id' => ['nullable', 'integer', 'exists:cms_ministries,id'],
      'ministry_uuid' => ['nullable', 'string', 'exists:cms_ministries,uuid'],
      'primary' => ['sometimes', 'boolean'],
    ]);

    $ministryId = $validated['ministry_id'] ?? null;
    if ($ministryId === null && ! empty($validated['ministry_uuid'])) {
      $ministryId = CmsMinistry::query()->where('uuid', $validated['ministry_uuid'])->value('id');
    }

    if ($ministryId === null) {
      throw new \App\Exceptions\BusinessException(
        'A valid ministry is required.',
        \App\Enums\ApiErrorCode::UnprocessableEntity,
        null,
        422,
      );
    }

    $member = $activationService->assignMinistry(
      $member,
      (int) $ministryId,
      $request->user(),
      $validated['primary'] ?? true,
    );

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Ministry assignment saved.',
    );
  }

  public function completeOrientation(Request $request, Member $member, MemberActivationService $activationService): JsonResponse
  {
    $this->authorize('update', $member);
    $validated = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
    $member = $activationService->completeOrientation($member, $request->user(), $validated['notes'] ?? null);

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Orientation progress recorded.',
    );
  }

  public function activate(Request $request, Member $member, MemberActivationService $activationService): JsonResponse
  {
    $this->authorize('activate', $member);
    $member = $activationService->activate($member, $request->user(), $request->input('temporary_password'));

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Member account activated.',
    );
  }

  public function activateWithAutomation(Request $request, Member $member, MemberActivationService $activationService): JsonResponse
  {
    $this->authorize('activate', $member);
    $member = $activationService->runPostApprovalAutomation($member, $request->user());

    return $this->responder->success(
      data: ['member' => new MemberResource($member)],
      message: 'Post-approval automation completed.',
    );
  }
}
