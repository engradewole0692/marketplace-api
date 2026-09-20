<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Services\BulkEmailService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BulkEmailController extends ApiController
{
  public function index(Request $request, BulkEmailService $service): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);

    $paginator = $service->paginate();

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, fn ($j) => $this->transform($j)),
      message: 'Bulk email jobs retrieved.',
    );
  }

  public function estimate(Request $request, BulkEmailService $service): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);

    $validated = $request->validate([
      'recipient_filters' => ['required', 'array'],
    ]);

    $count = $service->estimateCount($validated['recipient_filters']);
    $preview = $service->preview($validated['recipient_filters']);

    return $this->responder->success(
      data: array_merge(['estimated_count' => $count], $preview),
      message: 'Recipient estimate calculated.',
    );
  }

  public function store(Request $request, BulkEmailService $service): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);

    $validated = $request->validate([
      'subject' => ['required', 'string', 'max:255'],
      'html_body' => ['required', 'string'],
      'text_body' => ['nullable', 'string'],
      'from_name' => ['nullable', 'string', 'max:100'],
      'from_email' => ['nullable', 'email', 'max:255'],
      'recipient_filters' => ['required', 'array'],
      'recipient_filters.audience' => ['nullable', 'string', 'in:all,visitors,members,staff,admins'],
      'recipient_filters.country_id' => ['nullable', 'integer'],
      'recipient_filters.role_slug' => ['nullable', 'string'],
      'recipient_filters.ministry_id' => ['nullable', 'integer'],
      'recipient_filters.event_id' => ['nullable'],
      'recipient_filters.event_session_id' => ['nullable', 'string'],
      'recipient_filters.gender' => ['nullable', 'string', 'max:40'],
      'recipient_filters.category' => ['nullable', 'string', 'max:80'],
      'recipient_filters.accommodation' => ['nullable'],
      'recipient_filters.transport' => ['nullable'],
      'recipient_filters.payment' => ['nullable', 'string', 'max:40'],
      'recipient_filters.attendance' => ['nullable', 'string', 'max:40'],
      'recipient_filters.country' => ['nullable', 'string', 'max:120'],
      'recipient_filters.course_id' => ['nullable', 'integer'],
      'recipient_filters.module' => ['nullable', 'string', 'max:40'],
      'recipient_filters.registration_status' => ['nullable', 'string', 'max:40'],
      'recipient_filters.state_region' => ['nullable', 'string', 'max:120'],
      'recipient_filters.city' => ['nullable', 'string', 'max:120'],
      'recipient_filters.occupancy_type' => ['nullable', 'string', 'max:20'],
      'recipient_filters.seat_counting' => ['nullable', 'string', 'max:10'],
      'recipient_filters.school_id' => ['nullable', 'integer'],
      'recipient_filters.program_module_id' => ['nullable', 'integer'],
      'recipient_filters.lesson_id' => ['nullable', 'integer'],
      'recipient_filters.enrollment_status' => ['nullable', 'string', 'max:40'],
      'recipient_filters.assignment_status' => ['nullable', 'string', 'max:40'],
      'recipient_filters.learner_type' => ['nullable', 'string', 'max:20'],
      'recipient_filters.status' => ['nullable', 'string', 'max:40'],
      'recipient_filters.counsellor_id' => ['nullable', 'integer'],
      'recipient_filters.category_id' => ['nullable', 'integer'],
      'recipient_filters.assigned' => ['nullable', 'string', 'max:10'],
      'recipient_filters.client_type' => ['nullable', 'string', 'max:40'],
      'recipient_filters.form_type' => ['nullable', 'string', 'max:40'],
      'recipient_filters.approval_status' => ['nullable', 'string', 'max:40'],
      'recipient_filters.interview_status' => ['nullable', 'string', 'max:40'],
      'channel' => ['nullable', 'in:email,sms,whatsapp'],
      'scheduled_at' => ['nullable', 'date'],
    ]);

    $job = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['job' => $this->transform($job)],
      message: 'Bulk email queued.',
      status: 201,
    );
  }

  public function show(BulkEmailJob $bulkEmailJob): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);

    return $this->responder->success(
      data: ['job' => $this->transform($bulkEmailJob->load('creator:id,uuid,name,email'))],
      message: 'Bulk email job retrieved.',
    );
  }

  public function cancel(BulkEmailJob $bulkEmailJob, BulkEmailService $service): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);

    $service->cancel($bulkEmailJob);

    return $this->responder->success(message: 'Bulk email job cancelled.');
  }

  private function transform(BulkEmailJob $j): array
  {
    return [
      'id' => $j->uuid,
      'subject' => $j->subject,
      'status' => $j->status,
      'from_name' => $j->from_name,
      'from_email' => $j->from_email,
      'recipient_filters' => $j->recipient_filters,
      'estimated_count' => $j->estimated_count,
      'sent_count' => $j->sent_count,
      'failed_count' => $j->failed_count,
      'created_by' => $j->relationLoaded('creator') ? $j->creator?->name : null,
      'queued_at' => $j->queued_at?->toIso8601String(),
      'scheduled_at' => $j->scheduled_at?->toIso8601String(),
      'started_at' => $j->started_at?->toIso8601String(),
      'completed_at' => $j->completed_at?->toIso8601String(),
      'created_at' => $j->created_at?->toIso8601String(),
    ];
  }
}
