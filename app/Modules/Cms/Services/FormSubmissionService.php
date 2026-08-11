<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Enums\FormSubmissionStatus;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Counselling\Models\CounsellingService;
use App\Modules\Counselling\Services\CounsellingCaseService;
use App\Modules\Communications\Services\CommunicationFormBridge;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FormSubmissionService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsNotificationService $notificationService,
    private readonly CounsellingCaseService $counsellingCaseService,
    private readonly CommunicationFormBridge $communicationFormBridge,
    private readonly FormOutboundNotificationService $formOutboundNotificationService,
  ) {}

  public function submit(FormSubmissionType $type, array $payload, ?Request $request = null): CmsFormSubmission
  {
    $request ??= request();

    $submission = CmsFormSubmission::query()->create([
      'type' => $type,
      'status' => FormSubmissionStatus::New,
      'payload' => $payload,
      'submitter_name' => $payload['name'] ?? $payload['fullName'] ?? ($payload['firstName'] ?? null),
      'submitter_email' => $payload['email'] ?? null,
      'source_ip' => $request->ip(),
      'user_agent' => (string) $request->userAgent(),
    ]);

    $this->auditService->record(
      \App\Modules\Cms\Enums\CmsAuditEventType::Created,
      'form_submission',
      $submission->id,
      null,
      null,
      ['type' => $type->value],
    );

    \App\Modules\Cms\Models\CmsFormSubmissionEvent::query()->create([
      'submission_id' => $submission->id,
      'actor_id' => null,
      'event_type' => 'submitted',
      'title' => 'Submission received',
      'body' => ucfirst(str_replace('_', ' ', $type->value)).' received from the public site.',
      'meta' => ['type' => $type->value],
    ]);

    $this->notificationService->notifyFormSubmission($submission);

    try {
      $this->communicationFormBridge->dispatchForSubmission($submission);
    } catch (\Throwable $exception) {
      report($exception);
    }

    try {
      $this->formOutboundNotificationService->sendSmsHook($submission);
      $this->formOutboundNotificationService->sendWhatsAppHook($submission);
    } catch (\Throwable $exception) {
      report($exception);
    }

    if ($type === FormSubmissionType::Counseling) {
      $this->createCounsellingCaseFromSubmission($submission, $payload, $request);
    }

    return $submission;
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function createCounsellingCaseFromSubmission(
    CmsFormSubmission $submission,
    array $payload,
    Request $request,
  ): void {
    try {
      $service = CounsellingService::query()
        ->where('status', 'published')
        ->where('is_visible', true)
        ->orderBy('sort_order')
        ->first();

      if ($service === null) {
        report(new \RuntimeException('No default published counselling service available for form submission '.$submission->uuid));

        return;
      }

      $clientName = trim((string) (
        $payload['fullName']
        ?? $payload['name']
        ?? trim(((string) ($payload['firstName'] ?? '')).' '.((string) ($payload['lastName'] ?? '')))
        ?: 'Guest'
      ));

      $preferredAt = null;
      if (! empty($payload['preferredDate'])) {
        $preferredAt = trim((string) $payload['preferredDate']);
        if (! empty($payload['preferredTime'])) {
          $preferredAt .= ' '.trim((string) $payload['preferredTime']);
        }
      }

      $this->counsellingCaseService->createFromRequest([
        'service_id' => $service->uuid,
        'source_submission_id' => $submission->uuid,
        'client_name' => $clientName,
        'client_email' => (string) ($payload['email'] ?? $submission->submitter_email ?? ''),
        'client_phone' => $payload['phone'] ?? $payload['whatsapp'] ?? null,
        'client_country' => $payload['country'] ?? null,
        'client_gender' => $payload['gender'] ?? null,
        'preferred_counsellor_gender' => $payload['preferredCounselor'] ?? null,
        'reason' => $payload['reason'] ?? $payload['topic'] ?? $payload['message'] ?? null,
        'prayer_request' => $payload['prayerRequest'] ?? $payload['prayerRequests'] ?? null,
        'preferred_at' => $preferredAt,
        'metadata' => [
          'source' => 'cms_form',
          'form_submission_id' => $submission->uuid,
          'contact_method' => $payload['contactMethod'] ?? null,
        ],
      ], $request->user('sanctum'));
    } catch (Throwable $exception) {
      Log::warning('Failed to create counselling case from CMS form submission.', [
        'submission_id' => $submission->uuid,
        'error' => $exception->getMessage(),
      ]);
      report($exception);
    }
  }

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $trashed = $filters['trashed'] ?? null;
    $query = match ($trashed) {
      'only', '1', 1, true, 'true' => CmsFormSubmission::onlyTrashed(),
      'with' => CmsFormSubmission::withTrashed(),
      default => CmsFormSubmission::query(),
    };
    $query->with('assignee')->latest();

    if (! empty($filters['type'])) {
      $query->where('type', $filters['type']);
    }

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('submitter_name', 'like', "%{$search}%")
          ->orWhere('submitter_email', 'like', "%{$search}%");
      });
    }

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->paginate($perPage);
  }
}
