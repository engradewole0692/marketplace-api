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

    return $this->responder->success(
      data: ['estimated_count' => $count],
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
      'recipient_filters.event_id' => ['nullable', 'integer'],
      'recipient_filters.course_id' => ['nullable', 'integer'],
    ]);

    $job = $service->create($validated, $request->user());

    return $this->responder->created(
      data: ['job' => $this->transform($job)],
      message: 'Bulk email queued.',
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
      'started_at' => $j->started_at?->toIso8601String(),
      'completed_at' => $j->completed_at?->toIso8601String(),
      'created_at' => $j->created_at?->toIso8601String(),
    ];
  }
}
