<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Enums\FormSubmissionStatus;
use App\Modules\Cms\Http\Resources\CmsFormSubmissionResource;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Cms\Services\CmsAuditService;
use App\Modules\Cms\Services\CmsFormSubmissionAdminService;
use App\Modules\Cms\Services\FormSubmissionService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CmsFormSubmissionController extends ApiController
{
  public function index(Request $request, FormSubmissionService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsFormSubmission::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CmsFormSubmissionResource::class),
      message: 'Form submissions retrieved.',
    );
  }

  public function show(CmsFormSubmission $submission, CmsFormSubmissionAdminService $adminService): JsonResponse
  {
    $this->authorize('view', $submission);
    $submission->load(['assignee', 'notes.author', 'attachments']);

    return $this->responder->success(
      data: [
        'submission' => new CmsFormSubmissionResource($submission),
        'notes' => $adminService->notes($submission)->map(fn ($note) => [
          'id' => $note->uuid,
          'body' => $note->body,
          'author' => $note->author?->display_name,
          'created_at' => $note->created_at?->toIso8601String(),
        ]),
        'events' => $adminService->events($submission)->map(fn ($event) => [
          'id' => $event->uuid,
          'event_type' => $event->event_type,
          'title' => $event->title,
          'body' => $event->body,
          'actor' => $event->actor?->display_name ?? $event->actor?->name,
          'created_at' => $event->created_at?->toIso8601String(),
        ]),
      ],
      message: 'Form submission retrieved.',
    );
  }

  public function update(Request $request, CmsFormSubmission $submission, CmsAuditService $auditService, CmsFormSubmissionAdminService $adminService): JsonResponse
  {
    $this->authorize('update', $submission);

    $validated = $request->validate([
      'status' => ['sometimes', Rule::enum(FormSubmissionStatus::class)],
      'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
      'assign_to_me' => ['sometimes', 'boolean'],
      'unassign' => ['sometimes', 'boolean'],
    ]);

    $old = $submission->only(['status', 'assigned_to']);

    if (! empty($validated['assign_to_me'])) {
      $validated['assigned_to'] = $request->user()->id;
      unset($validated['assign_to_me']);
    }

    if (! empty($validated['unassign'])) {
      $validated['assigned_to'] = null;
      unset($validated['unassign']);
    }

    $submission->fill(collect($validated)->except(['assign_to_me', 'unassign'])->all());
    if (isset($validated['status'])) {
      $submission->processed_at = now();
      $submission->processed_by = $request->user()->id;
    }
    $submission->save();

    $auditService->record(CmsAuditEventType::Updated, 'form_submission', $submission->id, $request->user(), $old, $submission->only(['status', 'assigned_to']));

    $oldStatus = $old['status'] instanceof FormSubmissionStatus
      ? $old['status']->value
      : $old['status'];
    $newStatus = $submission->status instanceof FormSubmissionStatus
      ? $submission->status->value
      : (string) $submission->status;

    if ($oldStatus !== $newStatus) {
      $adminService->recordEvent(
        $submission,
        $request->user(),
        'status_changed',
        'Status updated',
        'Status set to '.$newStatus,
        ['from' => $oldStatus, 'to' => $newStatus],
      );
    }

    if (($old['assigned_to'] ?? null) !== $submission->assigned_to) {
      $submission->loadMissing('assignee');
      $adminService->recordEvent(
        $submission,
        $request->user(),
        $submission->assigned_to ? 'assigned' : 'unassigned',
        $submission->assigned_to ? 'Assigned to staff' : 'Unassigned',
        $submission->assigned_to
          ? ('Assigned to '.($submission->assignee?->display_name ?? $submission->assignee?->name ?? 'staff'))
          : 'Assignment cleared.',
        ['assigned_to' => $submission->assigned_to],
      );
    }

    return $this->responder->success(
      data: ['submission' => new CmsFormSubmissionResource($submission->fresh(['assignee']))],
      message: 'Form submission updated.',
    );
  }

  public function destroy(Request $request, CmsFormSubmission $submission, CmsFormSubmissionAdminService $adminService): JsonResponse
  {
    $this->authorize('delete', $submission);
    $adminService->recordEvent(
      $submission,
      $request->user(),
      'archived',
      'Submission archived',
      'Moved to trash.',
    );
    $submission->delete();

    return $this->responder->success(message: 'Form submission archived.');
  }

  public function restore(Request $request, string $submission, CmsFormSubmissionAdminService $adminService): JsonResponse
  {
    $record = CmsFormSubmission::withTrashed()->where('uuid', $submission)->firstOrFail();
    $this->authorize('update', $record);
    $record->restore();
    $adminService->recordEvent(
      $record,
      $request->user(),
      'restored',
      'Submission restored',
      'Restored from trash.',
    );

    return $this->responder->success(
      data: ['submission' => new CmsFormSubmissionResource($record->fresh(['assignee']))],
      message: 'Form submission restored.',
    );
  }

  public function addNote(Request $request, CmsFormSubmission $submission, CmsFormSubmissionAdminService $adminService): JsonResponse
  {
    $this->authorize('update', $submission);
    $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
    $note = $adminService->addNote($submission, $request->user(), $validated['body']);

    return $this->responder->success(
      data: ['note' => ['id' => $note->uuid, 'body' => $note->body]],
      message: 'Note added.',
      status: 201,
    );
  }

  public function export(Request $request, CmsFormSubmissionAdminService $adminService): StreamedResponse
  {
    $this->authorize('viewAny', CmsFormSubmission::class);
    $rows = $adminService->export($request->query());

    return response()->streamDownload(function () use ($rows): void {
      $out = fopen('php://output', 'w');
      fputcsv($out, ['id', 'type', 'status', 'name', 'email', 'created_at']);
      foreach ($rows as $row) {
        fputcsv($out, [$row->uuid, $row->type->value, $row->status->value, $row->submitter_name, $row->submitter_email, $row->created_at?->toIso8601String()]);
      }
      fclose($out);
    }, 'form-submissions.csv', ['Content-Type' => 'text/csv']);
  }
}
