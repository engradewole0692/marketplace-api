<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Member;
use App\Models\MemberOnboardingChecklistItem;
use App\Services\Membership\MemberOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberOnboardingController extends ApiController
{
  public function index(Member $member, MemberOnboardingService $service): JsonResponse
  {
    $this->authorize('update', $member);

    $items = collect($service->checklist($member))->map(fn (MemberOnboardingChecklistItem $item) => [
      'id' => $item->uuid,
      'step_key' => $item->step_key,
      'label' => $item->label,
      'is_completed' => $item->is_completed,
      'completed_at' => $item->completed_at?->toIso8601String(),
      'completed_by' => $item->completer?->display_name ?? $item->completer?->name,
      'notes' => $item->notes,
      'sort_order' => $item->sort_order,
    ]);

    return $this->responder->success(
      data: ['checklist' => $items->values()],
      message: 'Onboarding checklist retrieved.',
    );
  }

  public function updateStep(
    Request $request,
    Member $member,
    string $stepKey,
    MemberOnboardingService $service,
  ): JsonResponse {
    $this->authorize('update', $member);

    $validated = $request->validate([
      'is_completed' => ['required', 'boolean'],
      'notes' => ['nullable', 'string', 'max:5000'],
    ]);

    $item = $service->markStep(
      $member,
      $stepKey,
      (bool) $validated['is_completed'],
      $request->user(),
      $validated['notes'] ?? null,
    );

    return $this->responder->success(
      data: [
        'item' => [
          'id' => $item->uuid,
          'step_key' => $item->step_key,
          'label' => $item->label,
          'is_completed' => $item->is_completed,
          'completed_at' => $item->completed_at?->toIso8601String(),
          'notes' => $item->notes,
        ],
      ],
      message: 'Onboarding step updated.',
    );
  }
}
