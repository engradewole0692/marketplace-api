<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Events\Enums\EventStaffRole;
use App\Modules\Events\Enums\SeatClassificationScope;
use App\Modules\Events\Enums\SeatingArea;
use App\Modules\Events\Enums\SeatingPolicy;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDay;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventSeatClassification;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Models\EventSessionAttendance;
use App\Modules\Events\Models\EventStaffAssignment;
use App\Modules\Events\Support\MembershipClassification;
use Illuminate\Support\Collection;

final class SeatingService implements ServiceContract
{
    public function __construct(
        private readonly EventAuthorizationService $authorization,
    ) {}

    public function countsTowardSeating(Event $event, EventRegistration $registration): bool
    {
        $classifications = EventSeatClassification::query()->where('event_id', $event->id)->get();
        $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];
        $category = strtolower(trim((string) ($profile['participant_category'] ?? $profile['membership_status'] ?? '')));
        $membership = MembershipClassification::forPerson($registration->person) ?: MembershipClassification::forMember($registration->member);
        $membershipKey = ($membership['presentation'] ?? $membership['type'] ?? '') === 'approved_member' ? 'member' : 'visitor';

        $staff = EventStaffAssignment::query()
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->where(function ($query) use ($registration): void {
                if ($registration->member?->user_id) {
                    $query->where('user_id', $registration->member->user_id);
                }
                if ($registration->person?->user_id) {
                    $query->orWhere('user_id', $registration->person->user_id);
                }
            })
            ->first();

        $matches = [];
        foreach ($classifications as $row) {
            $scope = $row->scope instanceof SeatClassificationScope ? $row->scope : SeatClassificationScope::tryFrom((string) $row->scope);
            $key = strtolower(trim((string) $row->match_key));
            $matched = match ($scope) {
                SeatClassificationScope::Default => $key === '*' || $key === 'all' || $key === 'default',
                SeatClassificationScope::ParticipantCategory => $category !== '' && $key === $category,
                SeatClassificationScope::Membership => $key === $membershipKey || $key === strtolower((string) ($membership['type'] ?? '')),
                SeatClassificationScope::StaffDepartment => $staff !== null && strtolower((string) $staff->department) === $key,
                SeatClassificationScope::StaffRole => $staff !== null && EventStaffRole::normalize((string) $staff->staff_role)->value === $key,
                SeatClassificationScope::Speaker => $key === 'speaker' && $this->isSpeaker($event, $registration),
                default => false,
            };
            if ($matched) {
                $matches[] = $row;
            }
        }

        if ($matches !== []) {
            return (bool) end($matches)->counts_toward_seating;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{area: SeatingArea, counts: bool, overridden: bool, occupancy: array<string, mixed>}
     */
    public function place(
        Event $event,
        EventRegistration $registration,
        ?EventSession $session,
        ?EventDay $day,
        array $data,
        User $actor,
    ): array {
        $counts = $this->countsTowardSeating($event, $registration);
        if (! $counts) {
            return [
                'area' => SeatingArea::None,
                'counts' => false,
                'overridden' => false,
                'occupancy' => $this->occupancy($event, $session, $day),
            ];
        }

        $occupancy = $this->occupancy($event, $session, $day);
        $requested = SeatingArea::tryFrom((string) ($data['seating_area'] ?? ''));
        $override = (bool) ($data['capacity_override'] ?? $data['force_capacity'] ?? false);
        $policy = SeatingPolicy::fromEvent($event->seating_policy);

        $mainCap = (int) ($event->main_hall_capacity ?? 0);
        $overflowCap = (int) ($event->overflow_capacity ?? 0);
        $hasCapacityConfig = $mainCap > 0 || $overflowCap > 0;

        if (! $hasCapacityConfig) {
            $area = $requested && $requested !== SeatingArea::None ? $requested : SeatingArea::MainHall;

            return [
                'area' => $area,
                'counts' => true,
                'overridden' => false,
                'occupancy' => $occupancy,
            ];
        }

        $mainAvailable = max(0, $mainCap - (int) $occupancy['main_hall']['occupied']);
        $overflowAvailable = max(0, $overflowCap - (int) $occupancy['overflow']['occupied']);

        $area = $this->chooseArea($policy, $requested, $mainAvailable, $overflowAvailable, $mainCap, $overflowCap);

        if ($area !== null) {
            return [
                'area' => $area,
                'counts' => true,
                'overridden' => false,
                'occupancy' => $occupancy,
            ];
        }

        if ($override && $this->canOverride($actor, $event)) {
            $fallback = $requested && $requested !== SeatingArea::None
                ? $requested
                : ($overflowCap > 0 && $overflowAvailable === 0 && $mainAvailable === 0 ? SeatingArea::Overflow : SeatingArea::MainHall);

            return [
                'area' => $fallback,
                'counts' => true,
                'overridden' => true,
                'occupancy' => $occupancy,
            ];
        }

        $this->throwCapacity($mainAvailable, $overflowAvailable, $overflowCap, $occupancy);
    }

    /**
     * @return array<string, mixed>
     */
    public function occupancy(Event $event, ?EventSession $session, ?EventDay $day): array
    {
        $mainCap = (int) ($event->main_hall_capacity ?? 0);
        $overflowCap = (int) ($event->overflow_capacity ?? 0);

        if ($session !== null) {
            $open = EventSessionAttendance::query()
                ->where('event_session_id', $session->id)
                ->whereNull('checked_out_at')
                ->where('status', 'checked_in')
                ->get();
        } elseif ($day !== null) {
            $open = EventDayAttendance::query()
                ->where('event_day_id', $day->id)
                ->where('status', 'checked_in')
                ->whereNull('checked_out_at')
                ->get();
        } else {
            $open = collect();
        }

        $seatCounted = $open->filter(function ($row): bool {
            if (property_exists($row, 'counts_toward_seating') || isset($row->counts_toward_seating)) {
                return (bool) $row->counts_toward_seating;
            }

            return true;
        });
        $main = $seatCounted->filter(fn ($row): bool => $this->areaValue($row) === SeatingArea::MainHall->value)->count();
        $overflow = $seatCounted->filter(fn ($row): bool => $this->areaValue($row) === SeatingArea::Overflow->value)->count();
        $nonSeat = $open->count() - $seatCounted->count();

        return [
            'main_hall' => [
                'capacity' => $mainCap,
                'occupied' => $main,
                'available' => $mainCap > 0 ? max(0, $mainCap - $main) : null,
            ],
            'overflow' => [
                'capacity' => $overflowCap,
                'occupied' => $overflow,
                'available' => $overflowCap > 0 ? max(0, $overflowCap - $overflow) : null,
            ],
            'total' => [
                'capacity' => $mainCap + $overflowCap,
                'occupied' => $main + $overflow,
                'available' => ($mainCap + $overflowCap) > 0 ? max(0, $mainCap + $overflowCap - $main - $overflow) : null,
                'seat_counted' => $seatCounted->count(),
                'non_seat_counted' => $nonSeat,
                'checked_in' => $open->count(),
            ],
        ];
    }

    public function canOverride(User $actor, Event $event): bool
    {
        if ($this->authorization->isGlobalEventAdmin($actor)) {
            return true;
        }

        $role = $this->authorization->staffRoleFor($actor, $event);

        return $role === EventStaffRole::EventAdministrator;
    }

    /**
     * @return Collection<int, EventSeatClassification>
     */
    public function listClassifications(Event $event): Collection
    {
        return EventSeatClassification::query()->where('event_id', $event->id)->orderBy('id')->get();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, EventSeatClassification>
     */
    public function syncClassifications(Event $event, array $rows): Collection
    {
        $keep = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['scope']) || empty($row['match_key'])) {
                continue;
            }
            $model = EventSeatClassification::query()->updateOrCreate(
                [
                    'event_id' => $event->id,
                    'scope' => $row['scope'],
                    'match_key' => strtolower(trim((string) $row['match_key'])),
                ],
                [
                    'label' => $row['label'] ?? null,
                    'counts_toward_seating' => array_key_exists('counts_toward_seating', $row)
                        ? (bool) $row['counts_toward_seating']
                        : true,
                ],
            );
            $keep[] = $model->id;
        }

        EventSeatClassification::query()
            ->where('event_id', $event->id)
            ->when($keep !== [], fn ($query) => $query->whereNotIn('id', $keep))
            ->delete();

        return $this->listClassifications($event);
    }

    private function chooseArea(
        SeatingPolicy $policy,
        ?SeatingArea $requested,
        int $mainAvailable,
        int $overflowAvailable,
        int $mainCap,
        int $overflowCap,
    ): ?SeatingArea {
        if ($requested === SeatingArea::MainHall) {
            return $mainAvailable > 0 || $mainCap === 0 ? SeatingArea::MainHall : null;
        }
        if ($requested === SeatingArea::Overflow) {
            return $overflowAvailable > 0 || $overflowCap === 0 ? SeatingArea::Overflow : null;
        }

        return match ($policy) {
            SeatingPolicy::MainOnly => $mainAvailable > 0 ? SeatingArea::MainHall : null,
            SeatingPolicy::OverflowOnly => $overflowAvailable > 0 ? SeatingArea::Overflow : null,
            SeatingPolicy::StaffSelect => $mainAvailable > 0
                ? SeatingArea::MainHall
                : ($overflowAvailable > 0 ? SeatingArea::Overflow : null),
            SeatingPolicy::MainThenOverflow => $mainAvailable > 0
                ? SeatingArea::MainHall
                : ($overflowAvailable > 0 ? SeatingArea::Overflow : null),
        };
    }

    /**
     * @param  array<string, mixed>  $occupancy
     */
    private function throwCapacity(int $mainAvailable, int $overflowAvailable, int $overflowCap, array $occupancy): never
    {
        if ($mainAvailable <= 0 && $overflowAvailable > 0) {
            throw new ApiException(
                ApiErrorCode::Conflict,
                'Main Hall capacity reached. Overflow available.',
                [
                    'seating' => ['Main Hall capacity reached.'],
                    'overflow_available' => ['true'],
                    'occupancy' => [json_encode($occupancy)],
                ],
                409,
            );
        }

        throw new ApiException(
            ApiErrorCode::Conflict,
            'Configured seating capacity has been reached for this session.',
            [
                'seating' => ['Seat capacity reached for this session.'],
                'occupancy' => [json_encode($occupancy)],
            ],
            409,
        );
    }

    private function areaValue(object $row): string
    {
        $area = $row->seating_area ?? null;
        if ($area instanceof SeatingArea) {
            return $area->value;
        }

        return (string) ($area ?: SeatingArea::MainHall->value);
    }

    private function isSpeaker(Event $event, EventRegistration $registration): bool
    {
        $name = strtolower((string) $registration->contactName());
        if ($name === '') {
            return false;
        }

        return $event->speakers()->get()->contains(
            fn ($speaker): bool => strtolower((string) $speaker->name) === $name,
        );
    }
}
