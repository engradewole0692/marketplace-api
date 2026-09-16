<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Modules\Events\Enums\DayAttendanceStatus;
use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAccommodationAllocation;
use App\Modules\Events\Models\EventAccommodationPairing;
use App\Modules\Events\Models\EventDay;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Models\EventSessionAttendance;
use App\Modules\Events\Models\EventTransportTrip;
use App\Modules\Events\Models\EventTravelRequest;
use App\Modules\Events\Support\MembershipClassification;
use Illuminate\Support\Carbon;

final class EventOpsDashboardService implements ServiceContract
{
    public function __construct(
        private readonly EventDayService $eventDayService,
        private readonly AttendanceService $attendanceService,
        private readonly SessionResolutionService $sessionResolutionService,
        private readonly SeatingService $seatingService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Event $event, ?string $dayUuid = null, ?string $sessionUuid = null): array
    {
        $event->loadMissing(['days', 'venue', 'accommodationOptions', 'sessions']);
        $days = $this->eventDayService->ensureDays($event);
        $current = $this->eventDayService->resolveCurrentDay($event, $dayUuid);
        $resolution = $this->sessionResolutionService->resolve($event, ['event_session_id' => $sessionUuid]);
        $currentSession = $resolution['session'];
        $seating = $this->seatingService->occupancy($event, $currentSession, $current);
        $registrations = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->with(['person.member', 'member', 'services', 'dayAttendances', 'payments', 'plannedSessions', 'sessionAttendances'])
            ->get();

        $todayAttendances = EventDayAttendance::query()
            ->where('event_id', $event->id)
            ->where('event_day_id', $current->id)
            ->get();

        $checkedInToday = $todayAttendances->filter(fn ($row) => $this->status($row) === DayAttendanceStatus::CheckedIn)->count();
        $checkedOutToday = $todayAttendances->filter(fn ($row) => $this->status($row) === DayAttendanceStatus::CheckedOut)->count();
        $attendedToday = $todayAttendances->filter(fn ($row) => $this->status($row)?->countsAsAttended())->count();

        $members = 0;
        $visitors = 0;
        foreach ($registrations as $registration) {
            $class = MembershipClassification::forPerson($registration->person) ?: MembershipClassification::forMember($registration->member);
            if ($class['type'] === 'approved_member') {
                $members++;
            } else {
                $visitors++;
            }
        }

        $services = EventRegService::query()
            ->whereIn('registration_id', $registrations->pluck('id'))
            ->get();

        $acc = $services->where('type', EventRegServiceType::Accommodation);
        $transport = $services->where('type', EventRegServiceType::Transport);
        $travel = $services->where('type', EventRegServiceType::Travel);

        $paid = $registrations->filter(fn (EventRegistration $r) => $r->payments->contains(
            fn ($p) => in_array($p->status instanceof \BackedEnum ? $p->status->value : (string) $p->status, ['paid', 'approved', 'waived'], true),
        ))->count();

        $payments = EventRegistrationPayment::query()->where('event_id', $event->id)->get();
        $paymentStatus = fn ($p) => $p->status instanceof \BackedEnum ? $p->status->value : (string) $p->status;
        $trips = EventTransportTrip::query()->where('event_id', $event->id)->get();
        $travelRows = EventTravelRequest::query()->where('event_id', $event->id)->get();
        $pairings = EventAccommodationPairing::query()->where('event_id', $event->id)->get();
        $allocations = EventAccommodationAllocation::query()->where('event_id', $event->id)->get();
        $totalCapacity = $event->accommodationOptions->sum(fn ($option) => $option->totalSpaces());

        return [
            'event' => [
                'id' => $event->uuid,
                'title' => $event->title,
                'attendance_mode' => $event->attendance_mode instanceof \BackedEnum
                    ? $event->attendance_mode->value
                    : $event->attendance_mode,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
            ],
            'current_day' => [
                'id' => $current->uuid,
                'label' => $current->label,
                'date' => $current->date?->toDateString(),
                'day_index' => $current->day_index,
            ],
            'current_session' => $currentSession ? [
                'id' => $currentSession->uuid,
                'title' => $currentSession->title,
                'starts_at' => $currentSession->starts_at?->toIso8601String(),
                'ends_at' => $currentSession->ends_at?->toIso8601String(),
                'resolution' => $resolution['status'],
            ] : [
                'id' => null,
                'title' => null,
                'starts_at' => null,
                'ends_at' => null,
                'resolution' => $resolution['status'],
            ],
            'session_resolution' => $resolution['status'],
            'sessions' => $this->sessionRows($event, $registrations, $currentSession),
            'seating' => $seating,
            'checkout_enabled' => (bool) ($event->checkout_enabled ?? true),
            'days' => $days->map(fn (EventDay $day) => [
                'id' => $day->uuid,
                'label' => $day->label,
                'date' => $day->date?->toDateString(),
                'day_index' => $day->day_index,
                'is_current' => $day->id === $current->id,
            ])->values(),
            'totals' => [
                'registered' => $registrations->count(),
                'expected_today' => $registrations->count(),
                'checked_in_today' => $checkedInToday,
                'checked_out_today' => $checkedOutToday,
                'currently_present' => $checkedInToday,
                'attended_today' => $attendedToday,
                'attendance_count' => $attendedToday,
                'approved_members' => $members,
                'visitors' => $visitors,
                'payments_settled' => $paid,
            ],
            'accommodation' => [
                'requests' => $acc->count(),
                'confirmed' => $acc->filter(fn ($s) => $this->serviceConfirmed($s))->count(),
                'pending' => $acc->filter(fn ($s) => ! $this->serviceConfirmed($s))->count(),
                'pending_pairings' => $pairings->whereIn('status', ['pending_confirmation', 'incomplete'])->count(),
                'confirmed_groups' => $pairings->where('status', 'confirmed')->count(),
                'incomplete_groups' => $pairings->where('status', 'incomplete')->count(),
                'capacity' => $totalCapacity,
                'allocated' => $allocations->whereIn('status', ['pending', 'confirmed'])->sum('spaces'),
            ],
            'transport' => [
                'requests' => $transport->count(),
                'confirmed' => $transport->filter(fn ($s) => $this->serviceConfirmed($s))->count(),
                'trips' => $trips->count(),
                'assigned' => $trips->where('status', 'assigned')->count(),
            ],
            'travel' => [
                'requests' => $travel->count(),
                'confirmed' => $travel->filter(fn ($s) => $this->serviceConfirmed($s))->count(),
                'booked' => $travelRows->whereIn('status', ['booked', 'completed'])->count(),
            ],
            'payments' => [
                'pending' => $payments->filter(fn ($p) => in_array($paymentStatus($p), ['pending', 'unpaid'], true))->count(),
                'verified' => $payments->filter(fn ($p) => in_array($paymentStatus($p), ['paid', 'approved'], true))->count(),
            ],
            'allocations_confirmed' => $allocations->where('status', 'confirmed')->count(),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function attendanceReport(Event $event, array $filters = []): array
    {
        $days = $this->eventDayService->ensureDays($event);
        $query = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->with(['person.member.country', 'person.country', 'member.ministry', 'dayAttendances', 'event', 'services', 'payments', 'plannedSessions', 'sessionAttendances.session']);

        $membershipFilter = MembershipClassification::normalizeFilter($filters['membership'] ?? null);
        if ($membershipFilter === 'approved_member') {
            $query->whereHas('person.member', function ($q): void {
                $q->where('approval_status', 'approved')->where('status', 'active')->whereNotNull('user_id');
            });
        } elseif ($membershipFilter === 'visitor') {
            $query->where(function ($q): void {
                $q->whereDoesntHave('person.member')
                    ->orWhereHas('person.member', function ($member): void {
                        $member->where('approval_status', '!=', 'approved')
                            ->orWhere('status', '!=', 'active')
                            ->orWhereNull('user_id');
                    });
            });
        }

        $registrations = $query->get();
        $rows = [];
        $attendedCounts = [];
        foreach ($days as $day) {
            $attendedCounts[$day->id] = 0;
        }

        $exactly = array_fill(0, $days->count() + 1, 0);

        foreach ($registrations as $registration) {
            $summary = $this->attendanceService->summarizeRegistration($registration);
            $class = $summary['membership'];
            $dayMap = [];
            foreach ($summary['days'] as $dayRow) {
                $dayMap[$dayRow['id']] = $dayRow;
            }
            $attended = (int) $summary['days_attended'];
            $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];

            $row = [
                'registration_id' => $registration->uuid,
                'registration_number' => $registration->registration_number,
                'name' => $registration->contactName(),
                'category' => $profile['participant_category'] ?? $profile['category'] ?? null,
                'membership' => $class['label'],
                'membership_type' => $class['type'],
                'membership_presentation' => MembershipClassification::presentation($class),
                'membership_number' => $class['membership_number'],
                'country' => $registration->person?->country?->name,
                'region' => $registration->person?->region,
                'city' => $registration->person?->city,
                'ministry' => $registration->member?->ministry?->name ?? ($profile['ministry'] ?? null),
                'ordained' => $profile['ordained'] ?? $profile['is_ordained'] ?? null,
                'days' => $dayMap,
                'attendance' => $summary['attendance_count'],
                'days_attended' => $attended,
                'days_total' => $summary['days_total'],
                'planned_sessions' => $registration->plannedSessions->map(fn ($session) => [
                    'id' => $session->uuid,
                    'title' => $session->title,
                ])->values()->all(),
                'actual_sessions' => $registration->sessionAttendances->map(fn ($row) => [
                    'id' => $row->session?->uuid,
                    'title' => $row->session?->title,
                    'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
                    'seating_area' => $row->seating_area instanceof \BackedEnum ? $row->seating_area->value : $row->seating_area,
                    'counts_toward_seating' => (bool) $row->counts_toward_seating,
                ])->values()->all(),
            ];
            if (! $this->rowMatchesAttendanceFilters($row, $registration, $filters)) {
                continue;
            }
            $exactly[$attended] = ($exactly[$attended] ?? 0) + 1;
            foreach ($summary['days'] as $dayRow) {
                if ($dayRow['attended']) {
                    $match = $days->first(fn (EventDay $d) => $d->uuid === $dayRow['id']);
                    if ($match) {
                        $attendedCounts[$match->id]++;
                    }
                }
            }
            $rows[] = $row;
        }

        $byDay = [];
        foreach ($days as $day) {
            $byDay[] = [
                'id' => $day->uuid,
                'label' => $day->label,
                'date' => $day->date?->toDateString(),
                'attended' => $attendedCounts[$day->id] ?? 0,
            ];
        }

        $totalDays = max(1, $days->count());
        $allDays = $exactly[$totalDays] ?? 0;

        return [
            'event' => ['id' => $event->uuid, 'title' => $event->title],
            'days' => $byDay,
            'summary' => [
                'registered' => count($rows),
                'attended_all_days' => $allDays,
                'attended_exactly' => $exactly,
                'attended_zero' => $exactly[0] ?? 0,
                'by_day' => $byDay,
            ],
            'rows' => $rows,
            'sessions' => $this->sessionRows($event, $registrations, $this->sessionResolutionService->resolve($event)['session'] ?? null),
        ];
    }

    private function status(EventDayAttendance $row): ?DayAttendanceStatus
    {
        return $row->status instanceof DayAttendanceStatus
            ? $row->status
            : DayAttendanceStatus::tryFrom((string) $row->status);
    }

    private function serviceConfirmed(EventRegService $service): bool
    {
        $status = $service->status instanceof EventRegServiceStatus
            ? $service->status
            : EventRegServiceStatus::tryFrom((string) $service->status);

        return $status?->isConfirmed() ?? false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function rowMatchesAttendanceFilters(array $row, EventRegistration $registration, array $filters): bool
    {
        $category = strtolower(trim((string) ($filters['category'] ?? $filters['participant_category'] ?? '')));
        if ($category !== '' && strtolower((string) ($row['category'] ?? '')) !== $category) {
            return false;
        }

        $country = strtolower(trim((string) ($filters['country'] ?? '')));
        if ($country !== '' && ! str_contains(strtolower((string) ($row['country'] ?? '')), $country)) {
            return false;
        }

        $region = strtolower(trim((string) ($filters['region'] ?? '')));
        if ($region !== '' && ! str_contains(strtolower((string) ($row['region'] ?? '')), $region)) {
            return false;
        }

        $ministry = strtolower(trim((string) ($filters['ministry'] ?? '')));
        if ($ministry !== '' && ! str_contains(strtolower((string) ($row['ministry'] ?? '')), $ministry)) {
            return false;
        }

        $ordained = $filters['ordained'] ?? null;
        if ($ordained !== null && $ordained !== '') {
            $want = in_array(strtolower((string) $ordained), ['1', 'true', 'yes', 'ordained'], true);
            $have = in_array(strtolower((string) ($row['ordained'] ?? '')), ['1', 'true', 'yes', 'ordained'], true);
            if ($want !== $have) {
                return false;
            }
        }

        $wantsAccommodation = $filters['accommodation'] ?? null;
        if ($wantsAccommodation !== null && $wantsAccommodation !== '') {
            $has = $registration->services->contains(function ($service) {
                $type = $service->type instanceof EventRegServiceType
                    ? $service->type
                    : EventRegServiceType::tryFrom((string) $service->type);

                return $type === EventRegServiceType::Accommodation;
            });
            if (filter_var($wantsAccommodation, FILTER_VALIDATE_BOOLEAN) !== $has) {
                return false;
            }
        }

        $wantsTransport = $filters['transport'] ?? null;
        if ($wantsTransport !== null && $wantsTransport !== '') {
            $has = $registration->services->contains(function ($service) {
                $type = $service->type instanceof EventRegServiceType
                    ? $service->type
                    : EventRegServiceType::tryFrom((string) $service->type);

                return $type === EventRegServiceType::Transport;
            });
            if (filter_var($wantsTransport, FILTER_VALIDATE_BOOLEAN) !== $has) {
                return false;
            }
        }

        $payment = strtolower(trim((string) ($filters['payment'] ?? $filters['payment_status'] ?? '')));
        if ($payment !== '') {
            $statuses = $registration->payments->map(function ($item) {
                return strtolower($item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status);
            });
            $settled = $statuses->contains(fn ($status) => in_array($status, ['paid', 'approved', 'waived'], true));
            if ($payment === 'paid' && ! $settled) {
                return false;
            }
            if ($payment === 'pending' && $settled) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EventRegistration>  $registrations
     * @return list<array<string, mixed>>
     */
    private function sessionRows(Event $event, $registrations, ?EventSession $currentSession): array
    {
        $sessions = $this->sessionResolutionService->activeTimedSessions($event);
        if ($sessions->isEmpty()) {
            $sessions = $event->sessions()->orderBy('sort_order')->orderBy('starts_at')->get();
        }

        return $sessions->map(function (EventSession $session) use ($event, $registrations, $currentSession): array {
            $planned = $registrations->filter(
                fn (EventRegistration $registration): bool => $registration->plannedSessions->contains('id', $session->id),
            )->count();
            $actual = EventSessionAttendance::query()
                ->where('event_session_id', $session->id)
                ->where('status', 'checked_in')
                ->count();
            $occupancy = $this->seatingService->occupancy($event, $session, null);

            return [
                'id' => $session->uuid,
                'title' => $session->title,
                'session_number' => $session->session_number,
                'starts_at' => $session->starts_at?->toIso8601String(),
                'ends_at' => $session->ends_at?->toIso8601String(),
                'is_current' => $currentSession?->id === $session->id,
                'planned' => $planned,
                'actual' => $actual,
                'seating' => $occupancy,
            ];
        })->values()->all();
    }
}
