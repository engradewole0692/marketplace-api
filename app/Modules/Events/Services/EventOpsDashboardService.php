<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Modules\Events\Enums\DayAttendanceStatus;
use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAccommodationAllocation;
use App\Modules\Events\Models\EventDay;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\MembershipClassification;
use Illuminate\Support\Carbon;

final class EventOpsDashboardService implements ServiceContract
{
    public function __construct(
        private readonly EventDayService $eventDayService,
        private readonly AttendanceService $attendanceService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Event $event, ?string $dayUuid = null): array
    {
        $event->loadMissing(['days', 'venue']);
        $days = $this->eventDayService->ensureDays($event);
        $current = $this->eventDayService->resolveCurrentDay($event, $dayUuid);
        $registrations = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->with(['person.member', 'member', 'services', 'dayAttendances', 'payments'])
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
            ],
            'transport' => [
                'requests' => $transport->count(),
                'confirmed' => $transport->filter(fn ($s) => $this->serviceConfirmed($s))->count(),
            ],
            'travel' => [
                'requests' => $travel->count(),
                'confirmed' => $travel->filter(fn ($s) => $this->serviceConfirmed($s))->count(),
            ],
            'allocations_confirmed' => EventAccommodationAllocation::query()
                ->where('event_id', $event->id)
                ->where('status', 'confirmed')
                ->count(),
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
            ->with(['person.member.country', 'member', 'dayAttendances', 'event']);

        if (($filters['membership'] ?? null) === 'approved_member') {
            $query->whereHas('person.member', function ($q): void {
                $q->where('approval_status', 'approved')->where('status', 'active')->whereNotNull('user_id');
            });
        } elseif (($filters['membership'] ?? null) === 'visitor') {
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
                if ($dayRow['attended']) {
                    $match = $days->first(fn (EventDay $d) => $d->uuid === $dayRow['id']);
                    if ($match) {
                        $attendedCounts[$match->id]++;
                    }
                }
            }
            $attended = (int) $summary['days_attended'];
            $exactly[$attended] = ($exactly[$attended] ?? 0) + 1;
            $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];

            $row = [
                'registration_id' => $registration->uuid,
                'registration_number' => $registration->registration_number,
                'name' => $registration->contactName(),
                'category' => $profile['participant_category'] ?? $profile['category'] ?? null,
                'membership' => $class['label'],
                'membership_type' => $class['type'],
                'membership_number' => $class['membership_number'],
                'country' => $registration->person?->country?->name,
                'region' => $registration->person?->region,
                'city' => $registration->person?->city,
                'days' => $dayMap,
                'attendance' => $summary['attendance_count'],
                'days_attended' => $attended,
                'days_total' => $summary['days_total'],
            ];
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
                'registered' => $registrations->count(),
                'attended_all_days' => $allDays,
                'attended_exactly' => $exactly,
                'attended_zero' => $exactly[0] ?? 0,
                'by_day' => $byDay,
            ],
            'rows' => $rows,
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
}
