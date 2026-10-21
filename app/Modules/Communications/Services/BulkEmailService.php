<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Models\User;
use App\Modules\Communications\Jobs\SendBulkEmailBatchJob;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\BulkEmailRecipient;
use App\Modules\Communications\Models\CommunicationWhatsAppTemplate;
use App\Modules\Communications\Support\DeliveryStatus;
use Illuminate\Validation\ValidationException;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Support\MembershipClassification;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class BulkEmailService
{
  public function paginate(int $perPage = 20): LengthAwarePaginator
  {
    return BulkEmailJob::query()->with('creator:id,uuid,name,email')->latest()->paginate($perPage);
  }

  /**
   * Build recipient query from filters (does NOT execute — returns builder for counting or iteration).
   *
   * @param  array<string, mixed>  $filters
   */
  public function buildRecipientQuery(array $filters): Builder
  {
    $query = User::query()
      ->whereNotNull('email')
      ->where('email', '!=', '')
      ->where('status', '!=', 'inactive');

    if (! empty($filters['audience'])) {
      match ($filters['audience']) {
        'visitors' => $query->whereDoesntHave('member', fn ($q) => $q->where('approval_status', 'approved')),
        'members' => $query->whereHas('member', fn ($q) => $q->where('status', 'active')),
        'staff' => $query->whereHas('roles', fn ($q) => $q->where('slug', 'staff')),
        'admins' => $query->whereHas('roles', fn ($q) => $q->whereIn('slug', ['admin', 'super_admin'])),
        default => null,
      };
    }

    if (! empty($filters['country_id'])) {
      $query->whereHas('member', fn ($q) => $q->where('country_id', $filters['country_id']));
    }

    if (! empty($filters['role_slug'])) {
      $query->whereHas('roles', fn ($q) => $q->where('slug', $filters['role_slug']));
    }

    if (! empty($filters['ministry_id'])) {
      $query->whereHas('member', fn ($q) => $q->where('ministry_id', $filters['ministry_id']));
    }

    if (! empty($filters['course_id'])) {
      $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $filters['course_id']));
    }

    return $query->select('id', 'uuid', 'name', 'first_name', 'email', 'phone');
  }

  /**
   * Unique recipient records for estimate and dispatch.
   *
   * @param  array<string, mixed>  $filters
   * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
   */
  public function collectRecipients(array $filters): Collection
  {
    return app(RecipientAudienceService::class)->collect($filters);
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
   */
  public function collectUserRecipients(array $filters): Collection
  {
    return $this->buildRecipientQuery($filters)->get()->map(fn (User $user): array => [
      'email' => $user->email,
      'phone' => $user->phone ?? null,
      'name' => $user->name,
      'first_name' => $user->first_name,
      'user_id' => $user->id,
      'person_id' => null,
    ])->values();
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
   */
  public function collectEventRecipients(array $filters): Collection
  {
    return $this->eventRegistrationRecipients($filters);
  }

  /**
   * Count estimated recipients without creating the job.
   */
  public function estimateCount(array $filters): int
  {
    return (int) (app(RecipientAudienceService::class)->preview($filters)['valid_count'] ?? 0);
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public function preview(array $filters): array
  {
    return app(RecipientAudienceService::class)->preview($filters);
  }

  /**
   * Create a campaign as a draft, a scheduled send, or an immediate (queued) send.
   *
   * @param  array<string, mixed>  $data  action: draft|send|schedule
   */
  public function create(array $data, User $actor): BulkEmailJob
  {
    [$filters, $channels, $messageType] = $this->normalizeInput($data);
    $action = $this->action($data);

    $job = new BulkEmailJob([
      'uuid' => Str::uuid()->toString(),
      'created_by' => $actor->id,
      'status' => BulkEmailJob::STATUS_DRAFT,
    ]);
    $this->fillContent($job, $data, $filters, $channels, $messageType);

    if ($action === 'draft') {
      $job->save();

      return $job;
    }

    return $this->launch($job, $data, $action);
  }

  /**
   * Unsaved campaign for previews and test sends.
   *
   * @param  array<string, mixed>  $data
   */
  public function makeUnsaved(array $data): BulkEmailJob
  {
    [$filters, $channels, $messageType] = $this->normalizeInput($data);
    $job = new BulkEmailJob();
    $this->fillContent($job, $data, $filters, $channels, $messageType);

    return $job;
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateDraft(BulkEmailJob $job, array $data): BulkEmailJob
  {
    if ($job->status !== BulkEmailJob::STATUS_DRAFT) {
      throw ValidationException::withMessages(['status' => 'Only draft campaigns can be edited.']);
    }
    [$filters, $channels, $messageType] = $this->normalizeInput(array_merge([
      'recipient_filters' => $job->recipient_filters,
      'channels' => $job->channelList(),
      'message_type' => $job->message_type,
    ], $data));
    $this->fillContent($job, array_merge($job->only(['subject', 'html_body', 'text_body', 'from_name', 'from_email', 'whatsapp_template']), $data), $filters, $channels, $messageType);
    $job->save();

    return $job->fresh() ?? $job;
  }

  /**
   * Send or schedule an existing draft.
   *
   * @param  array<string, mixed>  $data
   */
  public function launchDraft(BulkEmailJob $job, array $data): BulkEmailJob
  {
    if ($job->status !== BulkEmailJob::STATUS_DRAFT) {
      throw ValidationException::withMessages(['status' => 'Only draft campaigns can be sent or scheduled.']);
    }

    return $this->launch($job, $data, $this->action($data));
  }

  /**
   * Validate preconditions, snapshot the preview counts and queue or schedule the campaign.
   *
   * @param  array<string, mixed>  $data
   */
  private function launch(BulkEmailJob $job, array $data, string $action): BulkEmailJob
  {
    $this->assertSendable($job);

    $resolved = app(RecipientAudienceService::class)->resolve($job->recipient_filters ?? [], $job->channelList(), (string) $job->message_type, 0);
    $total = (int) $resolved['summary']['total_messages'];
    if ($total === 0) {
      throw ValidationException::withMessages(['recipient_filters' => 'No eligible recipients match these filters for the selected channels.']);
    }
    $threshold = max(1, (int) config('communications.campaigns.confirmation_threshold', 100));
    if ($total >= $threshold && (int) ($data['confirm_total'] ?? -1) !== $total) {
      throw ValidationException::withMessages([
        'confirm_total' => "This campaign sends {$total} messages. Confirm by submitting confirm_total={$total}.",
      ]);
    }

    $scheduledAt = $action === 'schedule' ? $this->parseSchedule($data['scheduled_at'] ?? null) : null;
    if ($action === 'schedule' && $scheduledAt === null) {
      throw ValidationException::withMessages(['scheduled_at' => 'Choose a future date and time (Africa/Lagos).']);
    }

    $job->fill([
      'estimated_count' => $total,
      'preview_snapshot' => [
        'previewed_at' => now()->toIso8601String(),
        'summary' => $resolved['summary'],
        'channels' => $resolved['channels'],
      ],
      'status' => $scheduledAt !== null ? BulkEmailJob::STATUS_SCHEDULED : BulkEmailJob::STATUS_QUEUED,
      'scheduled_at' => $scheduledAt,
      'queued_at' => $scheduledAt !== null ? null : now(),
    ])->save();

    if ($scheduledAt === null) {
      SendBulkEmailBatchJob::dispatch($job->id)->onQueue((string) config('communications.campaigns.queue', 'default'));
    }

    return $job->fresh() ?? $job;
  }

  /**
   * Move due scheduled campaigns onto the queue (run every minute by the scheduler).
   */
  public function dispatchDue(): int
  {
    $count = 0;
    BulkEmailJob::query()
      ->where('status', BulkEmailJob::STATUS_SCHEDULED)
      ->where('scheduled_at', '<=', now())
      ->orderBy('scheduled_at')
      ->limit(50)
      ->get()
      ->each(function (BulkEmailJob $job) use (&$count): void {
        $claimed = BulkEmailJob::query()->whereKey($job->id)->where('status', BulkEmailJob::STATUS_SCHEDULED)
          ->update(['status' => BulkEmailJob::STATUS_QUEUED, 'queued_at' => now(), 'updated_at' => now()]);
        if ($claimed === 1) {
          SendBulkEmailBatchJob::dispatch($job->id)->onQueue((string) config('communications.campaigns.queue', 'default'));
          $count++;
        }
      });

    return $count;
  }

  public function assertSendable(BulkEmailJob $job): void
  {
    $errors = [];
    if (! app(QueueHealthService::class)->canQueueCampaigns()) {
      $errors['queue'] = 'Campaigns cannot run on the sync queue in production. Configure QUEUE_CONNECTION and a queue worker.';
    }
    $providers = app(ProviderConfigurationService::class);
    foreach ($job->channelList() as $channel) {
      if ($channel === 'email') {
        if (trim(strip_tags((string) $job->html_body)) === '') {
          $errors['html_body'] = 'Email body is required.';
        }
        continue;
      }
      if (! $providers->channelConfigured($channel)) {
        $errors['channels'] = strtoupper($channel).' provider is not configured. See Communications → Providers.';
      }
      if ($channel === 'sms' && trim((string) ($job->text_body ?: strip_tags((string) $job->html_body))) === '') {
        $errors['text_body'] = 'SMS message text is required.';
      }
      if ($channel === 'whatsapp') {
        $template = is_array($job->whatsapp_template) ? $job->whatsapp_template : [];
        $model = ! empty($template['template_id'])
          ? CommunicationWhatsAppTemplate::query()->where('uuid', $template['template_id'])->first()
          : null;
        $provider = app(\App\Modules\Cms\Contracts\WhatsAppNotifierContract::class)->providerName();
        if ($model === null || ! $model->isUsable() || $model->provider !== $provider) {
          $errors['whatsapp_template'] = 'Select an approved WhatsApp template for the configured provider before sending.';
        } elseif ($job->message_type === 'transactional' && $model->category === 'marketing') {
          $errors['whatsapp_template'] = 'Marketing templates cannot be sent as transactional messages.';
        }
      }
    }
    if ($errors !== []) {
      throw ValidationException::withMessages($errors);
    }
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array{0: array<string, mixed>, 1: list<string>, 2: string}
   */
  private function normalizeInput(array $data): array
  {
    $filters = is_array($data['recipient_filters'] ?? null) ? $data['recipient_filters'] : [];
    $channels = $data['channels'] ?? null;
    if (! is_array($channels) || $channels === []) {
      $channels = [$data['channel'] ?? $filters['channel'] ?? 'email'];
    }
    $channels = array_values(array_unique(array_intersect(['email', 'sms', 'whatsapp'], array_map('strtolower', array_map('strval', $channels)))));
    if ($channels === []) {
      throw ValidationException::withMessages(['channels' => 'Select at least one channel.']);
    }
    $filters['channel'] = $channels[0];
    $filters['module'] = app(RecipientAudienceService::class)->moduleOf($filters);
    $messageType = ($data['message_type'] ?? 'promotional') === 'transactional' ? 'transactional' : 'promotional';

    return [$filters, $channels, $messageType];
  }

  /**
   * @param  array<string, mixed>  $data
   * @param  array<string, mixed>  $filters
   * @param  list<string>  $channels
   */
  private function fillContent(BulkEmailJob $job, array $data, array $filters, array $channels, string $messageType): void
  {
    $text = isset($data['text_body']) ? (string) $data['text_body'] : null;
    $html = (string) ($data['html_body'] ?? '');
    if ($html === '' && $text !== null) {
      $html = nl2br(e($text));
    }
    $template = null;
    if (! empty($data['whatsapp_template']['template_id'])) {
      $model = CommunicationWhatsAppTemplate::query()->where('uuid', $data['whatsapp_template']['template_id'])->first();
      if ($model !== null) {
        $values = (array) ($data['whatsapp_template']['values'] ?? []);
        $template = [
          'template_id' => $model->uuid,
          'name' => $model->name,
          'language' => $model->language,
          'category' => $model->category,
          'provider_template_id' => $model->provider_template_id,
          'parameters' => array_map(function (array $param) use ($values): array {
            $key = (string) ($param['key'] ?? '');
            $mapped = (string) ($values[$key] ?? $param['source'] ?? 'fixed');

            return str_starts_with($mapped, 'variable:')
              ? ['key' => $key, 'source' => $mapped]
              : ['key' => $key, 'source' => 'fixed', 'value' => mb_substr($mapped === 'fixed' ? '' : $mapped, 0, 500)];
          }, (array) ($model->parameters ?? [])),
        ];
      }
    }

    $job->fill([
      'subject' => (string) ($data['subject'] ?? $job->subject ?? 'Untitled campaign'),
      'html_body' => $html,
      'text_body' => $text,
      'from_name' => $data['from_name'] ?? null,
      'from_email' => $data['from_email'] ?? null,
      'recipient_filters' => $filters,
      'channels' => $channels,
      'module' => $filters['module'],
      'message_type' => $messageType,
      'whatsapp_template' => $template ?? (in_array('whatsapp', $channels, true) ? $job->whatsapp_template : null),
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function action(array $data): string
  {
    $action = (string) ($data['action'] ?? '');
    if (in_array($action, ['draft', 'send', 'schedule'], true)) {
      return $action;
    }

    return ! empty($data['scheduled_at']) ? 'schedule' : 'send';
  }

  /**
   * Naive datetimes (e.g. from datetime-local inputs) are interpreted in Africa/Lagos.
   */
  public function parseSchedule(mixed $value): ?CarbonInterface
  {
    if ($value === null || $value === '') {
      return null;
    }

    try {
      $parsed = Carbon::parse((string) $value, (string) config('communications.timezone', 'Africa/Lagos'))->utc();
    } catch (\Throwable) {
      return null;
    }

    return $parsed->isFuture() ? $parsed : null;
  }

  public function cancel(BulkEmailJob $job, ?User $actor = null): void
  {
    $cancellable = [BulkEmailJob::STATUS_DRAFT, BulkEmailJob::STATUS_SCHEDULED, BulkEmailJob::STATUS_QUEUED, BulkEmailJob::STATUS_PROCESSING, 'sending'];
    if (! in_array($job->status, $cancellable, true)) {
      throw ValidationException::withMessages(['status' => 'Only draft, scheduled, queued or processing campaigns can be cancelled.']);
    }

    $job->forceFill([
      'status' => BulkEmailJob::STATUS_CANCELLED,
      'cancelled_at' => now(),
      'cancelled_by' => $actor?->id,
    ])->save();

    BulkEmailRecipient::query()
      ->where('bulk_email_job_id', $job->id)
      ->where('status', DeliveryStatus::PENDING)
      ->update(['status' => DeliveryStatus::CANCELLED, 'updated_at' => now()]);

    app(CampaignDispatchService::class)->refreshProgress($job->id);
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int}>
   */
  private function eventRegistrationRecipients(array $filters): Collection
  {
    $eventId = $this->resolveEventId($filters['event_id'] ?? null);
    $query = EventRegistration::query()
      ->with(['person.country', 'person.user', 'member.user', 'plannedSessions', 'sessionAttendances', 'services', 'payments'])
      ->whereNotIn('status', ['cancelled', 'declined']);

    if ($eventId !== null) {
      $query->where('event_id', $eventId);
    }

    $sessionId = $this->resolveSessionId($filters['event_session_id'] ?? null);
    if ($sessionId !== null) {
      $query->where(function ($builder) use ($sessionId): void {
        $builder->whereHas('plannedSessions', fn ($q) => $q->where('event_sessions.id', $sessionId))
          ->orWhereHas('sessionAttendances', fn ($q) => $q->where('event_session_id', $sessionId));
      });
    }

    $rows = $query->get()->filter(fn (EventRegistration $registration): bool => $this->matchesEventFilters($registration, $filters));

    return $rows->map(function (EventRegistration $registration): array {
      $code = $registration->person?->country?->code;

      return [
        'email' => $registration->contactEmail(),
        'phone' => $registration->contactPhone(),
        'name' => $registration->contactName(),
        'first_name' => $registration->person?->first_name ?: $registration->member?->first_name,
        'user_id' => $registration->person?->user_id ?: $registration->member?->user_id,
        'person_id' => $registration->person_id,
        'region' => is_string($code) && strlen($code) === 2 ? strtoupper($code) : null,
        'phone_country_code' => $registration->person?->phone_country_code,
        'country_id' => $registration->member?->country_id,
      ];
    })->values();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  private function matchesEventFilters(EventRegistration $registration, array $filters): bool
  {
    $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];

    $gender = strtolower(trim((string) ($filters['gender'] ?? '')));
    if ($gender !== '' && strtolower((string) ($profile['gender'] ?? '')) !== $gender) {
      return false;
    }

    $category = strtolower(trim((string) ($filters['category'] ?? '')));
    if ($category !== '') {
      $value = strtolower((string) ($profile['participant_category'] ?? $profile['membership_status'] ?? $profile['category'] ?? ''));
      if ($value !== $category) {
        return false;
      }
    }

    if (array_key_exists('accommodation', $filters) && $filters['accommodation'] !== null && $filters['accommodation'] !== '') {
      $raw = $filters['accommodation'];
      $wants = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($wants === null) {
        $wants = in_array(strtolower((string) $raw), ['yes', 'true', '1', 'requested'], true);
      }
      $has = $registration->services->contains(function ($service) {
        $type = $service->type instanceof EventRegServiceType ? $service->type : EventRegServiceType::tryFrom((string) $service->type);

        return $type === EventRegServiceType::Accommodation;
      });
      if ($wants !== $has) {
        return false;
      }
    }

    if (array_key_exists('transport', $filters) && $filters['transport'] !== null && $filters['transport'] !== '') {
      $raw = $filters['transport'];
      $wants = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($wants === null) {
        $wants = in_array(strtolower((string) $raw), ['yes', 'true', '1', 'requested'], true);
      }
      $has = $registration->services->contains(function ($service) {
        $type = $service->type instanceof EventRegServiceType ? $service->type : EventRegServiceType::tryFrom((string) $service->type);

        return $type === EventRegServiceType::Transport;
      });
      if ($wants !== $has) {
        return false;
      }
    }

    $payment = strtolower(trim((string) ($filters['payment'] ?? '')));
    if ($payment !== '') {
      $statuses = $registration->payments->map(fn ($item) => strtolower($item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status));
      $settled = $statuses->contains(fn ($status) => in_array($status, ['paid', 'approved', 'waived'], true));
      if ($payment === 'paid' && ! $settled) {
        return false;
      }
      if ($payment === 'pending' && $settled) {
        return false;
      }
    }

    $attendance = strtolower(trim((string) ($filters['attendance'] ?? '')));
    if ($attendance !== '') {
      $statuses = $registration->sessionAttendances->map(
        fn ($row) => strtolower($row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status),
      );
      $present = $statuses->contains('checked_in');
      $out = $statuses->contains('checked_out');
      if (in_array($attendance, ['attended', 'checked_in'], true) && ! $present) {
        return false;
      }
      if ($attendance === 'absent' && $present) {
        return false;
      }
      if ($attendance === 'checked_out' && ! $out) {
        return false;
      }
    }

    $audience = strtolower(trim((string) ($filters['audience'] ?? '')));
    if (in_array($audience, ['members', 'visitors'], true)) {
      $class = MembershipClassification::forPerson($registration->person) ?: MembershipClassification::forMember($registration->member);
      $isMember = ($class['type'] ?? '') === 'approved_member';
      if ($audience === 'members' && ! $isMember) {
        return false;
      }
      if ($audience === 'visitors' && $isMember) {
        return false;
      }
    }

    $country = strtolower(trim((string) ($filters['country'] ?? '')));
    if ($country !== '') {
      $haystack = strtolower(trim(implode(' ', array_filter([
        $registration->person?->country?->name,
        $registration->person?->country?->code,
        $registration->person?->country?->slug,
        is_string($profile['country'] ?? null) ? $profile['country'] : null,
      ]))));
      if (! str_contains($haystack, $country)) {
        return false;
      }
    }

    $state = strtolower(trim((string) ($filters['state_region'] ?? $filters['state'] ?? '')));
    if ($state !== '') {
      $hay = strtolower(trim((string) ($registration->person?->region ?? $profile['state_region'] ?? $profile['region'] ?? '')));
      if (! str_contains($hay, $state)) {
        return false;
      }
    }

    $city = strtolower(trim((string) ($filters['city'] ?? '')));
    if ($city !== '' && ! str_contains(strtolower((string) ($registration->person?->city ?? $profile['city'] ?? '')), $city)) {
      return false;
    }

    $status = strtolower(trim((string) ($filters['registration_status'] ?? '')));
    if ($status !== '') {
      $current = strtolower($registration->status instanceof \BackedEnum ? $registration->status->value : (string) $registration->status);
      if ($current !== $status) {
        return false;
      }
    }

    $occupancy = strtolower(trim((string) ($filters['occupancy_type'] ?? '')));
    if ($occupancy !== '') {
      $details = is_array($registration->metadata['accommodation'] ?? null) ? $registration->metadata['accommodation'] : [];
      $value = strtolower((string) ($details['occupancy_type'] ?? $details['occupancy'] ?? ''));
      if ($value !== $occupancy) {
        $has = $registration->services->first(function ($service) {
          $type = $service->type instanceof EventRegServiceType ? $service->type : EventRegServiceType::tryFrom((string) $service->type);

          return $type === EventRegServiceType::Accommodation;
        });
        $meta = is_array($has?->details) ? $has->details : [];
        $value = strtolower((string) ($meta['occupancy_type'] ?? $meta['occupancy'] ?? ''));
      }
      if ($value !== $occupancy) {
        return false;
      }
    }

    $seat = strtolower(trim((string) ($filters['seat_counting'] ?? '')));
    if (in_array($seat, ['yes', 'no'], true)) {
      $counted = $registration->sessionAttendances->contains(fn ($row) => (bool) $row->counts_toward_seating);
      if ($seat === 'yes' && ! $counted) {
        return false;
      }
      if ($seat === 'no' && $counted) {
        return false;
      }
    }

    return true;
  }

  private function resolveEventId(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (is_numeric($value)) {
      return (int) $value;
    }

    return Event::query()->where('uuid', (string) $value)->value('id');
  }

  private function resolveSessionId(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (is_numeric($value)) {
      return (int) $value;
    }

    return EventSession::query()->where('uuid', (string) $value)->value('id');
  }
}
