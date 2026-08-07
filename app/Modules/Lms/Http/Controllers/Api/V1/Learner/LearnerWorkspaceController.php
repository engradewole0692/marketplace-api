<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Learner;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsFormSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visitor (learner) personal workspace helpers — scoped to authenticated user email.
 */
final class LearnerWorkspaceController extends ApiController
{
  public function prayerRequests(Request $request): JsonResponse
  {
    return $this->formRequests($request, FormSubmissionType::Prayer);
  }

  public function counsellingRequests(Request $request): JsonResponse
  {
    return $this->formRequests($request, FormSubmissionType::Counseling);
  }

  public function notifications(Request $request): JsonResponse
  {
    $user = $request->user();
    $email = strtolower((string) ($user?->email ?? ''));

    // Reuse LMS experience notifications when available; keep empty-safe contract.
    $items = [];
    if ($email !== '') {
      // Soft personal notifications from form acknowledgements (same email only).
      $items = CmsFormSubmission::query()
        ->whereIn('type', [
          FormSubmissionType::Prayer->value,
          FormSubmissionType::Counseling->value,
        ])
        ->whereRaw('LOWER(submitter_email) = ?', [$email])
        ->latest('id')
        ->limit(40)
        ->get()
        ->map(static function (CmsFormSubmission $row): array {
          return [
            'id' => (string) $row->uuid,
            'title' => $row->type instanceof FormSubmissionType
              ? ucfirst($row->type->value).' update'
              : 'Workspace update',
            'body' => 'Status: '.(string) ($row->status?->value ?? $row->status),
            'type' => $row->type instanceof FormSubmissionType ? $row->type->value : (string) $row->type,
            'occurred_at' => optional($row->updated_at)?->toIso8601String(),
            'read' => false,
          ];
        })
        ->all();
    }

    return $this->responder->success(
      data: ['notifications' => $items],
      message: 'Visitor notifications loaded.',
    );
  }

  private function formRequests(Request $request, FormSubmissionType $type): JsonResponse
  {
    $user = $request->user();
    $email = strtolower((string) ($user?->email ?? ''));

    if ($email === '') {
      return $this->responder->success(data: ['requests' => []], message: 'No email on account.');
    }

    $rows = CmsFormSubmission::query()
      ->where('type', $type->value)
      ->whereRaw('LOWER(submitter_email) = ?', [$email])
      ->latest('id')
      ->limit(50)
      ->get()
      ->map(static function (CmsFormSubmission $row): array {
        return [
          'id' => (string) $row->uuid,
          'type' => $row->type instanceof FormSubmissionType ? $row->type->value : (string) $row->type,
          'status' => $row->status?->value ?? (string) $row->status,
          'payload' => $row->payload,
          'created_at' => optional($row->created_at)?->toIso8601String(),
          'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
      })
      ->all();

    return $this->responder->success(
      data: ['requests' => $rows],
      message: 'Requests loaded.',
    );
  }
}
