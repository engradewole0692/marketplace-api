<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Contracts\ApiResponderContract;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Events\Http\Resources\EventRegServiceResource;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Services\CheckInTokenService;
use App\Modules\Events\Services\PersonIdentityService;
use App\Services\Membership\MemberPortalWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberPortalController extends ApiController
{
  public function __construct(
    ApiResponderContract $responder,
    private readonly MemberPortalWorkspaceService $workspaceService,
    private readonly PersonIdentityService $personIdentityService,
  ) {
    parent::__construct($responder);
  }

  public function dashboard(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $workspace = $this->workspaceService->buildDashboard($member);

    return $this->responder->success(
      data: [
        'member' => new MemberResource($member->loadMissing([
          'ministry',
          'country',
          'region',
          'preferredMinistry',
          'photoMedia',
        ])),
        'ministry' => $member->ministry ? [
          'id' => $member->ministry->uuid,
          'name' => $member->ministry->name,
          'slug' => $member->ministry->slug,
          'summary' => $member->ministry->summary,
          'whatsapp_link' => $member->ministry->whatsapp_link ?? null,
          'telegram_link' => $member->ministry->telegram_link ?? null,
          'signal_link' => $member->ministry->signal_link ?? null,
          'leader' => $member->ministry->leaderMember ? [
            'id' => $member->ministry->leaderMember->id,
            'name' => $member->ministry->leaderMember->fullName(),
          ] : null,
        ] : null,
        'country' => $member->country ? [
          'id' => $member->country->uuid ?? $member->country->id,
          'name' => $member->country->name,
          'slug' => $member->country->slug,
        ] : null,
        'region' => $member->region ? [
          'id' => $member->region->id,
          'name' => $member->region->name,
        ] : null,
        'widgets' => $workspace['widgets'],
        'sections' => $workspace['sections'],
      ],
      message: 'Member dashboard loaded.',
    );
  }

  public function profile(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $member->load(['contacts', 'addresses', 'ministry', 'preferredMinistry', 'country', 'region', 'tags', 'photoMedia']);

    return $this->responder->success(
      data: [
        'member' => new MemberResource($member),
        'profile_completion' => $this->workspaceService->buildDashboard($member)['widgets']['profile_completion'],
      ],
      message: 'Member profile loaded.',
    );
  }

  public function updateProfile(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $validated = $request->validate([
      'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
      'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
      'occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
      'organization' => ['sometimes', 'nullable', 'string', 'max:255'],
      'profession' => ['sometimes', 'nullable', 'string', 'max:255'],
      'biography' => ['sometimes', 'nullable', 'string', 'max:5000'],
      'city' => ['sometimes', 'nullable', 'string', 'max:120'],
      'state' => ['sometimes', 'nullable', 'string', 'max:120'],
      'availability' => ['sometimes', 'nullable', 'string', 'max:255'],
      'education' => ['sometimes', 'nullable', 'string', 'max:255'],
      'skills' => ['sometimes', 'nullable', 'array'],
      'skills.*' => ['string', 'max:120'],
      'languages' => ['sometimes', 'nullable', 'array'],
      'languages.*' => ['string', 'max:80'],
      'emergency_contact' => ['sometimes', 'nullable', 'array'],
      'emergency_contact.name' => ['nullable', 'string', 'max:255'],
      'emergency_contact.relationship' => ['nullable', 'string', 'max:120'],
      'emergency_contact.phone' => ['nullable', 'string', 'max:50'],
      'emergency_contact.email' => ['nullable', 'email', 'max:255'],
      'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
    ]);

    foreach ([
      'phone', 'alternate_phone', 'occupation', 'organization', 'profession',
      'biography', 'city', 'state', 'availability', 'education', 'skills', 'languages',
    ] as $field) {
      if (array_key_exists($field, $validated)) {
        $member->{$field} = $validated[$field];
      }
    }
    $member->save();

    if (! empty($validated['emergency_contact']['name'])) {
      $member->contacts()->updateOrCreate(
        ['contact_type' => 'emergency', 'is_primary' => true],
        [
          'name' => $validated['emergency_contact']['name'],
          'relationship' => $validated['emergency_contact']['relationship'] ?? null,
          'phone' => $validated['emergency_contact']['phone'] ?? null,
          'email' => $validated['emergency_contact']['email'] ?? null,
          'is_primary' => true,
        ],
      );
    }

    if (array_key_exists('address_line_1', $validated)) {
      $member->addresses()->updateOrCreate(
        ['address_type' => 'home', 'is_primary' => true],
        [
          'address_line_1' => $validated['address_line_1'],
          'city' => $validated['city'] ?? $member->city,
          'state' => $validated['state'] ?? $member->state,
          'is_primary' => true,
        ],
      );
    }

    return $this->responder->success(
      data: ['member' => new MemberResource($member->fresh(['contacts', 'addresses', 'ministry', 'photoMedia', 'country', 'region']))],
      message: 'Profile updated.',
    );
  }

  public function myMinistry(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $member->load([
      'ministry.heroMedia',
      'ministry.logoMedia',
      'ministry.leaderMember',
      'ministry.assistantLeaderMember',
      'ministryAssignments.ministry',
    ]);

    $ministry = $member->ministry;
    if ($ministry === null) {
      return $this->responder->success(data: ['ministry' => null], message: 'No ministry assigned yet.');
    }

    $content = is_array($ministry->content) ? $ministry->content : [];
    $resources = array_values(array_filter((array) ($content['resources'] ?? [])));
    $downloads = array_values(array_filter((array) ($content['downloads'] ?? [])));
    $announcements = array_values(array_filter((array) ($content['announcements'] ?? [])));
    $gallery = array_values(array_filter((array) ($content['gallery'] ?? [])));

    // Names only — do not expose staff PII through the member portal.
    $leadershipProfiles = CmsLeadershipProfile::query()
      ->where('ministry_id', $ministry->id)
      ->where('is_active', true)
      ->orderBy('sort_order')
      ->limit(10)
      ->get(['uuid', 'name', 'role', 'hierarchy_level'])
      ->map(fn (CmsLeadershipProfile $profile) => [
        'id' => $profile->uuid,
        'name' => $profile->name,
        'role' => $profile->role,
        'hierarchy_level' => $profile->hierarchy_level,
      ])
      ->values();

    return $this->responder->success(
      data: [
        'ministry' => [
          'id' => $ministry->uuid,
          'name' => $ministry->name,
          'slug' => $ministry->slug,
          'tagline' => $ministry->tagline,
          'summary' => $ministry->summary,
          'mission' => $ministry->mission ?? null,
          'vision' => $ministry->vision ?? null,
          'whatsapp_link' => $ministry->whatsapp_link ?? null,
          'telegram_link' => $ministry->telegram_link ?? null,
          'signal_link' => $ministry->signal_link ?? null,
          'image_url' => $ministry->heroMedia?->url(),
          'logo_url' => $ministry->logoMedia?->url(),
          'leader' => $ministry->leaderMember ? [
            'id' => $ministry->leaderMember->id,
            'name' => $ministry->leaderMember->fullName(),
          ] : null,
          'assistant_leader' => $ministry->assistantLeaderMember ? [
            'id' => $ministry->assistantLeaderMember->id,
            'name' => $ministry->assistantLeaderMember->fullName(),
          ] : null,
          'leadership' => $leadershipProfiles,
          'announcements' => $announcements,
          'resources' => $resources,
          'downloads' => $downloads,
          'gallery' => $gallery,
          'recent_updates' => collect($this->workspaceService->buildActivityFeed($member, 8))
            ->where('category', 'membership')
            ->values(),
          'upcoming_activities' => [],
          'content' => [
            'resources' => $resources,
            'downloads' => $downloads,
            'announcements' => $announcements,
          ],
        ],
        'assignments' => $member->ministryAssignments->map(fn ($row) => [
          'ministry_id' => $row->ministry?->uuid,
          'name' => $row->ministry?->name,
          'role' => $row->role,
          'is_primary' => $row->is_primary,
          'assigned_at' => $row->assigned_at?->toIso8601String(),
        ])->values(),
      ],
      message: 'Ministry details loaded.',
    );
  }

  public function activity(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $limit = min(100, max(1, (int) $request->integer('per_page', 50)));

    return $this->responder->success(
      data: ['activity' => $this->workspaceService->buildActivityFeed($member, $limit)],
      message: 'Member activity loaded.',
    );
  }

  public function notifications(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $search = strtolower(trim((string) $request->query('search', '')));
    $status = (string) $request->query('status', 'all');

    $items = $member->notificationQueue()
      ->latest()
      ->limit(100)
      ->get()
      ->map(fn ($row) => $this->workspaceService->mapNotification($row))
      ->reject(fn (array $row) => ! empty($row['payload']['deleted_at']))
      ->when($status === 'unread', fn ($c) => $c->reject(fn (array $row) => $row['read'] || $row['archived']))
      ->when($status === 'read', fn ($c) => $c->filter(fn (array $row) => $row['read'] && ! $row['archived']))
      ->when($status === 'archived', fn ($c) => $c->filter(fn (array $row) => $row['archived']))
      ->when($status === 'all', fn ($c) => $c->reject(fn (array $row) => $row['archived']))
      ->when($search !== '', fn ($c) => $c->filter(function (array $row) use ($search): bool {
        return str_contains(strtolower((string) $row['template']), $search)
          || str_contains(strtolower((string) $row['channel']), $search);
      }))
      ->values();

    return $this->responder->success(
      data: ['notifications' => $items],
      message: 'Member notifications loaded.',
    );
  }

  public function markNotificationRead(Request $request, string $notification): JsonResponse
  {
    $member = $this->resolveMember($request);
    $item = $this->workspaceService->markNotificationRead($member, $notification);

    return $this->responder->success(
      data: ['notification' => $this->workspaceService->mapNotification($item)],
      message: 'Notification marked as read.',
    );
  }

  public function markAllNotificationsRead(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $count = $this->workspaceService->markAllNotificationsRead($member);

    return $this->responder->success(
      data: ['updated' => $count],
      message: 'All notifications marked as read.',
    );
  }

  public function archiveNotification(Request $request, string $notification): JsonResponse
  {
    $member = $this->resolveMember($request);
    $item = $this->workspaceService->archiveNotification($member, $notification);

    return $this->responder->success(
      data: ['notification' => $this->workspaceService->mapNotification($item)],
      message: 'Notification archived.',
    );
  }

  public function deleteNotification(Request $request, string $notification): JsonResponse
  {
    $member = $this->resolveMember($request);
    $this->workspaceService->deleteNotification($member, $notification);

    return $this->responder->success(message: 'Notification deleted.');
  }

  public function prayerRequests(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);

    return $this->responder->success(
      data: [
        'requests' => $this->workspaceService
          ->formSubmissionsForMember($member, FormSubmissionType::Prayer)
          ->values(),
      ],
      message: 'Prayer requests loaded.',
    );
  }

  public function counsellingRequests(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);

    return $this->responder->success(
      data: [
        'requests' => $this->workspaceService
          ->formSubmissionsForMember($member, FormSubmissionType::Counseling)
          ->values(),
      ],
      message: 'Counselling requests loaded.',
    );
  }

  public function events(Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $this->personIdentityService->ensureForMember($member);
    $member->refresh();

    $registrations = EventRegistration::query()
      ->forMemberIdentity($member)
      ->with(['event.venue', 'event.country', 'event.days', 'checkInToken', 'services', 'payments', 'checkIns', 'attendanceHistories', 'dayAttendances.day', 'person.member'])
      ->latest('submitted_at')
      ->limit(100)
      ->get()
      ->map(fn (EventRegistration $registration) => $this->mapPortalRegistrationSummary($registration))
      ->values();

    $certificates = EventCertificateIssuance::query()
      ->where('member_id', $member->id)
      ->with(['event', 'registration'])
      ->latest('issued_at')
      ->limit(100)
      ->get()
      ->map(fn (EventCertificateIssuance $certificate) => [
        'id' => $certificate->uuid,
        'event_id' => $certificate->event?->uuid,
        'registration_id' => $certificate->registration?->uuid,
        'member_id' => $member->uuid ?? (string) $member->id,
        'certificate_number' => $certificate->certificate_number,
        'status' => $certificate->status instanceof \App\Modules\Events\Enums\CertificateStatus
          ? $certificate->status->value
          : $certificate->status,
        'certificate_url' => $certificate->certificate_url,
        'issued_at' => $certificate->issued_at?->toIso8601String(),
        'revoked_at' => $certificate->revoked_at?->toIso8601String(),
      ])
      ->values();

    $attendance = EventAttendanceHistory::query()
      ->where('member_id', $member->id)
      ->with(['event', 'registration', 'session'])
      ->latest('occurred_at')
      ->limit(100)
      ->get()
      ->map(fn ($record) => [
        'id' => $record->uuid,
        'event_id' => $record->event?->uuid,
        'registration_id' => $record->registration?->uuid,
        'member_id' => $member->uuid ?? (string) $member->id,
        'session_id' => $record->session?->uuid,
        'status' => $record->status instanceof \App\Modules\Events\Enums\AttendanceStatus
          ? $record->status->value
          : $record->status,
        'source' => $record->source,
        'occurred_at' => $record->occurred_at?->toIso8601String(),
        'notes' => $record->notes,
      ])
      ->values();

    return $this->responder->success(
      data: [
        'registrations' => $registrations,
        'certificates' => $certificates,
        'attendance' => $attendance,
      ],
      message: 'Member events loaded.',
    );
  }

  public function eventRegistration(string $registration, Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $this->personIdentityService->ensureForMember($member);
    $member->refresh();

    $registrationModel = $this->ownedRegistration($member, $registration);
    $registrationModel->load([
      'event.venue',
      'event.country',
      'person.country',
      'member',
      'answers.question',
      'services',
      'payments',
      'checkIns',
      'attendanceHistories',
      'dayAttendances.day',
    ]);

    return $this->responder->success(
      data: ['registration' => $this->mapPortalRegistrationDetail($registrationModel, $member)],
      message: 'Event registration loaded.',
    );
  }

  public function eventCheckInToken(string $registration, Request $request, CheckInTokenService $tokenService): JsonResponse
  {
    $member = $this->resolveMember($request);
    $this->personIdentityService->ensureForMember($member);
    $member->refresh();

    $registrationModel = $this->ownedRegistration($member, $registration)->load('event');

    if (! $registrationModel->event?->check_in_enabled) {
      return $this->responder->success(
        data: ['token' => null, 'reason' => 'Check-in not enabled for this event.'],
        message: 'No token available.',
      );
    }

    $result = $tokenService->regenerate($registrationModel, null, $request->user());

    return $this->responder->success(
      data: [
        'registration_id' => $registrationModel->uuid,
        'event_id' => $registrationModel->event?->uuid,
        'token' => $result['token'],
        'qr_payload' => $result['token'],
        'expires_at' => $result['model']->expires_at?->toIso8601String(),
      ],
      message: 'Check-in token generated.',
    );
  }

  public function respondPairing(string $pairing, Request $request): JsonResponse
  {
    $member = $this->resolveMember($request);
    $validated = $request->validate([
      'registration_id' => ['required', 'string'],
      'accept' => ['required', 'boolean'],
    ]);
    $registration = $this->ownedRegistration($member, $validated['registration_id']);
    $model = \App\Modules\Events\Models\EventAccommodationPairing::query()->where('uuid', $pairing)->firstOrFail();
    $updated = app(\App\Modules\Events\Services\AccommodationService::class)
      ->respondToPairing($model, $registration, (bool) $validated['accept'], $request->user());

    return $this->responder->success(
      data: ['pairing' => $updated],
      message: 'Pairing response recorded.',
    );
  }

  public function uploadPhoto(Request $request, \App\Services\Profile\ProfilePhotoService $service): JsonResponse
  {
    $member = $this->resolveMember($request);
    $validated = $request->validate([
      'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
    ]);

    $member = $service->uploadForMember($member, $validated['photo'], $request->user());

    return $this->responder->success(
      data: ['member' => new MemberResource($member->loadMissing(['photoMedia']))],
      message: 'Profile photo uploaded.',
    );
  }

  public function attachPhoto(Request $request, \App\Services\Profile\ProfilePhotoService $service): JsonResponse
  {
    $member = $this->resolveMember($request);
    $validated = $request->validate([
      'media_id' => ['required', 'string'],
    ]);

    $media = $service->resolveMedia($validated['media_id']);
    $member = $service->attachMediaToMember($member, $media);

    return $this->responder->success(
      data: ['member' => new MemberResource($member->loadMissing(['photoMedia']))],
      message: 'Profile photo attached.',
    );
  }

  public function destroyPhoto(Request $request, \App\Services\Profile\ProfilePhotoService $service): JsonResponse
  {
    $member = $this->resolveMember($request);
    $member = $service->clearMember($member);

    return $this->responder->success(
      data: ['member' => new MemberResource($member->loadMissing(['photoMedia']))],
      message: 'Profile photo removed.',
    );
  }

  private function resolveMember(Request $request): Member
  {
    $user = $request->user();

    if ($user === null || ! $user->hasPermission('member.portal')) {
      throw new \App\Exceptions\BusinessException(
        'Member portal access required.',
        \App\Enums\ApiErrorCode::Forbidden,
        null,
        403,
      );
    }

    $member = Member::query()->where('user_id', $user->id)->first();

    if ($member === null || ! $member->isActiveMember()) {
      throw new \App\Exceptions\BusinessException(
        'Active membership required.',
        \App\Enums\ApiErrorCode::Forbidden,
        null,
        403,
      );
    }

    return $member;
  }

  private function ownedRegistration(Member $member, string $registration): EventRegistration
  {
    return EventRegistration::query()
      ->forMemberIdentity($member)
      ->where('uuid', $registration)
      ->firstOrFail();
  }

  /**
   * @return array<string, mixed>
   */
  private function mapPortalRegistrationSummary(EventRegistration $registration): array
  {
    $latestPayment = $registration->relationLoaded('payments')
      ? $registration->payments->sortByDesc('id')->first()
      : null;
    $services = $this->mapPortalServices($registration);
    $latestCheckIn = $registration->relationLoaded('checkIns')
      ? $registration->checkIns->sortByDesc('checked_in_at')->first()
      : null;

    return [
      'id' => $registration->uuid,
      'event' => $registration->event ? [
        'id' => $registration->event->uuid,
        'title' => $registration->event->title,
        'slug' => $registration->event->slug,
        'starts_at' => $registration->event->starts_at?->toIso8601String(),
        'ends_at' => $registration->event->ends_at?->toIso8601String(),
        'banner_url' => $registration->event->banner_url,
        'venue_name' => $registration->event->venue?->name,
        'location' => $registration->event->venue?->name,
        'check_in_enabled' => $registration->event->check_in_enabled,
        'certificate_enabled' => $registration->event->certificate_enabled,
      ] : null,
      'status' => $registration->status instanceof \App\Modules\Events\Enums\RegistrationStatus
        ? $registration->status->value
        : $registration->status,
      'registration_number' => $registration->registration_number,
      'volunteer_interest' => (bool) $registration->volunteer_interest,
      'submitted_at' => $registration->submitted_at?->toIso8601String(),
      'approved_at' => $registration->approved_at?->toIso8601String(),
      'attendance_status' => $latestCheckIn ? 'checked_in' : (
        $registration->status instanceof \BackedEnum ? $registration->status->value : (string) $registration->status
      ),
      'accommodation_status' => $services['accommodation']['status_label'] ?? ($registration->accommodation_required ? 'Pending Confirmation' : 'Not requested'),
      'transport_status' => $services['transport']['status_label'] ?? ($registration->airport_pickup_required ? 'Pending Confirmation' : 'Not requested'),
      'travel_status' => $services['travel']['status_label'] ?? 'Not requested',
      'payment_status' => $latestPayment?->status instanceof \BackedEnum
        ? $latestPayment->status->value
        : ($latestPayment?->status ?? null),
      'services' => array_values($services),
      'check_in_token' => $registration->checkInToken?->token,
      'attendance_summary' => app(\App\Modules\Events\Services\AttendanceService::class)->summarizeRegistration($registration),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function mapPortalRegistrationDetail(EventRegistration $registration, Member $member): array
  {
    $summary = $this->mapPortalRegistrationSummary($registration);
    $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];
    $latestPayment = $registration->payments->sortByDesc('id')->first();
    $person = $registration->person;

    $answers = $registration->answers->map(function ($answer) {
      $question = $answer->question;

      return [
        'id' => $answer->uuid ?? $answer->id,
        'question' => $question?->question ?? $question?->label,
        'answer' => $answer->answer_text ?? $answer->answer_json,
      ];
    })->values();

    return [
      ...$summary,
      'event' => $registration->event ? [
        ...($summary['event'] ?? []),
        'country' => $registration->event->country?->name,
      ] : null,
      'person' => [
        'id' => $person?->uuid,
        'person_no' => $person?->person_no,
        'name' => $person?->fullName() ?? $member->fullName(),
        'email' => $person?->email ?? $member->email,
        'phone' => $person?->phone ?? $member->phone,
        'country' => $person?->country?->name ?? $member->country?->name,
        'region' => $person?->region ?? $member->state,
        'city' => $person?->city ?? $member->city,
        'organization' => $person?->organization ?? $member->organization,
        'is_member' => true,
      ],
      'submitted' => [
        'name' => $registration->submittedName(),
        'email' => $registration->submittedEmail(),
        'phone' => $registration->submittedPhone(),
        'country' => is_string($profile['country'] ?? null) ? $profile['country'] : null,
        'state_region' => is_string($profile['state_region'] ?? $profile['state'] ?? null)
          ? ($profile['state_region'] ?? $profile['state'])
          : null,
        'city' => is_string($profile['city'] ?? null) ? $profile['city'] : null,
        'occupation' => is_string($profile['occupation'] ?? null) ? $profile['occupation'] : null,
        'organization' => is_string($profile['organization'] ?? null) ? $profile['organization'] : null,
        'ministry' => is_string($profile['ministry'] ?? null) ? $profile['ministry'] : null,
        'category' => is_string($profile['membership_status'] ?? $profile['participant_category'] ?? null)
          ? ($profile['membership_status'] ?? $profile['participant_category'])
          : null,
        'profile' => $profile,
      ],
      'emergency_contact' => [
        'name' => $registration->emergency_contact_name,
        'relationship' => $registration->emergency_contact_relationship,
        'phone' => $registration->emergency_contact_phone,
      ],
      'accommodation' => $this->serviceOrFallback(
        $summary['services'],
        'accommodation',
        $registration->accommodation_required,
        [
          'arrival_date' => $registration->arrival_date?->toDateString(),
          'departure_date' => $registration->departure_date?->toDateString(),
          'type' => $profile['accommodation_type'] ?? null,
        ],
      ),
      'transport' => $this->serviceOrFallback(
        $summary['services'],
        'transport',
        $registration->airport_pickup_required,
        ['service' => $registration->airport_pickup_required ? 'Airport pickup' : null],
      ),
      'travel' => $this->serviceOrFallback($summary['services'], 'travel', false, []),
      'payment' => $latestPayment ? [
        'status' => $latestPayment->status instanceof \BackedEnum ? $latestPayment->status->value : $latestPayment->status,
        'amount' => $latestPayment->amount,
        'currency' => $latestPayment->currency,
        'paid_at' => $latestPayment->paid_at?->toIso8601String(),
      ] : null,
      'dietary_requirements' => $registration->dietary_requirements,
      'answers' => $answers,
      'attendance_summary' => app(\App\Modules\Events\Services\AttendanceService::class)->summarizeRegistration($registration),
      'next_step' => $this->portalNextStep($registration, $latestPayment),
    ];
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function mapPortalServices(EventRegistration $registration): array
  {
    if (! $registration->relationLoaded('services')) {
      return [];
    }

    $out = [];
    foreach ($registration->services as $service) {
      $payload = (new EventRegServiceResource($service))->resolve();
      $out[(string) $payload['type']] = $payload;
    }

    return $out;
  }

  /**
   * @param  list<array<string, mixed>>|array<string, mixed>  $services
   * @param  array<string, mixed>  $details
   * @return array<string, mixed>
   */
  private function serviceOrFallback(array $services, string $type, bool $requested, array $details): array
  {
    $indexed = [];
    foreach ($services as $service) {
      if (is_array($service) && ($service['type'] ?? null) === $type) {
        $indexed = $service;
        break;
      }
    }

    if ($indexed !== []) {
      return [
        ...$indexed,
        'details' => array_filter(array_merge(
          is_array($indexed['details'] ?? null) ? $indexed['details'] : [],
          $details,
        ), fn ($value) => $value !== null && $value !== ''),
      ];
    }

    if (! $requested) {
      return [
        'type' => $type,
        'status' => 'not_requested',
        'status_label' => 'Not requested',
        'confirmed' => false,
        'pending' => false,
        'details' => (object) [],
      ];
    }

    return [
      'type' => $type,
      'status' => 'requested',
      'status_label' => 'Pending Confirmation',
      'confirmed' => false,
      'pending' => true,
      'details' => array_filter($details, fn ($value) => $value !== null && $value !== ''),
    ];
  }

  private function portalNextStep(EventRegistration $registration, ?EventRegistrationPayment $payment): ?string
  {
    $status = $registration->status instanceof \BackedEnum ? $registration->status->value : (string) $registration->status;
    $payStatus = $payment?->status instanceof \BackedEnum ? $payment->status->value : $payment?->status;

    if (in_array($payStatus, ['pending', 'unpaid', 'awaiting_payment'], true)) {
      return 'Complete payment for this event.';
    }
    if (in_array($status, ['submitted', 'pending_review'], true)) {
      return 'Your registration is awaiting confirmation.';
    }
    if ($status === 'approved' && $registration->event?->check_in_enabled) {
      return 'Bring your check-in QR code to the event.';
    }

    foreach ($registration->services as $service) {
      $serviceStatus = $service->status instanceof \BackedEnum ? $service->status->value : (string) $service->status;
      if (in_array($serviceStatus, ['requested', 'under_review', 'awaiting_payment'], true)) {
        return 'A requested service is still pending confirmation.';
      }
    }

    return null;
  }
}
