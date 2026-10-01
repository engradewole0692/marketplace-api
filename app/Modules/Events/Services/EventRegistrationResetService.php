<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\EventAuditEventType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAccommodationAllocation;
use App\Modules\Events\Models\EventAccommodationPairing;
use App\Modules\Events\Models\EventAccommodationPairingMember;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventCheckIn;
use App\Modules\Events\Models\EventCheckInToken;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationAuditLog;
use App\Modules\Events\Models\EventRegistrationFieldSetting;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventRegistrationQuestion;
use App\Modules\Events\Models\EventRegistrationQuestionAnswer;
use App\Modules\Events\Models\EventRegistrationResetSnapshot;
use App\Modules\Events\Models\EventRegistrationStatusTransition;
use App\Modules\Events\Models\EventRegistrationTimeline;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventSessionAttendance;
use App\Modules\Events\Models\EventTransportTrip;
use App\Modules\Events\Models\EventTravelRequest;
use App\Modules\Events\Models\EventVolunteerAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventRegistrationResetService implements ServiceContract
{
    public const TYPE_REGISTRATIONS = 'registrations';

    public const TYPE_FORM_CONFIGURATION = 'form_configuration';

    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly RegistrationFormConfigService $formConfigService,
        private readonly EventAuditService $auditService,
    ) {}

    /**
     * @return array{snapshot: EventRegistrationResetSnapshot, counts: array<string, int>}
     */
    public function resetRegistrations(Event $event, User $actor): array
    {
        return DB::transaction(function () use ($event, $actor): array {
            $this->pruneExpired($event);

            $registrations = EventRegistration::query()
                ->where('event_id', $event->id)
                ->with([
                    'answers',
                    'services',
                    'payments',
                    'plannedSessions',
                    'checkInToken',
                    'checkIns',
                    'dayAttendances',
                    'sessionAttendances',
                    'attendanceHistories',
                    'statusTransitions',
                    'timelines',
                    'auditLogs',
                    'certificates',
                    'volunteerAssignments',
                    'accommodationAllocation',
                    'transportTrips',
                    'travelRequest',
                    'pairingMemberships',
                ])
                ->get();

            $payload = [
                'registrations' => $registrations->map(fn (EventRegistration $registration) => $this->serializeRegistration($registration))->values()->all(),
                'pairings' => EventAccommodationPairing::query()
                    ->where('event_id', $event->id)
                    ->with('members')
                    ->get()
                    ->map(fn (EventAccommodationPairing $pairing) => [
                        'attributes' => $this->attrs($pairing),
                        'members' => $pairing->members->map(fn (EventAccommodationPairingMember $member) => $this->attrs($member))->values()->all(),
                    ])
                    ->values()
                    ->all(),
            ];

            $counts = [
                'registrations' => $registrations->count(),
                'answers' => $registrations->sum(fn (EventRegistration $row) => $row->answers->count()),
                'services' => $registrations->sum(fn (EventRegistration $row) => $row->services->count()),
                'payments' => $registrations->sum(fn (EventRegistration $row) => $row->payments->count()),
                'attendance' => $registrations->sum(fn (EventRegistration $row) => $row->checkIns->count() + $row->dayAttendances->count() + $row->sessionAttendances->count()),
                'pairings' => count($payload['pairings']),
            ];

            $snapshot = EventRegistrationResetSnapshot::query()->create([
                'event_id' => $event->id,
                'actor_id' => $actor->id,
                'reset_type' => self::TYPE_REGISTRATIONS,
                'registration_count' => $counts['registrations'],
                'affected_counts' => $counts,
                'payload' => $payload,
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
            ]);

            foreach ($registrations as $registration) {
                $this->registrationService->delete($registration, $actor);
            }

            $this->auditService->record(
                EventAuditEventType::RegistrationsReset,
                $event,
                $actor,
                EventRegistrationResetSnapshot::class,
                $snapshot->id,
                null,
                null,
                [
                    'reset_type' => self::TYPE_REGISTRATIONS,
                    'registration_count' => $counts['registrations'],
                    'affected_counts' => $counts,
                    'undone' => false,
                ],
            );

            return ['snapshot' => $snapshot->fresh(), 'counts' => $counts];
        });
    }

    /**
     * @return array{snapshot: EventRegistrationResetSnapshot, counts: array<string, int>}
     */
    public function resetFormConfiguration(Event $event, User $actor): array
    {
        return DB::transaction(function () use ($event, $actor): array {
            $this->pruneExpired($event);

            $settings = $event->registrationFieldSettings()->get();
            $questions = $event->registrationQuestions()->get();

            $payload = [
                'field_settings' => $settings->map(fn (EventRegistrationFieldSetting $row) => $this->attrs($row))->values()->all(),
                'questions' => $questions->map(fn (EventRegistrationQuestion $row) => $this->attrs($row))->values()->all(),
            ];

            $counts = [
                'field_settings' => $settings->count(),
                'questions' => $questions->count(),
            ];

            $snapshot = EventRegistrationResetSnapshot::query()->create([
                'event_id' => $event->id,
                'actor_id' => $actor->id,
                'reset_type' => self::TYPE_FORM_CONFIGURATION,
                'registration_count' => 0,
                'affected_counts' => $counts,
                'payload' => $payload,
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
            ]);

            $event->registrationQuestions()->delete();
            $this->formConfigService->resetFieldSettingsToDefaults($event);

            $this->auditService->record(
                EventAuditEventType::FormConfigurationReset,
                $event,
                $actor,
                EventRegistrationResetSnapshot::class,
                $snapshot->id,
                null,
                null,
                [
                    'reset_type' => self::TYPE_FORM_CONFIGURATION,
                    'affected_counts' => $counts,
                    'undone' => false,
                ],
            );

            return ['snapshot' => $snapshot->fresh(), 'counts' => $counts];
        });
    }

    /**
     * @return array{snapshot: EventRegistrationResetSnapshot, restored: int}
     */
    public function undo(Event $event, User $actor, string $resetType): array
    {
        return DB::transaction(function () use ($event, $actor, $resetType): array {
            $snapshot = EventRegistrationResetSnapshot::query()
                ->where('event_id', $event->id)
                ->where('reset_type', $resetType)
                ->whereNull('restored_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($snapshot === null || ! $snapshot->canRestore()) {
                throw ValidationException::withMessages([
                    'snapshot' => ['No restorable reset snapshot is available for this event.'],
                ]);
            }

            if ($resetType === self::TYPE_REGISTRATIONS) {
                $existing = EventRegistration::query()->where('event_id', $event->id)->count();
                if ($existing > 0) {
                    throw ValidationException::withMessages([
                        'snapshot' => ['Undo is blocked because new registrations exist after the reset. Delete them first or keep the reset.'],
                    ]);
                }
                $restored = $this->restoreRegistrations($event, $snapshot);
            } else {
                $restored = $this->restoreFormConfiguration($event, $snapshot);
            }

            $snapshot->restored_at = now();
            $snapshot->restored_by_user_id = $actor->id;
            $snapshot->save();

            $this->auditService->record(
                $resetType === self::TYPE_REGISTRATIONS
                    ? EventAuditEventType::RegistrationsRestored
                    : EventAuditEventType::FormConfigurationRestored,
                $event,
                $actor,
                EventRegistrationResetSnapshot::class,
                $snapshot->id,
                null,
                null,
                [
                    'reset_type' => $resetType,
                    'restored' => $restored,
                    'undone' => true,
                    'original_actor_id' => $snapshot->actor_id,
                    'reset_at' => $snapshot->created_at?->toIso8601String(),
                ],
            );

            return ['snapshot' => $snapshot->fresh(), 'restored' => $restored];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Event $event): array
    {
        $currentRegistrationCount = EventRegistration::query()->where('event_id', $event->id)->count();
        $latest = EventRegistrationResetSnapshot::query()
            ->where('event_id', $event->id)
            ->latest('id')
            ->get()
            ->groupBy('reset_type')
            ->map(function ($rows) use ($currentRegistrationCount) {
                /** @var EventRegistrationResetSnapshot $row */
                $row = $rows->first();
                $blocked = $row->reset_type === self::TYPE_REGISTRATIONS
                    && $row->canRestore()
                    && $currentRegistrationCount > 0
                    ? 'Undo is blocked because new registrations exist after the reset. Delete them first or keep the reset.'
                    : null;

                return [
                    'id' => $row->uuid,
                    'reset_type' => $row->reset_type,
                    'registration_count' => $row->registration_count,
                    'affected_counts' => $row->affected_counts,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'actor_id' => $row->actor_id,
                    'restored_at' => $row->restored_at?->toIso8601String(),
                    'restored_by_user_id' => $row->restored_by_user_id,
                    'expires_at' => $row->expires_at?->toIso8601String(),
                    'can_undo' => $row->canRestore() && $blocked === null,
                    'undo_blocked_reason' => $blocked,
                ];
            });

        return [
            'registrations' => $latest[self::TYPE_REGISTRATIONS] ?? null,
            'form_configuration' => $latest[self::TYPE_FORM_CONFIGURATION] ?? null,
            'current_registration_count' => $currentRegistrationCount,
        ];
    }

    private function pruneExpired(Event $event): void
    {
        EventRegistrationResetSnapshot::query()
            ->where('event_id', $event->id)
            ->where(function ($query): void {
                $query->whereNotNull('restored_at')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('expires_at')->where('expires_at', '<', now());
                    });
            })
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRegistration(EventRegistration $registration): array
    {
        return [
            'id' => $registration->id,
            'attributes' => $this->attrs($registration),
            'answers' => $registration->answers->map(fn ($row) => $this->attrs($row))->values()->all(),
            'services' => $registration->services->map(fn ($row) => $this->attrs($row))->values()->all(),
            'payments' => $registration->payments->map(fn ($row) => $this->attrs($row))->values()->all(),
            'planned_session_ids' => $registration->plannedSessions->pluck('id')->values()->all(),
            'check_in_token' => $registration->checkInToken ? $this->attrs($registration->checkInToken) : null,
            'check_ins' => $registration->checkIns->map(fn ($row) => $this->attrs($row))->values()->all(),
            'day_attendances' => $registration->dayAttendances->map(fn ($row) => $this->attrs($row))->values()->all(),
            'session_attendances' => $registration->sessionAttendances->map(fn ($row) => $this->attrs($row))->values()->all(),
            'attendance_histories' => $registration->attendanceHistories->map(fn ($row) => $this->attrs($row))->values()->all(),
            'status_transitions' => $registration->statusTransitions->map(fn ($row) => $this->attrs($row))->values()->all(),
            'timelines' => $registration->timelines->map(fn ($row) => $this->attrs($row))->values()->all(),
            'audit_logs' => $registration->auditLogs->map(fn ($row) => $this->attrs($row))->values()->all(),
            'certificates' => $registration->certificates->map(fn ($row) => $this->attrs($row))->values()->all(),
            'volunteer_assignments' => $registration->volunteerAssignments->map(fn ($row) => $this->attrs($row))->values()->all(),
            'allocation' => $registration->accommodationAllocation ? $this->attrs($registration->accommodationAllocation) : null,
            'transport_trips' => $registration->transportTrips->map(fn ($row) => $this->attrs($row))->values()->all(),
            'travel_request' => $registration->travelRequest ? $this->attrs($registration->travelRequest) : null,
        ];
    }

    private function restoreRegistrations(Event $event, EventRegistrationResetSnapshot $snapshot): int
    {
        $payload = $snapshot->payload ?? [];
        $pairingIdMap = [];
        foreach ($payload['pairings'] ?? [] as $pairingRow) {
            $oldId = $pairingRow['attributes']['id'] ?? null;
            $attrs = $this->withoutId($pairingRow['attributes'] ?? []);
            unset($attrs['requested_by_registration_id']);
            $pairing = EventAccommodationPairing::query()->create($attrs);
            if ($oldId !== null) {
                $pairingIdMap[(int) $oldId] = $pairing->id;
            }
        }

        $idMap = [];
        foreach ($payload['registrations'] ?? [] as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            $registration = EventRegistration::query()->create($this->withoutId($row['attributes'] ?? []));
            $idMap[$oldId] = $registration->id;

            $this->restoreChildren(EventRegistrationQuestionAnswer::class, $row['answers'] ?? [], $registration->id);
            $serviceIdMap = [];
            foreach ($row['services'] ?? [] as $serviceRow) {
                $oldServiceId = $serviceRow['id'] ?? null;
                $service = EventRegService::query()->create($this->withoutId([
                    ...$serviceRow,
                    'registration_id' => $registration->id,
                ]));
                if ($oldServiceId !== null) {
                    $serviceIdMap[(int) $oldServiceId] = $service->id;
                }
            }
            $paymentIdMap = [];
            foreach ($row['payments'] ?? [] as $paymentRow) {
                $oldPaymentId = $paymentRow['id'] ?? null;
                $payment = EventRegistrationPayment::query()->create($this->withoutId([
                    ...$paymentRow,
                    'registration_id' => $registration->id,
                    'event_id' => $event->id,
                ]));
                if ($oldPaymentId !== null) {
                    $paymentIdMap[(int) $oldPaymentId] = $payment->id;
                }
            }

            if (! empty($row['planned_session_ids'])) {
                $registration->plannedSessions()->sync($row['planned_session_ids']);
            }

            if (is_array($row['check_in_token'] ?? null)) {
                EventCheckInToken::query()->create($this->withoutId([
                    ...$row['check_in_token'],
                    'registration_id' => $registration->id,
                    'event_id' => $event->id,
                ]));
            }

            $this->restoreChildren(EventCheckIn::class, $row['check_ins'] ?? [], $registration->id, ['event_id' => $event->id]);
            $this->restoreChildren(EventDayAttendance::class, $row['day_attendances'] ?? [], $registration->id, ['event_id' => $event->id]);
            $this->restoreChildren(EventSessionAttendance::class, $row['session_attendances'] ?? [], $registration->id, ['event_id' => $event->id]);
            $this->restoreChildren(EventAttendanceHistory::class, $row['attendance_histories'] ?? [], $registration->id, ['event_id' => $event->id]);
            $this->restoreChildren(EventRegistrationStatusTransition::class, $row['status_transitions'] ?? [], $registration->id);
            $this->restoreChildren(EventRegistrationTimeline::class, $row['timelines'] ?? [], $registration->id);
            $this->restoreChildren(EventRegistrationAuditLog::class, $row['audit_logs'] ?? [], $registration->id);
            $this->restoreChildren(EventCertificateIssuance::class, $row['certificates'] ?? [], $registration->id, ['event_id' => $event->id]);
            $this->restoreChildren(EventVolunteerAssignment::class, $row['volunteer_assignments'] ?? [], $registration->id, ['event_id' => $event->id]);

            if (is_array($row['allocation'] ?? null)) {
                $allocation = $this->withoutId($row['allocation']);
                $allocation['registration_id'] = $registration->id;
                $allocation['event_id'] = $event->id;
                if (isset($allocation['pairing_id'], $pairingIdMap[(int) $allocation['pairing_id']])) {
                    $allocation['pairing_id'] = $pairingIdMap[(int) $allocation['pairing_id']];
                }
                EventAccommodationAllocation::query()->create($allocation);
            }

            foreach ($row['transport_trips'] ?? [] as $tripRow) {
                $trip = $this->withoutId($tripRow);
                $trip['registration_id'] = $registration->id;
                $trip['event_id'] = $event->id;
                if (isset($trip['service_id'], $serviceIdMap[(int) $trip['service_id']])) {
                    $trip['service_id'] = $serviceIdMap[(int) $trip['service_id']];
                }
                if (isset($trip['payment_id'], $paymentIdMap[(int) $trip['payment_id']])) {
                    $trip['payment_id'] = $paymentIdMap[(int) $trip['payment_id']];
                }
                EventTransportTrip::query()->create($trip);
            }

            if (is_array($row['travel_request'] ?? null)) {
                $travel = $this->withoutId($row['travel_request']);
                $travel['registration_id'] = $registration->id;
                $travel['event_id'] = $event->id;
                if (isset($travel['service_id'], $serviceIdMap[(int) $travel['service_id']])) {
                    $travel['service_id'] = $serviceIdMap[(int) $travel['service_id']];
                }
                if (isset($travel['payment_id'], $paymentIdMap[(int) $travel['payment_id']])) {
                    $travel['payment_id'] = $paymentIdMap[(int) $travel['payment_id']];
                }
                EventTravelRequest::query()->create($travel);
            }
        }

        foreach ($payload['pairings'] ?? [] as $pairingRow) {
            $oldPairingId = (int) ($pairingRow['attributes']['id'] ?? 0);
            $newPairingId = $pairingIdMap[$oldPairingId] ?? null;
            if ($newPairingId === null) {
                continue;
            }
            $pairing = EventAccommodationPairing::query()->find($newPairingId);
            if ($pairing && isset($pairingRow['attributes']['requested_by_registration_id'])) {
                $oldRegId = (int) $pairingRow['attributes']['requested_by_registration_id'];
                if (isset($idMap[$oldRegId])) {
                    $pairing->requested_by_registration_id = $idMap[$oldRegId];
                    $pairing->save();
                }
            }
            foreach ($pairingRow['members'] ?? [] as $memberRow) {
                $member = $this->withoutId($memberRow);
                $member['pairing_id'] = $newPairingId;
                $oldRegId = (int) ($memberRow['registration_id'] ?? 0);
                if (! isset($idMap[$oldRegId])) {
                    continue;
                }
                $member['registration_id'] = $idMap[$oldRegId];
                EventAccommodationPairingMember::query()->create($member);
            }
        }

        return count($idMap);
    }

    private function restoreFormConfiguration(Event $event, EventRegistrationResetSnapshot $snapshot): int
    {
        $payload = $snapshot->payload ?? [];
        $event->registrationFieldSettings()->delete();
        $event->registrationQuestions()->withTrashed()->forceDelete();

        foreach ($payload['field_settings'] ?? [] as $row) {
            EventRegistrationFieldSetting::query()->create($this->withoutId($row));
        }
        foreach ($payload['questions'] ?? [] as $row) {
            EventRegistrationQuestion::query()->create($this->withoutId($row));
        }

        return count($payload['field_settings'] ?? []) + count($payload['questions'] ?? []);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $overrides
     */
    private function restoreChildren(string $class, array $rows, int $registrationId, array $overrides = []): void
    {
        foreach ($rows as $row) {
            $class::query()->create($this->withoutId([
                ...$row,
                'registration_id' => $registrationId,
                ...$overrides,
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withoutId(array $attributes): array
    {
        unset($attributes['id']);

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function attrs(\Illuminate\Database\Eloquent\Model $model): array
    {
        $raw = $model->getAttributes();
        foreach ($model->getCasts() as $key => $cast) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            if (in_array($cast, ['array', 'json'], true) && is_string($raw[$key]) && $raw[$key] !== '') {
                $decoded = json_decode($raw[$key], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $raw[$key] = $decoded;
                }
            }
        }

        return $raw;
    }
}
