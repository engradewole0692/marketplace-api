<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\Person;
use App\Models\User;
use App\Modules\Events\Enums\DayAttendanceStatus;
use App\Modules\Events\Enums\EventAuditEventType;
use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\EventAccommodationAllocation;
use App\Modules\Events\Models\EventAccommodationPairingMember;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventCheckIn;
use App\Modules\Events\Models\EventCheckInToken;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventRegistrationQuestionAnswer;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventVolunteerAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersonMergeService implements ServiceContract
{
  /**
   * @var list<string>
   */
  private const MERGEABLE_FIELDS = [
    'first_name',
    'last_name',
    'display_name',
    'email',
    'phone',
    'country_id',
    'region',
    'city',
    'organization',
  ];

  public function __construct(
    private readonly EventAuditService $eventAuditService,
    private readonly RegistrationAuditService $registrationAuditService,
  ) {}

  /**
   * @param  list<string>  $preferSourceFields
   * @return array<string, mixed>
   */
  public function preview(Person $source, Person $target, array $preferSourceFields = []): array
  {
    return $this->buildPlan($source, $target, $preferSourceFields);
  }

  /**
   * @param  list<string>  $preferSourceFields
   */
  public function execute(Person $source, Person $target, User $actor, array $preferSourceFields = []): Person
  {
    return DB::transaction(function () use ($source, $target, $actor, $preferSourceFields): Person {
      $ids = [$source->id, $target->id];
      sort($ids);
      Person::query()->whereIn('id', $ids)->lockForUpdate()->get();

      $source = Person::query()->with(['member', 'user', 'country'])->findOrFail($source->id);
      $target = Person::query()->with(['member', 'user', 'country'])->findOrFail($target->id);

      EventRegistration::query()
        ->whereIn('person_id', [$source->id, $target->id])
        ->lockForUpdate()
        ->get();

      $plan = $this->buildPlan($source, $target, $preferSourceFields);
      if (! $plan['can_merge']) {
        throw ValidationException::withMessages([
          'merge' => $plan['conflicts'] !== []
            ? $plan['conflicts']
            : ['This merge cannot be completed safely.'],
        ]);
      }

      $this->applyPlan($plan, $source, $target, $actor);

      return $target->fresh(['member', 'country', 'user']) ?? $target;
    });
  }

  /**
   * @param  list<string>  $preferSourceFields
   * @return array<string, mixed>
   */
  private function buildPlan(Person $source, Person $target, array $preferSourceFields): array
  {
    $conflicts = [];
    if ($source->id === $target->id) {
      $conflicts[] = 'Source and surviving person must be different records.';
    }
    if ($source->trashed() || $target->trashed()) {
      $conflicts[] = 'Cannot merge a person that has already been archived.';
    }

    $source->loadMissing(['member', 'user', 'country']);
    $target->loadMissing(['member', 'user', 'country']);

    if ($source->user_id && $target->user_id && (int) $source->user_id !== (int) $target->user_id) {
      $conflicts[] = 'Both people are linked to different user accounts. User/authentication identities cannot be merged.';
    }
    $sourceMember = $source->member;
    $targetMember = $target->member;
    if ($sourceMember && $targetMember && (int) $sourceMember->id !== (int) $targetMember->id) {
      $conflicts[] = 'Both people are linked to different member records. Member accounts cannot be merged.';
    }

    $fieldPlan = $this->fieldPlan($source, $target, $preferSourceFields);
    $sourceRegs = $this->registrationsFor($source);
    $targetRegs = $this->registrationsFor($target);
    $targetByEvent = $targetRegs->keyBy('event_id');

    $reassign = [];
    $collisions = [];

    foreach ($sourceRegs as $sourceReg) {
      $targetReg = $targetByEvent->get($sourceReg->event_id);
      if ($targetReg === null) {
        $reassign[] = $this->registrationSummary($sourceReg, 'reassign');
        continue;
      }

      $collision = $this->analyzeCollision($sourceReg, $targetReg);
      $collisions[] = $collision;
      foreach ($collision['conflicts'] as $conflict) {
        $conflicts[] = $conflict;
      }
    }

    $conflicts = array_values(array_unique($conflicts));

    return [
      'can_merge' => $conflicts === [],
      'conflicts' => $conflicts,
      'source' => $this->personSnapshot($source, $sourceRegs),
      'target' => $this->personSnapshot($target, $targetRegs),
      'fields' => $fieldPlan,
      'reassign_registrations' => $reassign,
      'collisions' => $collisions,
      'member_user' => [
        'source_has_user' => $source->user_id !== null,
        'target_has_user' => $target->user_id !== null,
        'source_has_member' => $sourceMember !== null,
        'target_has_member' => $targetMember !== null,
        'transfer_user' => $source->user_id !== null && $target->user_id === null,
        'transfer_member' => $sourceMember !== null && $targetMember === null,
      ],
    ];
  }

  /**
   * @param  array<string, mixed>  $plan
   */
  private function applyPlan(array $plan, Person $source, Person $target, User $actor): void
  {
    foreach ($plan['collisions'] as $collision) {
      $this->applyCollision($collision, $target, $actor);
    }

    foreach ($plan['reassign_registrations'] as $row) {
      $registration = EventRegistration::query()->where('uuid', $row['id'])->firstOrFail();
      $oldPerson = $registration->person_id;
      $registration->person_id = $target->id;
      $registration->save();
      EventDayAttendance::query()->where('registration_id', $registration->id)->update(['person_id' => $target->id]);
      $this->registrationAuditService->record(
        RegistrationAuditEventType::PersonMerged,
        $registration,
        $actor,
        ['person_id' => $oldPerson],
        ['person_id' => $target->id],
        ['source_person_id' => $source->uuid, 'surviving_person_id' => $target->uuid],
      );
    }

    $this->applyFieldPlan($target, $plan['fields']);
    $this->transferIdentityLinks($source, $target, $plan['member_user']);

    $sourceMeta = is_array($source->meta) ? $source->meta : [];
    $sourceMeta['merged_into_person_id'] = $target->uuid;
    $sourceMeta['merged_at'] = now()->toIso8601String();
    $source->meta = $sourceMeta;
    $source->user_id = null;
    $source->save();
    $source->delete();

    $targetMeta = is_array($target->meta) ? $target->meta : [];
    $merged = $targetMeta['merged_person_ids'] ?? [];
    $merged[] = $source->uuid;
    $targetMeta['merged_person_ids'] = array_values(array_unique($merged));
    $target->meta = $targetMeta;
    $target->save();

    $this->eventAuditService->record(
      EventAuditEventType::PersonMerged,
      null,
      $actor,
      Person::class,
      $target->id,
      $plan['source'],
      $plan['target'],
      [
        'source_person_id' => $source->uuid,
        'surviving_person_id' => $target->uuid,
        'conflicts' => $plan['conflicts'],
        'fields' => $plan['fields'],
        'reassign_registrations' => $plan['reassign_registrations'],
        'collisions' => $plan['collisions'],
        'member_user' => $plan['member_user'],
      ],
    );
  }

  /**
   * @param  array<string, mixed>  $collision
   */
  private function applyCollision(array $collision, Person $target, User $actor): void
  {
    $keeper = EventRegistration::query()->where('uuid', $collision['keeper_id'])->firstOrFail();
    $discarded = EventRegistration::query()->where('uuid', $collision['discarded_id'])->firstOrFail();

    $this->moveChildren($discarded, $keeper, $target);
    $this->fillRegistrationBlanks($keeper, $discarded);

    $discardedMeta = is_array($discarded->metadata) ? $discarded->metadata : [];
    $discardedMeta['merged_into_registration_id'] = $keeper->uuid;
    $discarded->metadata = $discardedMeta;
    $discarded->person_id = null;
    $discarded->save();
    $discarded->delete();

    $keeper->person_id = $target->id;
    $keeper->save();

    $this->registrationAuditService->record(
      RegistrationAuditEventType::PersonMerged,
      $keeper,
      $actor,
      ['merged_registration_id' => $discarded->uuid],
      ['person_id' => $target->id],
      $collision,
    );
  }

  private function moveChildren(EventRegistration $discarded, EventRegistration $keeper, Person $target): void
  {
    EventRegistrationPayment::query()->where('registration_id', $discarded->id)->update(['registration_id' => $keeper->id]);
    EventCheckIn::query()->where('registration_id', $discarded->id)->update(['registration_id' => $keeper->id]);
    EventAttendanceHistory::query()->where('registration_id', $discarded->id)->update(['registration_id' => $keeper->id]);
    EventVolunteerAssignment::query()->where('registration_id', $discarded->id)->update(['registration_id' => $keeper->id]);

    $token = EventCheckInToken::query()->where('registration_id', $discarded->id)->first();
    if ($token !== null && $token->revoked_at === null) {
      $token->revoked_at = now();
      $token->save();
    }

    $keeperCert = EventCertificateIssuance::query()->where('registration_id', $keeper->id)->exists();
    if (! $keeperCert) {
      EventCertificateIssuance::query()->where('registration_id', $discarded->id)->update(['registration_id' => $keeper->id]);
    }

    $keeperAllocation = EventAccommodationAllocation::query()->where('registration_id', $keeper->id)->first();
    $discardedAllocation = EventAccommodationAllocation::query()->where('registration_id', $discarded->id)->first();
    if ($keeperAllocation === null && $discardedAllocation !== null) {
      $discardedAllocation->registration_id = $keeper->id;
      $discardedAllocation->save();
    }

    $keeperPairingIds = EventAccommodationPairingMember::query()->where('registration_id', $keeper->id)->pluck('pairing_id');
    EventAccommodationPairingMember::query()
      ->where('registration_id', $discarded->id)
      ->whereNotIn('pairing_id', $keeperPairingIds)
      ->update(['registration_id' => $keeper->id]);

    $keeperQuestionIds = EventRegistrationQuestionAnswer::query()->where('registration_id', $keeper->id)->pluck('question_id');
    EventRegistrationQuestionAnswer::query()
      ->where('registration_id', $discarded->id)
      ->whereNotIn('question_id', $keeperQuestionIds)
      ->update(['registration_id' => $keeper->id]);

    $keeperServiceTypes = EventRegService::query()->where('registration_id', $keeper->id)->get()->keyBy(fn (EventRegService $row) => $this->enumValue($row->type));
    foreach (EventRegService::query()->where('registration_id', $discarded->id)->get() as $service) {
      $type = $this->enumValue($service->type);
      $existing = $keeperServiceTypes->get($type);
      if ($existing === null) {
        $service->registration_id = $keeper->id;
        $service->save();
        continue;
      }
      if ($this->serviceRank($service) > $this->serviceRank($existing)) {
        $existing->status = $service->status;
        $existing->details = $service->details;
        $existing->option_id = $service->option_id ?: $existing->option_id;
        $existing->allocation_id = $service->allocation_id ?: $existing->allocation_id;
        $existing->confirmed_at = $service->confirmed_at ?: $existing->confirmed_at;
        $existing->save();
      }
    }

    $keeperDays = EventDayAttendance::query()->where('registration_id', $keeper->id)->get()->keyBy('event_day_id');
    foreach (EventDayAttendance::query()->where('registration_id', $discarded->id)->get() as $dayRow) {
      $existing = $keeperDays->get($dayRow->event_day_id);
      if ($existing === null) {
        $dayRow->registration_id = $keeper->id;
        $dayRow->person_id = $target->id;
        $dayRow->save();
        continue;
      }
      $this->mergeDayAttendance($existing, $dayRow, $target);
      $dayRow->delete();
    }

    EventDayAttendance::query()->where('registration_id', $keeper->id)->update(['person_id' => $target->id]);
  }

  private function mergeDayAttendance(EventDayAttendance $keeper, EventDayAttendance $other, Person $target): void
  {
    $keeperAttended = $keeper->countsAsAttended();
    $otherAttended = $other->countsAsAttended();
    if ($otherAttended && ! $keeperAttended) {
      $keeper->status = $other->status;
      $keeper->method = $other->method ?: $keeper->method;
      $keeper->checked_in_at = $other->checked_in_at;
      $keeper->checked_out_at = $other->checked_out_at;
      $keeper->notes = trim(trim((string) $keeper->notes)."\n".trim((string) $other->notes)) ?: $keeper->notes;
    } elseif ($keeperAttended && $otherAttended) {
      $keeperIn = $keeper->checked_in_at;
      $otherIn = $other->checked_in_at;
      if ($keeperIn === null || ($otherIn !== null && $otherIn->lt($keeperIn))) {
        $keeper->checked_in_at = $otherIn;
      }
      $keeperOut = $keeper->checked_out_at;
      $otherOut = $other->checked_out_at;
      if ($keeperOut === null || ($otherOut !== null && $otherOut->gt($keeperOut))) {
        $keeper->checked_out_at = $otherOut;
      }
      if ($keeper->checked_out_at !== null) {
        $keeper->status = DayAttendanceStatus::CheckedOut;
      } elseif ($keeper->checked_in_at !== null) {
        $keeper->status = DayAttendanceStatus::CheckedIn;
      }
      $keeper->notes = trim(trim((string) $keeper->notes)."\n".trim((string) $other->notes)) ?: $keeper->notes;
    }
    $keeper->person_id = $target->id;
    $keeper->save();
  }

  private function fillRegistrationBlanks(EventRegistration $keeper, EventRegistration $discarded): void
  {
    foreach ([
      'guest_name', 'guest_email', 'guest_phone', 'emergency_contact_name', 'emergency_contact_relationship',
      'emergency_contact_phone', 'arrival_date', 'departure_date', 'dietary_requirements', 'medical_notes',
      'prayer_requests', 'additional_notes', 'seat_reservation',
    ] as $field) {
      if (($keeper->{$field} === null || $keeper->{$field} === '') && $discarded->{$field} !== null && $discarded->{$field} !== '') {
        $keeper->{$field} = $discarded->{$field};
      }
    }
    if (! $keeper->accommodation_required && $discarded->accommodation_required) {
      $keeper->accommodation_required = true;
    }
    if (! $keeper->airport_pickup_required && $discarded->airport_pickup_required) {
      $keeper->airport_pickup_required = true;
    }
    if (! $keeper->volunteer_interest && $discarded->volunteer_interest) {
      $keeper->volunteer_interest = true;
    }
    $keeper->save();
  }

  /**
   * @param  array<string, mixed>  $memberUser
   */
  private function transferIdentityLinks(Person $source, Person $target, array $memberUser): void
  {
    if ($memberUser['transfer_user']) {
      $userId = $source->user_id;
      $source->user_id = null;
      $source->save();
      $target->user_id = $userId;
      $target->save();
    }

    if ($memberUser['transfer_member'] && $source->member) {
      $member = $source->member;
      $member->person_id = $target->id;
      $member->save();
    }
  }

  /**
   * @param  array<int, array<string, mixed>>  $fields
   */
  private function applyFieldPlan(Person $target, array $fields): void
  {
    foreach ($fields as $field) {
      if (($field['action'] ?? null) !== 'take_source') {
        continue;
      }
      $name = (string) $field['field'];
      if ($name === 'phone') {
        $target->phone = $field['source'];
        $target->phone_digits = Person::digits($field['source']);
        continue;
      }
      $target->{$name} = $field['source'];
    }
    $target->save();
  }

  /**
   * @return array<string, mixed>
   */
  private function analyzeCollision(EventRegistration $sourceReg, EventRegistration $targetReg): array
  {
    $conflicts = [];
    $sourceReg->load(['services', 'dayAttendances', 'payments', 'certificates', 'accommodationAllocation']);
    $targetReg->load(['services', 'dayAttendances', 'payments', 'certificates', 'accommodationAllocation']);

    $sourceRank = $this->registrationRank($sourceReg);
    $targetRank = $this->registrationRank($targetReg);
    $keeper = $sourceRank > $targetRank ? $sourceReg : $targetReg;
    $discarded = $keeper->id === $sourceReg->id ? $targetReg : $sourceReg;
    if ($sourceRank === $targetRank) {
      $keeper = $targetReg;
      $discarded = $sourceReg;
    }

    $sourceAllocation = $sourceReg->accommodationAllocation;
    $targetAllocation = $targetReg->accommodationAllocation;
    if ($sourceAllocation && $targetAllocation) {
      $conflicts[] = 'Both registrations for '.$this->eventTitle($sourceReg).' have accommodation allocations. Inventory cannot be combined automatically.';
    }

    if ($sourceReg->certificates->isNotEmpty() && $targetReg->certificates->isNotEmpty()) {
      $conflicts[] = 'Both registrations for '.$this->eventTitle($sourceReg).' have certificates.';
    }

    $sourceServices = $sourceReg->services->keyBy(fn (EventRegService $row) => $this->enumValue($row->type));
    $targetServices = $targetReg->services->keyBy(fn (EventRegService $row) => $this->enumValue($row->type));
    foreach (EventRegServiceType::cases() as $type) {
      $left = $sourceServices->get($type->value);
      $right = $targetServices->get($type->value);
      if ($left && $right && $this->isConfirmedService($left) && $this->isConfirmedService($right)) {
        $conflicts[] = 'Both registrations for '.$this->eventTitle($sourceReg).' have a confirmed '.$type->value.' service.';
      }
    }

    $sourcePairingIds = EventAccommodationPairingMember::query()->where('registration_id', $sourceReg->id)->pluck('pairing_id');
    $overlap = EventAccommodationPairingMember::query()
      ->where('registration_id', $targetReg->id)
      ->whereIn('pairing_id', $sourcePairingIds)
      ->exists();
    if ($overlap) {
      $conflicts[] = 'Both registrations for '.$this->eventTitle($sourceReg).' belong to the same accommodation pairing.';
    }

    return [
      'event_id' => $sourceReg->event?->uuid,
      'event_title' => $this->eventTitle($sourceReg),
      'keeper_id' => $keeper->uuid,
      'discarded_id' => $discarded->uuid,
      'keeper_status' => $this->enumValue($keeper->status),
      'discarded_status' => $this->enumValue($discarded->status),
      'conflicts' => $conflicts,
      'source_payments' => $sourceReg->payments->count(),
      'target_payments' => $targetReg->payments->count(),
      'source_attendance_days' => $sourceReg->dayAttendances->filter(fn (EventDayAttendance $row) => $row->countsAsAttended())->count(),
      'target_attendance_days' => $targetReg->dayAttendances->filter(fn (EventDayAttendance $row) => $row->countsAsAttended())->count(),
    ];
  }

  /**
   * @param  list<string>  $preferSourceFields
   * @return list<array<string, mixed>>
   */
  private function fieldPlan(Person $source, Person $target, array $preferSourceFields): array
  {
    $prefer = array_values(array_intersect(self::MERGEABLE_FIELDS, $preferSourceFields));
    $rows = [];
    foreach (self::MERGEABLE_FIELDS as $field) {
      $sourceValue = $this->scalar($source->{$field} ?? null);
      $targetValue = $this->scalar($target->{$field} ?? null);
      $action = 'keep_target';
      if ($this->isBlank($targetValue) && ! $this->isBlank($sourceValue)) {
        $action = 'take_source';
      } elseif (! $this->isBlank($sourceValue) && $sourceValue !== $targetValue && in_array($field, $prefer, true)) {
        $action = 'take_source';
      }
      $rows[] = [
        'field' => $field,
        'source' => $sourceValue,
        'target' => $targetValue,
        'conflict' => ! $this->isBlank($sourceValue) && ! $this->isBlank($targetValue) && $sourceValue !== $targetValue,
        'action' => $action,
      ];
    }

    return $rows;
  }

  /**
   * @return \Illuminate\Support\Collection<int, EventRegistration>
   */
  private function registrationsFor(Person $person)
  {
    return EventRegistration::query()
      ->with(['event', 'services', 'dayAttendances', 'payments', 'certificates', 'accommodationAllocation'])
      ->where('person_id', $person->id)
      ->orderBy('id')
      ->get();
  }

  /**
   * @param  \Illuminate\Support\Collection<int, EventRegistration>  $registrations
   * @return array<string, mixed>
   */
  private function personSnapshot(Person $person, $registrations): array
  {
    return [
      'id' => $person->uuid,
      'person_no' => $person->person_no,
      'name' => $person->fullName(),
      'first_name' => $person->first_name,
      'last_name' => $person->last_name,
      'email' => $person->email,
      'phone' => $person->phone,
      'region' => $person->region,
      'city' => $person->city,
      'organization' => $person->organization,
      'has_user' => $person->user_id !== null,
      'is_member' => $person->member !== null,
      'member_id' => $person->member?->uuid,
      'registrations' => $registrations->map(fn (EventRegistration $registration) => $this->registrationSummary($registration))->values()->all(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function registrationSummary(EventRegistration $registration, string $action = 'keep'): array
  {
    $registration->loadMissing(['event', 'services', 'dayAttendances', 'payments']);

    return [
      'id' => $registration->uuid,
      'action' => $action,
      'event_id' => $registration->event?->uuid,
      'event_title' => $this->eventTitle($registration),
      'registration_number' => $registration->registration_number,
      'status' => $this->enumValue($registration->status),
      'payments' => $registration->payments->count(),
      'services' => $registration->services->map(fn (EventRegService $service) => [
        'type' => $this->enumValue($service->type),
        'status' => $this->enumValue($service->status),
      ])->values()->all(),
      'attended_days' => $registration->dayAttendances->filter(fn (EventDayAttendance $row) => $row->countsAsAttended())->count(),
    ];
  }

  private function registrationRank(EventRegistration $registration): int
  {
    return match ($this->enumValue($registration->status)) {
      RegistrationStatus::Attended->value => 80,
      RegistrationStatus::CheckedIn->value => 70,
      RegistrationStatus::Approved->value => 60,
      RegistrationStatus::PendingReview->value => 50,
      RegistrationStatus::Submitted->value => 40,
      RegistrationStatus::Waitlisted->value => 30,
      RegistrationStatus::NoShow->value => 20,
      RegistrationStatus::Cancelled->value, RegistrationStatus::Declined->value => 10,
      default => 0,
    };
  }

  private function serviceRank(EventRegService $service): int
  {
    if ($this->isConfirmedService($service)) {
      return 50;
    }

    return match ($this->enumValue($service->status)) {
      EventRegServiceStatus::PaymentVerified->value, EventRegServiceStatus::AwaitingPayment->value => 30,
      EventRegServiceStatus::Requested->value, EventRegServiceStatus::UnderReview->value => 20,
      default => 0,
    };
  }

  private function isConfirmedService(EventRegService $service): bool
  {
    $status = $service->status instanceof EventRegServiceStatus
      ? $service->status
      : EventRegServiceStatus::tryFrom((string) $service->status);

    return $status?->isConfirmed() ?? false;
  }

  private function eventTitle(EventRegistration $registration): string
  {
    return $registration->event?->title ?? 'event';
  }

  private function enumValue(mixed $value): string
  {
    return $value instanceof \BackedEnum ? $value->value : (string) $value;
  }

  private function scalar(mixed $value): mixed
  {
    return $value instanceof \BackedEnum ? $value->value : $value;
  }

  private function isBlank(mixed $value): bool
  {
    return $value === null || $value === '';
  }
}
