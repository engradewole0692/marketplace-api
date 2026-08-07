<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\MemberInterviewResource;
use App\Models\Member;
use App\Models\MemberInterview;
use App\Services\Membership\MemberInterviewService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MemberInterviewController extends ApiController
{
  public function index(Request $request, MemberInterviewService $service): JsonResponse
  {
    $this->authorize('viewAny', MemberInterview::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), MemberInterviewResource::class),
      message: 'Interviews retrieved.',
    );
  }

  public function store(Request $request, Member $member, MemberInterviewService $service): JsonResponse
  {
    $this->authorize('create', MemberInterview::class);

    $validated = $request->validate([
      'scheduled_date' => ['required', 'date'],
      'scheduled_time' => ['nullable', 'date_format:H:i'],
      'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
      'timezone' => ['nullable', 'string', 'max:64'],
      'interview_type' => ['nullable', 'string', Rule::in(['physical', 'online', 'virtual'])],
      'interviewer_id' => ['nullable', 'integer', 'exists:users,id'],
      'interviewer_ids' => ['nullable', 'array'],
      'interviewer_ids.*' => ['integer', 'exists:users,id'],
      'external_interviewer_name' => ['nullable', 'string', 'max:255'],
      'meeting_link' => ['nullable', 'string', 'max:500'],
      'meeting_platform' => ['nullable', 'string', 'max:80'],
      'meeting_password' => ['nullable', 'string', 'max:120'],
      'physical_location' => ['nullable', 'string', 'max:500'],
      'venue' => ['nullable', 'string', 'max:500'],
      'remarks' => ['nullable', 'string', 'max:5000'],
      'instructions' => ['nullable', 'string', 'max:5000'],
      'parent_interview_id' => ['nullable', 'integer', 'exists:member_interviews,id'],
    ]);

    if (($validated['interview_type'] ?? null) === 'virtual') {
      $validated['interview_type'] = 'online';
    }

    $interview = $service->schedule($member, $validated, $request->user());

    return $this->responder->success(
      data: ['interview' => new MemberInterviewResource($interview)],
      message: 'Interview scheduled.',
      status: 201,
    );
  }

  public function update(Request $request, MemberInterview $interview, MemberInterviewService $service): JsonResponse
  {
    $this->authorize('update', $interview);

    $validated = $request->validate([
      'status' => ['sometimes', 'string', Rule::in([
        'pending',
        'scheduled',
        'invitation_sent',
        'confirmed',
        'completed',
        'passed',
        'failed',
        'missed',
        'cancelled',
        'rescheduled',
        'awaiting_review',
      ])],
      'scheduled_date' => ['sometimes', 'nullable', 'date'],
      'scheduled_time' => ['sometimes', 'nullable', 'date_format:H:i'],
      'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:15', 'max:480'],
      'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
      'interview_type' => ['sometimes', 'nullable', 'string', Rule::in(['physical', 'online', 'virtual'])],
      'interviewer_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
      'interviewer_ids' => ['sometimes', 'nullable', 'array'],
      'interviewer_ids.*' => ['integer', 'exists:users,id'],
      'external_interviewer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
      'meeting_link' => ['sometimes', 'nullable', 'string', 'max:500'],
      'meeting_platform' => ['sometimes', 'nullable', 'string', 'max:80'],
      'meeting_password' => ['sometimes', 'nullable', 'string', 'max:120'],
      'physical_location' => ['sometimes', 'nullable', 'string', 'max:500'],
      'venue' => ['sometimes', 'nullable', 'string', 'max:500'],
      'remarks' => ['sometimes', 'nullable', 'string', 'max:5000'],
      'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
      'result' => ['sometimes', 'nullable', 'string', 'max:80'],
    ]);

    if (($validated['interview_type'] ?? null) === 'virtual') {
      $validated['interview_type'] = 'online';
    }

    $interview = $service->update($interview, $validated, $request->user());

    return $this->responder->success(
      data: ['interview' => new MemberInterviewResource($interview)],
      message: 'Interview updated.',
    );
  }
}
