<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Enums\CertificateStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventCertificateTemplate;
use App\Modules\Events\Models\EventRegistration;
use App\Services\Certificates\CertificatePdfEngine;
use App\Services\Membership\MemberNotificationQueueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CertificateService implements ServiceContract
{
  public function __construct(
    private readonly MemberNotificationQueueService $memberNotificationQueueService,
    private readonly CertificatePdfEngine $pdfEngine,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateTemplates(array $filters = []): LengthAwarePaginator
  {
    $query = EventCertificateTemplate::query()->with('event')->orderBy('sort_order');

    if (! empty($filters['event_id'])) {
      $query->where('event_id', $filters['event_id']);
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createTemplate(array $data, User $actor): EventCertificateTemplate
  {
    $data['slug'] ??= Str::slug($data['name']);
    $data['created_by_user_id'] = $actor->id;
    $data['updated_by_user_id'] = $actor->id;

    return EventCertificateTemplate::query()->create($data)->fresh();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateTemplate(EventCertificateTemplate $template, array $data, User $actor): EventCertificateTemplate
  {
    if (isset($data['name']) && empty($data['slug'])) {
      $data['slug'] = Str::slug($data['name']);
    }
    $data['updated_by_user_id'] = $actor->id;

    $template->fill($data);
    $template->save();

    return $template->fresh();
  }

  public function deleteTemplate(EventCertificateTemplate $template): void
  {
    $template->delete();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateIssuances(array $filters = []): LengthAwarePaginator
  {
    $query = EventCertificateIssuance::query()
      ->with(['event', 'registration', 'member'])
      ->orderByDesc('issued_at');

    foreach (['event_id', 'registration_id', 'member_id', 'status'] as $field) {
      if (! empty($filters[$field])) {
        $query->where($field, $filters[$field]);
      }
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function issue(EventRegistration $registration, User $actor, ?int $templateId = null): EventCertificateIssuance
  {
    return DB::transaction(function () use ($registration, $actor, $templateId): EventCertificateIssuance {
      $registration->loadMissing(['event', 'member']);
      $event = $registration->event;
      if ($event === null) {
        throw ValidationException::withMessages(['registration' => ['Registration is missing its event.']]);
      }

      $existing = EventCertificateIssuance::query()
        ->where('event_id', $event->id)
        ->where('registration_id', $registration->id)
        ->first();
      if ($existing !== null) {
        return $existing;
      }

      $template = $this->resolveTemplate($event, $templateId);
      $verificationCode = strtoupper(Str::random(12));
      $certificateNumber = sprintf('CERT-%s-%s', $event->id, str_pad((string) $registration->id, 6, '0', STR_PAD_LEFT));

      $issuance = EventCertificateIssuance::query()->create([
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'certificate_number' => $certificateNumber,
        'verification_code' => $verificationCode,
        'status' => CertificateStatus::Issued,
        'issued_at' => now(),
        'issued_by_user_id' => $actor->id,
        'template_id' => $template?->id,
      ]);

      $media = $this->generatePdfMedia($event, $registration, $issuance, $template, $actor);
      $issuance->certificate_media_id = $media?->id;
      $issuance->save();

      if ($registration->member !== null) {
        $this->memberNotificationQueueService->queue(
          $registration->member,
          'email',
          'event_certificate_issued',
          [
            'event_id' => $event->id,
            'registration_id' => $registration->id,
            'certificate_number' => $issuance->certificate_number,
            'verification_code' => $issuance->verification_code,
          ],
        );
      }

      return $issuance->fresh(['event', 'registration', 'member']);
    });
  }

  public function reissue(EventCertificateIssuance $issuance, User $actor): EventCertificateIssuance
  {
    return DB::transaction(function () use ($issuance, $actor): EventCertificateIssuance {
      $issuance->loadMissing(['registration.event', 'registration.member']);
      $registration = $issuance->registration;
      if ($registration === null) {
        throw ValidationException::withMessages(['issuance' => ['Certificate has no registration.']]);
      }

      $event = $registration->event;
      $template = $issuance->template_id
        ? EventCertificateTemplate::query()->find($issuance->template_id)
        : $this->resolveTemplate($event, null);

      $verificationCode = strtoupper(Str::random(12));
      $certificateNumber = $issuance->certificate_number.'-R'.($issuance->reissued_from_id ? '2' : '1');

      $new = EventCertificateIssuance::query()->create([
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'certificate_number' => $certificateNumber,
        'verification_code' => $verificationCode,
        'status' => CertificateStatus::Issued,
        'issued_at' => now(),
        'issued_by_user_id' => $actor->id,
        'template_id' => $template?->id,
        'reissued_from_id' => $issuance->id,
      ]);

      $media = $this->generatePdfMedia($event, $registration, $new, $template, $actor);
      $new->certificate_media_id = $media?->id;
      $new->save();

      $issuance->status = CertificateStatus::Revoked;
      $issuance->revoked_at = now();
      $issuance->revoked_by_user_id = $actor->id;
      $issuance->save();

      return $new->fresh();
    });
  }

  /**
   * @return array{issued: int, skipped: int}
   */
  public function batchIssue(int $eventId, User $actor, bool $onlyAttended = true): array
  {
    $event = Event::query()->findOrFail($eventId);

    $query = EventRegistration::query()->where('event_id', $event->id);

    if ($onlyAttended) {
      $query->whereIn('status', [RegistrationStatus::Attended->value, RegistrationStatus::CheckedIn->value]);
    }

    $issued = 0;
    $skipped = 0;

    foreach ($query->cursor() as $registration) {
      $exists = EventCertificateIssuance::query()
        ->where('event_id', $event->id)
        ->where('registration_id', $registration->id)
        ->exists();

      if ($exists) {
        $skipped++;
        continue;
      }

      try {
        $this->issue($registration, $actor);
        $issued++;
      } catch (\Throwable) {
        $skipped++;
      }
    }

    return ['issued' => $issued, 'skipped' => $skipped];
  }

  /**
   * @return array<string, mixed>|null
   */
  public function verify(string $verificationCode): ?array
  {
    /** @var EventCertificateIssuance|null $issuance */
    $issuance = EventCertificateIssuance::query()
      ->with(['event', 'registration', 'member'])
      ->where('verification_code', $verificationCode)
      ->first();

    if ($issuance === null) {
      return null;
    }

    if ($issuance->status === CertificateStatus::Revoked) {
      return null;
    }

    $issuance->increment('download_count');

    return [
      'certificate_number' => $issuance->certificate_number,
      'verification_code' => $issuance->verification_code,
      'issued_at' => $issuance->issued_at?->toIso8601String(),
      'event' => $issuance->event ? [
        'id' => $issuance->event->uuid,
        'title' => $issuance->event->title,
        'starts_at' => $issuance->event->starts_at?->toIso8601String(),
      ] : null,
      'recipient' => [
        'name' => $issuance->registration?->contactName(),
        'email' => $issuance->registration?->contactEmail(),
        'is_member' => $issuance->member_id !== null,
      ],
      'certificate_url' => $issuance->certificate_url,
    ];
  }

  private function resolveTemplate(?Event $event, ?int $templateId): ?EventCertificateTemplate
  {
    if ($templateId !== null) {
      return EventCertificateTemplate::query()->find($templateId);
    }

    if ($event === null) {
      return null;
    }

    if ($event->certificate_template_id !== null) {
      return EventCertificateTemplate::query()->find($event->certificate_template_id);
    }

    return EventCertificateTemplate::query()
      ->where(function ($query) use ($event): void {
        $query->where('event_id', $event->id)->orWhereNull('event_id');
      })
      ->where('is_active', true)
      ->orderBy('sort_order')
      ->first();
  }

  private function generatePdfMedia(
    Event $event,
    EventRegistration $registration,
    EventCertificateIssuance $issuance,
    ?EventCertificateTemplate $template,
    User $actor,
  ): ?CmsMedia {
    $template?->loadMissing(['backgroundMedia']);
    $verificationUrl = URL::to('/certificate/'.$issuance->verification_code);

    $placeholders = [
      '{{name}}' => e((string) ($registration->contactName() ?? 'Recipient')),
      '{{member_name}}' => e((string) ($registration->contactName() ?? 'Recipient')),
      '{{event}}' => e($event->title ?? ''),
      '{{course}}' => e($event->title ?? ''),
      '{{date}}' => e(Carbon::parse($event->starts_at ?? now())->toFormattedDateString()),
      '{{registration_number}}' => e($registration->registration_number),
      '{{certificate_number}}' => e($issuance->certificate_number),
      '{{verification_url}}' => e($verificationUrl),
    ];

    $background = $template?->backgroundMedia?->url();
    $assets = [
      'background_url' => $background
        ? (str_starts_with($background, 'http') ? $background : URL::to($background))
        : null,
    ];

    $htmlBody = ($template !== null && $template->html_body !== '')
      ? (string) $template->html_body
      : '<h1>Certificate of Attendance</h1><p>Awarded to</p><h2>{{name}}</h2>'
        .'<p>For attending <strong>{{event}}</strong> on {{date}}.</p>'
        .'<p>Registration: {{registration_number}}</p>'
        .'<div style="margin-top:24px">{{qr}}</div>'
        .'<p style="margin-top:16px;font-size:12px;">Verify at: {{verification_url}}</p>';

    return $this->pdfEngine->renderToMedia(
      $htmlBody,
      $placeholders,
      $issuance->certificate_number,
      $actor,
      $assets,
      'certificates/events',
    );
  }
}
