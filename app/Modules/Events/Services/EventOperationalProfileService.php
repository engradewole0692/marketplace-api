<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\DayAttendanceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Enums\EventStaffDomain;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\EventCheckIn;
use App\Modules\Events\Models\EventDay;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\EventParticipantWorkspacePayload;
use App\Modules\Events\Support\MembershipClassification;

final class EventOperationalProfileService implements ServiceContract
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly EventDayService $eventDayService,
        private readonly EventAuthorizationService $authorization,
        private readonly SessionResolutionService $sessionResolutionService,
        private readonly SeatingService $seatingService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function forRegistration(EventRegistration $registration, array $data = [], ?User $actor = null): array
    {
        $registration->loadMissing([
            'event.days',
            'person.member.country',
            'person.country',
            'member.country',
            'member.ministry',
            'dayAttendances.day',
            'sessionAttendances.session',
            'plannedSessions',
            'services.option',
            'payments',
            'accommodationAllocation.option',
        ]);

        $event = $registration->event;
        $day = $event !== null
            ? $this->eventDayService->resolveCurrentDay($event, isset($data['event_day_id']) ? (string) $data['event_day_id'] : null)
            : null;

        $summary = $this->attendanceService->summarizeRegistration($registration);
        $membership = $summary['membership'] ?? MembershipClassification::forPerson($registration->person);
        $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];
        $workspace = EventParticipantWorkspacePayload::for($registration);
        $status = $registration->status instanceof RegistrationStatus
            ? $registration->status->value
            : (string) $registration->status;
        $current = $this->currentDayAttendance($registration, $day);
        $resolution = $event !== null ? $this->sessionResolutionService->resolve($event, $data) : null;
        $session = $resolution['session'] ?? null;
        if ($session !== null) {
            $sessionRow = $registration->sessionAttendances->firstWhere('event_session_id', $session->id);
            $sessionOpen = $sessionRow?->isOpen() ?? false;
            $current['already_session'] = $sessionOpen;
            if ($sessionOpen) {
                $current['status'] = DayAttendanceStatus::CheckedIn->value;
                $current['status_label'] = 'Checked in';
                $current['already_checked_in'] = true;
                $current['can_check_in'] = false;
                $current['can_check_out'] = (bool) ($event->checkout_enabled ?? true);
                $current['check_in_at'] = $sessionRow?->checked_in_at?->toIso8601String();
                $current['message'] = 'Already checked in'
                    .($sessionRow?->checked_in_at ? ' at '.$sessionRow->checked_in_at->toDayDateTimeString() : '')
                    .'.';
            } else {
                $current['status'] = DayAttendanceStatus::NotAttended->value;
                $current['already_checked_in'] = false;
                $current['can_check_in'] = ! in_array($status, ['cancelled', 'declined'], true);
                $current['can_check_out'] = false;
                $current['message'] = null;
            }
        }
        $visible = $actor !== null && $event !== null
            ? $this->authorization->visibleDomains($actor, $event)
            : array_map(fn (EventStaffDomain $domain) => $domain->value, EventStaffDomain::cases());

        $payload = [
            'identity' => [
                'name' => $registration->contactName(),
                'registration_id' => $registration->uuid,
                'registration_number' => $registration->registration_number,
                'person_no' => $registration->person?->person_no,
                'category' => $profile['participant_category'] ?? $profile['category'] ?? $profile['membership_status'] ?? null,
                'member_visitor' => MembershipClassification::presentation($membership),
                'membership' => $membership,
                'ordained' => $this->ordainedStatus($registration, $profile),
                'country' => $registration->person?->country?->name
                    ?? $registration->member?->country?->name
                    ?? ($profile['country'] ?? null),
                'region' => $registration->person?->region ?? ($profile['state_region'] ?? $profile['region'] ?? null),
                'ministry' => $registration->member?->ministry?->name ?? ($profile['ministry'] ?? null),
            ],
            'event' => [
                'id' => $event?->uuid,
                'title' => $event?->title,
                'attendance_mode' => $event?->attendance_mode instanceof \BackedEnum
                    ? $event->attendance_mode->value
                    : $event?->attendance_mode,
                'registration_date' => $registration->submitted_at?->toIso8601String() ?? $registration->created_at?->toIso8601String(),
                'registration_status' => $status,
            ],
            'current_day' => $day ? [
                'id' => $day->uuid,
                'label' => $day->label,
                'date' => $day->date?->toDateString(),
                'day_index' => $day->day_index,
            ] : null,
            'current_session' => $session ? [
                'id' => $session->uuid,
                'title' => $session->title,
                'starts_at' => $session->starts_at?->toIso8601String(),
                'ends_at' => $session->ends_at?->toIso8601String(),
            ] : null,
            'session_resolution' => $resolution['status'] ?? 'none',
            'planned_sessions' => $registration->plannedSessions->map(fn ($item) => [
                'id' => $item->uuid,
                'title' => $item->title,
            ])->values()->all(),
            'seating' => $event ? [
                'counts_toward_seating' => $this->seatingService->countsTowardSeating($event, $registration),
                'occupancy' => $this->seatingService->occupancy($event, $session, $day),
            ] : null,
            'attendance' => [
                'status' => $current['status'],
                'status_label' => $current['status_label'],
                'already_checked_in' => $current['status'] === DayAttendanceStatus::CheckedIn->value,
                'already_checked_out' => $current['status'] === DayAttendanceStatus::CheckedOut->value,
                'can_check_in' => $current['status'] !== DayAttendanceStatus::CheckedIn->value
                    && ! in_array($status, ['cancelled', 'declined'], true),
                'can_check_out' => ($event->checkout_enabled ?? true) && $current['status'] === DayAttendanceStatus::CheckedIn->value,
                'check_in_at' => $current['check_in_at'],
                'check_out_at' => $current['check_out_at'],
                'operator' => $current['operator'],
                'message' => $current['message'],
                'summary' => $summary,
            ],
            'visible_domains' => $visible,
        ];

        if (in_array(EventStaffDomain::Accommodation->value, $visible, true)) {
            $payload['accommodation'] = $this->accommodationCard($workspace, $registration);
        }
        if (in_array(EventStaffDomain::Logistics->value, $visible, true)) {
            $payload['logistics'] = $this->logisticsCard($workspace);
        }
        if (in_array(EventStaffDomain::Travel->value, $visible, true)) {
            $payload['travel'] = $this->travelCard($workspace);
        }
        if (in_array(EventStaffDomain::Finance->value, $visible, true)) {
            $payload['payments'] = $this->paymentCard($workspace);
        } else {
            $payload['payments'] = ['status' => $this->overallPaymentStatus($workspace)];
        }

        return $payload;
    }

    /**
     * @return array{status: string, status_label: string, check_in_at: ?string, check_out_at: ?string, operator: ?array<string, mixed>, message: ?string}
     */
    private function currentDayAttendance(EventRegistration $registration, ?EventDay $day): array
    {
        if ($day === null) {
            return [
                'status' => DayAttendanceStatus::NotAttended->value,
                'status_label' => DayAttendanceStatus::NotAttended->label(),
                'check_in_at' => null,
                'check_out_at' => null,
                'operator' => null,
                'message' => null,
            ];
        }

        $record = $registration->dayAttendances->firstWhere('event_day_id', $day->id);
        $status = $record?->status instanceof DayAttendanceStatus
            ? $record->status
            : DayAttendanceStatus::tryFrom((string) ($record?->status ?? DayAttendanceStatus::NotAttended->value))
              ?? DayAttendanceStatus::NotAttended;

        $checkIn = EventCheckIn::query()
            ->with('checkedInBy')
            ->where('registration_id', $registration->id)
            ->where('event_day_id', $day->id)
            ->latest('id')
            ->first();

        $operator = $checkIn?->checkedInBy ? [
            'id' => $checkIn->checkedInBy->uuid,
            'name' => $checkIn->checkedInBy->name,
        ] : null;

        $message = null;
        if ($status === DayAttendanceStatus::CheckedIn) {
            $when = $record?->checked_in_at?->toDayDateTimeString();
            $by = $operator['name'] ?? 'staff';
            $message = 'Already checked in'.($when ? ' at '.$when : '').' by '.$by.'.';
        } elseif ($status === DayAttendanceStatus::CheckedOut) {
            $when = $record?->checked_out_at?->toDayDateTimeString();
            $message = 'Already checked out'.($when ? ' at '.$when : '').'.';
        }

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'check_in_at' => $record?->checked_in_at?->toIso8601String(),
            'check_out_at' => $record?->checked_out_at?->toIso8601String(),
            'operator' => $operator,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{status: bool, label: string, source: ?string}
     */
    private function ordainedStatus(EventRegistration $registration, array $profile): array
    {
        $raw = $profile['ordained'] ?? $profile['is_ordained'] ?? $profile['ordained_minister'] ?? $profile['minister_status'] ?? null;
        if (is_bool($raw)) {
            return ['status' => $raw, 'label' => $raw ? 'Ordained' : 'Not ordained', 'source' => 'profile'];
        }
        if (is_string($raw) && $raw !== '') {
            $yes = in_array(strtolower($raw), ['1', 'true', 'yes', 'ordained'], true);

            return ['status' => $yes, 'label' => $yes ? 'Ordained' : $raw, 'source' => 'profile'];
        }

        $title = strtolower(trim((string) ($registration->member?->title ?? '')));
        $ordainedTitles = ['rev', 'reverend', 'pastor', 'apostle', 'bishop', 'prophet', 'evangelist'];
        foreach ($ordainedTitles as $needle) {
            if ($title !== '' && str_contains($title, $needle)) {
                return ['status' => true, 'label' => 'Ordained', 'source' => 'title'];
            }
        }

        return ['status' => false, 'label' => 'Not ordained', 'source' => null];
    }

    /**
     * @param  array<string, mixed>  $workspace
     * @return array<string, mixed>
     */
    private function accommodationCard(array $workspace, EventRegistration $registration): array
    {
        $pairings = is_array($workspace['pairings'] ?? null) ? $workspace['pairings'] : [];
        $allocation = is_array($workspace['allocation'] ?? null) ? $workspace['allocation'] : null;
        $service = $registration->services->first(function ($row) {
            $type = $row->type instanceof EventRegServiceType
                ? $row->type
                : EventRegServiceType::tryFrom((string) $row->type);

            return $type === EventRegServiceType::Accommodation;
        });
        $details = is_array($service?->details) ? $service->details : [];
        $payment = collect($workspace['service_payments'] ?? [])->firstWhere('purpose', 'accommodation');
        $groupMembers = [];
        foreach ($pairings as $pairing) {
            foreach (($pairing['members'] ?? []) as $member) {
                $groupMembers[] = [
                    'name' => $member['name'] ?? $member['registration_number'] ?? null,
                    'registration_id' => $member['registration_id'] ?? $member['id'] ?? null,
                    'status' => $member['status'] ?? null,
                ];
            }
        }

        return [
            'selected_option' => $allocation['option']['name'] ?? $details['option_name'] ?? $service?->option?->name,
            'occupancy_type' => $details['occupancy_type'] ?? $service?->option?->occupancyValue() ?? null,
            'group_members' => $groupMembers,
            'confirmation_status' => $allocation['status'] ?? ($pairings[0]['status'] ?? ($service?->status instanceof \BackedEnum ? $service->status->value : $service?->status)),
            'allocation' => $allocation,
            'actual_check_in_date' => $details['check_in'] ?? $allocation['check_in_date'] ?? null,
            'actual_check_out_date' => $details['check_out'] ?? $allocation['check_out_date'] ?? null,
            'billable_nights' => $details['billable_nights'] ?? $details['nights'] ?? null,
            'payment_status' => $payment['status'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $workspace
     * @return array<string, mixed>
     */
    private function logisticsCard(array $workspace): array
    {
        $trips = is_array($workspace['transport_trips'] ?? null) ? $workspace['transport_trips'] : [];
        $trip = $trips[0] ?? null;
        $payments = collect($workspace['service_payments'] ?? [])->firstWhere('purpose', 'transport');

        return [
            'trips' => $trips,
            'route' => $trip['route'] ?? $trip['option']['route'] ?? $trip['option']['name'] ?? null,
            'vehicle' => $trip['assigned_vehicle'] ?? $trip['option']['vehicle_name'] ?? null,
            'pickup' => $trip['pickup_location'] ?? null,
            'drop_off' => $trip['dropoff_location'] ?? null,
            'date' => $trip['trip_date'] ?? null,
            'time' => $trip['trip_time'] ?? null,
            'passengers' => $trip['passengers'] ?? null,
            'luggage' => $trip['luggage'] ?? null,
            'payment_status' => $payments['status'] ?? null,
            'status' => $trip['status'] ?? (count($trips) > 0 ? 'requested' : 'none'),
        ];
    }

    /**
     * @param  array<string, mixed>  $workspace
     * @return array<string, mixed>
     */
    private function travelCard(array $workspace): array
    {
        $travel = is_array($workspace['travel_request'] ?? null) ? $workspace['travel_request'] : null;
        $payment = collect($workspace['service_payments'] ?? [])->firstWhere('purpose', 'travel');

        return [
            'request' => $travel,
            'status' => $travel['status'] ?? 'none',
            'quote' => $travel['quote_amount'] ?? null,
            'currency' => $travel['currency'] ?? null,
            'payment_status' => $payment['status'] ?? null,
            'booking_status' => $travel['status'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $workspace
     * @return array<string, mixed>
     */
    private function paymentCard(array $workspace): array
    {
        $payments = is_array($workspace['service_payments'] ?? null) ? $workspace['service_payments'] : [];

        return [
            'status' => $this->overallPaymentStatus($workspace),
            'items' => $payments,
        ];
    }

    /**
     * @param  array<string, mixed>  $workspace
     */
    private function overallPaymentStatus(array $workspace): string
    {
        $payments = collect($workspace['service_payments'] ?? []);
        if ($payments->isEmpty()) {
            return 'none';
        }
        if ($payments->every(fn ($p) => in_array((string) ($p['status'] ?? ''), ['paid', 'approved', 'waived'], true))) {
            return 'paid';
        }
        if ($payments->contains(fn ($p) => in_array((string) ($p['status'] ?? ''), ['paid', 'approved'], true))) {
            return 'partial';
        }

        return 'pending';
    }
}
