<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\AccommodationOccupancyType;
use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Enums\PaymentMethodType;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAccommodationAllocation;
use App\Modules\Events\Models\EventAccommodationOption;
use App\Modules\Events\Models\EventAccommodationPairing;
use App\Modules\Events\Models\EventAccommodationPairingMember;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccommodationService implements ServiceContract
{
    public function __construct(
        private readonly RegistrationAuditService $auditService,
        private readonly EventServiceNotificationService $serviceNotifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOption(Event $event, array $data, User $actor): EventAccommodationOption
    {
        $option = EventAccommodationOption::query()->create($this->optionAttributes($event, $data));
        $this->enableEventFlag($event, 'accommodation_enabled');
        app(EventAuditService::class)->record(
            \App\Modules\Events\Enums\EventAuditEventType::AccommodationOptionChanged,
            $event,
            $actor,
            EventAccommodationOption::class,
            $option->id,
            null,
            [
                'name' => $option->name,
                'price' => $option->price,
                'currency' => $option->currency,
                'is_active' => $option->is_active,
            ],
            ['action' => 'created'],
        );

        return $option;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOption(EventAccommodationOption $option, array $data, ?User $actor = null): EventAccommodationOption
    {
        $option->loadMissing('event');
        $old = [
            'name' => $option->name,
            'price' => $option->price,
            'currency' => $option->currency,
            'is_active' => $option->is_active,
            'image_media_ids' => $option->image_media_ids,
        ];
        $option->fill($this->optionAttributes($option->event, $data, true));
        $option->save();
        if ($option->is_active !== false && $option->event) {
            $this->enableEventFlag($option->event, 'accommodation_enabled');
        }
        app(EventAuditService::class)->record(
            \App\Modules\Events\Enums\EventAuditEventType::AccommodationOptionChanged,
            $option->event,
            $actor,
            EventAccommodationOption::class,
            $option->id,
            $old,
            [
                'name' => $option->name,
                'price' => $option->price,
                'currency' => $option->currency,
                'is_active' => $option->is_active,
                'image_media_ids' => $option->image_media_ids,
            ],
            ['action' => 'updated'],
        );

        return $option->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(EventAccommodationOption $option): array
    {
        $pending = (int) $option->allocations()->where('status', 'pending')->sum('spaces');
        $confirmed = (int) $option->allocations()->where('status', 'confirmed')->sum('spaces');
        $allocated = $pending + $confirmed;
        $total = $option->totalSpaces();
        $capacity = max(1, (int) $option->capacity);
        $unitCount = max(0, (int) $option->unit_count);

        return [
            'total_spaces' => $total,
            'allocated_spaces' => $allocated,
            'available_spaces' => max(0, $total - $allocated),
            'pending_spaces' => $pending,
            'confirmed_spaces' => $confirmed,
            'total_units' => $unitCount,
            'allocated_units' => (int) ceil($allocated / $capacity),
            'available_units' => max(0, $unitCount - (int) ceil($allocated / $capacity)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function requestForRegistration(EventRegistration $registration, array $data, ?User $actor = null): EventRegService
    {
        $registration->loadMissing('event');
        $option = $this->resolveOption($registration->event_id, $data['option_id'] ?? null, required: ! empty($data['option_id']));
        $occupancy = (string) ($data['occupancy_type'] ?? $option?->occupancyValue() ?? AccommodationOccupancyType::Shared->value);
        $checkIn = $data['arrival_date'] ?? $data['check_in_date'] ?? $registration->arrival_date?->toDateString();
        $checkOut = $data['departure_date'] ?? $data['check_out_date'] ?? $registration->departure_date?->toDateString();
        $nights = $checkIn && $checkOut ? EventAccommodationOption::nightsBetween($checkIn, $checkOut) : 1;
        $this->assertStayWindow($registration->event, $option, $checkIn, $checkOut, $nights);

        if ($occupancy === AccommodationOccupancyType::Shared->value && $option && (int) $option->capacity < 2) {
            throw ValidationException::withMessages([
                'occupancy_type' => ['This accommodation is not configured for sharing.'],
            ]);
        }

        $quote = $option?->quote($nights, $occupancy);

        $service = EventRegService::query()->updateOrCreate(
            [
                'registration_id' => $registration->id,
                'type' => EventRegServiceType::Accommodation,
            ],
            [
                'status' => EventRegServiceStatus::Requested,
                'option_id' => $option?->id,
                'details' => array_filter([
                    'occupancy_type' => $occupancy,
                    'requested_option' => $option?->name,
                    'arrival_date' => $checkIn,
                    'departure_date' => $checkOut,
                    'nights' => $nights,
                    'person_night' => $quote['person_night'] ?? null,
                    'person_total' => $quote['person_total'] ?? null,
                    'room_night' => $quote['room_night'] ?? null,
                    'currency' => $quote['currency'] ?? $option?->currency,
                    'notes' => $data['notes'] ?? null,
                    'incomplete_group' => $occupancy === AccommodationOccupancyType::Shared->value,
                ], fn ($v) => $v !== null && $v !== ''),
            ],
        );

        $registration->accommodation_required = true;
        $registration->arrival_date = $checkIn ?: $registration->arrival_date;
        $registration->departure_date = $checkOut ?: $registration->departure_date;
        $registration->save();

        $shareWith = array_values(array_filter((array) ($data['share_with'] ?? $data['registration_ids'] ?? [])));
        $existingAllocation = EventAccommodationAllocation::query()
            ->where('registration_id', $registration->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->first();
        $existingMember = EventAccommodationPairingMember::query()
            ->where('registration_id', $registration->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereHas('pairing', fn ($query) => $query->whereIn('status', ['pending_confirmation', 'incomplete', 'confirmed']))
            ->with('pairing')
            ->first();

        if ($occupancy === AccommodationOccupancyType::Shared->value && $option) {
            if ($existingMember?->pairing) {
                $this->applyMemberStay($existingMember, $registration, $checkIn, $checkOut);
                $this->recalculateSharedBilling($existingMember->pairing->fresh(['members', 'option']), $actor);
                $extra = array_values(array_filter(
                    $shareWith,
                    fn ($id) => $id !== $registration->uuid,
                ));
                if ($extra !== []) {
                    $this->inviteToPairing($existingMember->pairing, $registration, $extra, $actor);
                }
            } else {
                $this->requestPairing($registration, $shareWith, $option->uuid, $actor, [
                    'check_in_date' => $checkIn,
                    'check_out_date' => $checkOut,
                ]);
            }
        } elseif ($occupancy === AccommodationOccupancyType::Private->value && $option && $existingAllocation === null) {
            $this->allocate($registration, $option, $actor, 1, null, $checkIn, $checkOut);
        }

        $this->auditService->record(
            RegistrationAuditEventType::ServiceUpdated,
            $registration,
            $actor,
            null,
            ['service' => 'accommodation', 'status' => 'requested'],
        );

        return $service->fresh(['option']);
    }

    /**
     * @param  list<string>  $registrationUuids
     * @param  array<string, mixed>  $dates
     */
    public function requestPairing(EventRegistration $requester, array $registrationUuids, ?string $optionId, ?User $actor, array $dates = []): EventAccommodationPairing
    {
        $ids = collect($registrationUuids)
            ->push($requester->uuid)
            ->unique()
            ->values();

        $partners = EventRegistration::query()
            ->where('event_id', $requester->event_id)
            ->whereIn('uuid', $ids)
            ->get();

        if ($partners->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'registration_ids' => ['Every roommate must be registered for the same event. Unregistered people cannot be selected.'],
            ]);
        }

        $option = $this->resolveOption($requester->event_id, $optionId, required: false);
        if ($option && $partners->count() > (int) $option->capacity) {
            throw ValidationException::withMessages([
                'registration_ids' => ['This room capacity is '.$option->capacity.'.'],
            ]);
        }

        foreach ($partners as $partner) {
            $this->assertNoActivePairingConflict($partner, null);
        }

        $checkIn = $dates['check_in_date'] ?? $dates['arrival_date'] ?? $requester->arrival_date?->toDateString();
        $checkOut = $dates['check_out_date'] ?? $dates['departure_date'] ?? $requester->departure_date?->toDateString();
        $nights = $checkIn && $checkOut ? EventAccommodationOption::nightsBetween($checkIn, $checkOut) : null;
        if ($checkIn && $checkOut) {
            $this->assertStayWindow($requester->event, $option, $checkIn, $checkOut, (int) $nights);
        }

        return DB::transaction(function () use ($requester, $partners, $option, $actor, $checkIn, $checkOut, $nights): EventAccommodationPairing {
            $status = 'pending_confirmation';
            if ($option && $option->require_full_occupancy && $partners->count() < (int) $option->capacity) {
                $status = 'incomplete';
            }

            $pairing = EventAccommodationPairing::query()->create([
                'event_id' => $requester->event_id,
                'option_id' => $option?->id,
                'status' => $status,
                'requested_by_registration_id' => $requester->id,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'nights' => $nights,
            ]);

            foreach ($partners as $partner) {
                $memberIn = $partner->id === $requester->id
                    ? $checkIn
                    : ($this->dateString($partner->arrival_date) ?: $checkIn);
                $memberOut = $partner->id === $requester->id
                    ? $checkOut
                    : ($this->dateString($partner->departure_date) ?: $checkOut);
                EventAccommodationPairingMember::query()->create([
                    'pairing_id' => $pairing->id,
                    'registration_id' => $partner->id,
                    'status' => $partner->id === $requester->id ? 'confirmed' : 'pending',
                    'confirmed_at' => $partner->id === $requester->id ? now() : null,
                    'check_in_date' => $memberIn,
                    'check_out_date' => $memberOut,
                    'actual_nights' => $memberIn && $memberOut ? EventAccommodationOption::nightsBetween($memberIn, $memberOut) : $nights,
                ]);
            }

            $this->auditService->record(
                RegistrationAuditEventType::PairingRequested,
                $requester,
                $actor,
                null,
                ['pairing_id' => $pairing->uuid, 'count' => $partners->count()],
            );

            $pairing = $pairing->fresh(['members.registration.person', 'option']);
            foreach ($partners as $partner) {
                if ($partner->id === $requester->id) {
                    continue;
                }
                $this->serviceNotifications->notifyPairingInvitation($partner, $pairing, $requester);
            }

            $this->finalizePairingIfReady($pairing, $actor);

            return $pairing->fresh(['members.registration.person', 'option']);
        });
    }

    /**
     * @param  list<string>  $registrationUuids
     */
    public function inviteToPairing(EventAccommodationPairing $pairing, EventRegistration $actorRegistration, array $registrationUuids, ?User $actor): EventAccommodationPairing
    {
        if ((int) $pairing->requested_by_registration_id !== (int) $actorRegistration->id && ! $actor?->hasPermission('events.manage')) {
            $member = EventAccommodationPairingMember::query()
                ->where('pairing_id', $pairing->id)
                ->where('registration_id', $actorRegistration->id)
                ->first();
            if ($member === null) {
                throw ValidationException::withMessages(['pairing' => ['You are not part of this sharing group.']]);
            }
        }

        $partners = EventRegistration::query()
            ->where('event_id', $pairing->event_id)
            ->whereIn('uuid', $registrationUuids)
            ->get();
        if ($partners->count() !== count(array_unique($registrationUuids))) {
            throw ValidationException::withMessages(['registration_ids' => ['Every invited person must be registered for this event.']]);
        }

        $option = $pairing->option;
        $currentCount = EventAccommodationPairingMember::query()->where('pairing_id', $pairing->id)->count();
        if ($option && ($currentCount + $partners->count()) > (int) $option->capacity) {
            throw ValidationException::withMessages(['registration_ids' => ['This room capacity is '.$option->capacity.'.']]);
        }

        foreach ($partners as $partner) {
            if ((int) $partner->id === (int) $actorRegistration->id) {
                throw ValidationException::withMessages(['registration_ids' => ['You cannot invite yourself.']]);
            }
            $this->assertNoActivePairingConflict($partner, $pairing->id);
            $exists = EventAccommodationPairingMember::query()
                ->where('pairing_id', $pairing->id)
                ->where('registration_id', $partner->id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['registration_ids' => ['That participant already has an invitation for this group.']]);
            }
            EventAccommodationPairingMember::query()->create([
                'pairing_id' => $pairing->id,
                'registration_id' => $partner->id,
                'status' => 'pending',
            ]);
            $this->serviceNotifications->notifyPairingInvitation($partner, $pairing, $actorRegistration);
        }

        if ($pairing->status === 'incomplete' || $pairing->status === 'pending_confirmation') {
            $pairing->status = 'pending_confirmation';
            $pairing->save();
        }

        $this->auditService->record(
            RegistrationAuditEventType::PairingRequested,
            $actorRegistration,
            $actor,
            null,
            ['pairing_id' => $pairing->uuid, 'invited' => $partners->count()],
        );

        return $pairing->fresh(['members.registration.person', 'option']);
    }

    public function searchParticipants(EventRegistration $requester, string $query, int $limit = 12): array
    {
        return $this->searchEventParticipants($requester->event_id, $query, $requester->id, $limit);
    }

    /**
     * @return list<array{id: string, registration_number: ?string, name: string, person_no: ?string}>
     */
    public function searchEventParticipants(int $eventId, string $query, ?int $excludeRegistrationId = null, int $limit = 12): array
    {
        $term = trim($query);
        if (strlen($term) < 2) {
            return [];
        }
        $like = '%'.$term.'%';

        $rows = EventRegistration::query()
            ->with(['person'])
            ->where('event_id', $eventId)
            ->when($excludeRegistrationId, fn ($q) => $q->where('id', '!=', $excludeRegistrationId))
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->where(function ($builder) use ($like): void {
                $builder->where('registration_number', 'like', $like)
                    ->orWhere('guest_name', 'like', $like)
                    ->orWhereHas('person', function ($person) use ($like): void {
                        $person->where('display_name', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('person_no', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
            })
            ->orderBy('registration_number')
            ->limit($limit)
            ->get();

        return $rows->map(fn (EventRegistration $row) => [
            'id' => $row->uuid,
            'registration_number' => $row->registration_number,
            'name' => $row->contactName(),
            'person_no' => $row->person?->person_no,
        ])->values()->all();
    }

    public function respondToPairing(EventAccommodationPairing $pairing, EventRegistration $registration, bool $accept, User $actor, array $dates = []): EventAccommodationPairing
    {
        $member = EventAccommodationPairingMember::query()
            ->where('pairing_id', $pairing->id)
            ->where('registration_id', $registration->id)
            ->first();
        if ($member === null) {
            throw ValidationException::withMessages(['pairing' => ['This registration is not part of the pairing.']]);
        }
        if ((int) $pairing->event_id !== (int) $registration->event_id) {
            throw ValidationException::withMessages(['pairing' => ['This invitation belongs to a different event.']]);
        }

        if (! $accept) {
            $member->status = 'declined';
            $member->declined_at = now();
            $member->save();
            $pairing->status = 'declined';
            $pairing->declined_at = now();
            $pairing->save();
            $this->auditService->record(RegistrationAuditEventType::PairingDeclined, $registration, $actor, null, ['pairing_id' => $pairing->uuid]);
            $this->serviceNotifications->notifyPairingResponse($pairing, $registration, false);

            return $pairing->fresh(['members.registration.person', 'option']);
        }

        $checkIn = $dates['arrival_date'] ?? $dates['check_in_date'] ?? $this->dateString($registration->arrival_date) ?? $this->dateString($member->check_in_date);
        $checkOut = $dates['departure_date'] ?? $dates['check_out_date'] ?? $this->dateString($registration->departure_date) ?? $this->dateString($member->check_out_date);
        $this->applyMemberStay($member, $registration, $checkIn, $checkOut);

        $member->status = 'confirmed';
        $member->confirmed_at = now();
        $member->save();
        $this->auditService->record(RegistrationAuditEventType::PairingConfirmed, $registration, $actor, null, ['pairing_id' => $pairing->uuid]);
        $this->serviceNotifications->notifyPairingResponse($pairing, $registration, true);

        $this->recalculateSharedBilling($pairing->fresh(['members', 'option']), $actor);
        $this->finalizePairingIfReady($pairing->fresh(['members', 'option']), $actor);

        return $pairing->fresh(['members.registration.person', 'option']);
    }

    public function allocate(
        EventRegistration $registration,
        EventAccommodationOption $option,
        ?User $actor,
        int $spaces = 1,
        ?int $pairingId = null,
        mixed $checkIn = null,
        mixed $checkOut = null,
    ): EventAccommodationAllocation {
        if ((int) $option->event_id !== (int) $registration->event_id) {
            throw ValidationException::withMessages(['option_id' => ['Option does not belong to this event.']]);
        }

        $pairing = $pairingId ? EventAccommodationPairing::query()->find($pairingId) : null;
        $billableNights = null;
        if ($pairing) {
            $this->recalculateSharedBilling($pairing);
            $billableNights = (int) ($pairing->billable_nights ?: $pairing->nights ?: 0) ?: null;
        }

        return DB::transaction(function () use ($registration, $option, $actor, $spaces, $pairingId, $checkIn, $checkOut, $pairing, $billableNights): EventAccommodationAllocation {
            $locked = EventAccommodationOption::query()->whereKey($option->id)->lockForUpdate()->firstOrFail();
            $inventory = $this->inventory($locked);
            $existing = EventAccommodationAllocation::query()
                ->where('registration_id', $registration->id)
                ->lockForUpdate()
                ->first();

            $already = $existing && in_array($existing->status, ['pending', 'confirmed'], true)
                ? (int) $existing->spaces
                : 0;
            if ($inventory['available_spaces'] + $already < $spaces) {
                throw ValidationException::withMessages([
                    'option_id' => ['Not enough accommodation spaces remaining.'],
                ]);
            }

            $in = $checkIn ?: $registration->arrival_date;
            $out = $checkOut ?: $registration->departure_date;
            $actualNights = $in && $out ? EventAccommodationOption::nightsBetween($in, $out) : 1;
            $occupancy = $pairing ? AccommodationOccupancyType::Shared->value : $locked->occupancyValue();
            $nights = $pairing ? max(1, (int) ($billableNights ?: $actualNights)) : $actualNights;
            $quote = $locked->quote($nights, $occupancy);

            $allocation = EventAccommodationAllocation::query()->updateOrCreate(
                ['registration_id' => $registration->id],
                [
                    'event_id' => $registration->event_id,
                    'option_id' => $locked->id,
                    'pairing_id' => $pairingId,
                    'spaces' => $spaces,
                    'status' => $existing?->status === 'confirmed' ? 'confirmed' : 'pending',
                    'check_in_date' => $in,
                    'check_out_date' => $out,
                    'details' => $quote,
                ],
            );

            $service = EventRegService::query()->updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'type' => EventRegServiceType::Accommodation,
                ],
                [
                    'status' => $allocation->status === 'confirmed' ? EventRegServiceStatus::Confirmed : EventRegServiceStatus::AwaitingPayment,
                    'option_id' => $locked->id,
                    'allocation_id' => $allocation->id,
                    'details' => array_merge(is_array($allocation->details) ? $allocation->details : [], [
                        'option_name' => $locked->name,
                        'location' => $locked->location,
                        'occupancy_type' => $occupancy,
                        'capacity' => $locked->capacity,
                        'check_in' => $allocation->check_in_date?->toDateString(),
                        'check_out' => $allocation->check_out_date?->toDateString(),
                        'actual_nights' => $actualNights,
                        'billable_nights' => $nights,
                        'billable_check_in' => $pairing?->billable_check_in_date?->toDateString() ?? $pairing?->check_in_date?->toDateString(),
                        'billable_check_out' => $pairing?->billable_check_out_date?->toDateString() ?? $pairing?->check_out_date?->toDateString(),
                        'nights' => $nights,
                        'person_night' => $quote['person_night'],
                        'person_total' => $quote['person_total'],
                        'currency' => $quote['currency'],
                    ]),
                ],
            );

            if ($quote['person_total'] > 0) {
                $this->upsertServicePayment($registration, $service, 'accommodation', $quote['person_total'], $quote['currency'], 'Accommodation: '.$locked->name);
            }

            $this->auditService->record(
                RegistrationAuditEventType::AccommodationAllocated,
                $registration,
                $actor,
                null,
                ['option' => $locked->name, 'allocation_id' => $allocation->uuid, 'amount' => $quote['person_total']],
            );

            return $allocation->fresh(['option', 'registration']);
        });
    }

    public function confirm(EventAccommodationAllocation $allocation, ?User $actor): EventAccommodationAllocation
    {
        return DB::transaction(function () use ($allocation, $actor): EventAccommodationAllocation {
            $allocation = EventAccommodationAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $allocation->loadMissing(['option', 'registration.event.venue', 'registration.person', 'registration.member', 'pairing']);
            $option = $allocation->option;
            if ($option === null) {
                throw ValidationException::withMessages(['allocation' => ['Allocation is missing an option.']]);
            }

            if ($allocation->pairing_id) {
                $pairing = EventAccommodationPairing::query()->find($allocation->pairing_id);
                if ($pairing && $pairing->status !== 'confirmed') {
                    throw ValidationException::withMessages([
                        'pairing' => ['Roommate pairing is not fully confirmed.'],
                    ]);
                }
            }

            $inventory = $this->inventory($option);
            $already = in_array($allocation->status, ['pending', 'confirmed'], true) ? (int) $allocation->spaces : 0;
            if ($inventory['available_spaces'] + $already < (int) $allocation->spaces && $allocation->status !== 'confirmed') {
                throw ValidationException::withMessages(['allocation' => ['Not enough spaces to confirm this allocation.']]);
            }

            $allocation->status = 'confirmed';
            $allocation->confirmed_at = now();
            $allocation->save();

            $service = EventRegService::query()
                ->where('registration_id', $allocation->registration_id)
                ->where('type', EventRegServiceType::Accommodation)
                ->first();
            if ($service) {
                $service->status = EventRegServiceStatus::Confirmed;
                $service->confirmed_at = now();
                $service->allocation_id = $allocation->id;
                $service->details = array_merge(is_array($service->details) ? $service->details : [], [
                    'option_name' => $option->name,
                    'location' => $option->location,
                    'occupancy_type' => $option->occupancyValue(),
                    'capacity' => $option->capacity,
                    'check_in' => $allocation->check_in_date?->toDateString(),
                    'check_out' => $allocation->check_out_date?->toDateString(),
                    'status' => 'Confirmed',
                ]);
                $service->save();
            }

            $this->auditService->record(
                RegistrationAuditEventType::AccommodationConfirmed,
                $allocation->registration,
                $actor,
                null,
                ['option' => $option->name],
            );

            $this->serviceNotifications->notifyServiceConfirmed($allocation->registration, $service, EventRegServiceType::Accommodation);

            return $allocation->fresh(['option', 'registration']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Event $event): array
    {
        $options = $event->accommodationOptions()->orderBy('sort_order')->get();
        $pairings = EventAccommodationPairing::query()
            ->with(['members.registration.person.country', 'option', 'requestedBy'])
            ->where('event_id', $event->id)
            ->latest('id')
            ->get();
        $allocations = EventAccommodationAllocation::query()->where('event_id', $event->id)->get();
        $services = EventRegService::query()
            ->where('type', EventRegServiceType::Accommodation)
            ->whereHas('registration', fn ($q) => $q->where('event_id', $event->id))
            ->get();
        $payments = EventRegistrationPayment::query()
            ->where('event_id', $event->id)
            ->where('purpose', 'accommodation')
            ->get();

        return [
            'requests' => $services->count(),
            'private_requests' => $options->filter(fn ($o) => $o->occupancyValue() === 'private')->sum(fn ($o) => $o->allocations()->count()),
            'shared_requests' => $pairings->count(),
            'pending_pairings' => $pairings->whereIn('status', ['pending_confirmation', 'incomplete'])->count(),
            'confirmed_groups' => $pairings->where('status', 'confirmed')->count(),
            'incomplete_groups' => $pairings->where('status', 'incomplete')->count(),
            'allocated' => $allocations->whereIn('status', ['pending', 'confirmed'])->count(),
            'confirmed_allocations' => $allocations->where('status', 'confirmed')->count(),
            'unpaid' => $payments->filter(fn ($p) => ($p->status instanceof \BackedEnum ? $p->status->value : (string) $p->status) === PaymentStatus::Pending->value)->count(),
            'paid' => $payments->filter(fn ($p) => in_array($p->status instanceof \BackedEnum ? $p->status->value : (string) $p->status, ['paid', 'approved'], true))->count(),
            'options' => $options->map(fn (EventAccommodationOption $option) => [
                'id' => $option->uuid,
                'name' => $option->name,
                'inventory' => $this->inventory($option),
            ])->values(),
            'pairings' => $pairings->map(fn (EventAccommodationPairing $pairing) => $this->pairingPayload($pairing))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pairingPayload(EventAccommodationPairing $pairing): array
    {
        $pairing->loadMissing(['members.registration.person.country', 'option']);
        $capacity = max(1, (int) ($pairing->option?->capacity ?? $pairing->members->count()));
        $confirmed = $pairing->members->where('status', 'confirmed')->count();

        return [
            'id' => $pairing->uuid,
            'uuid' => $pairing->uuid,
            'status' => $pairing->status,
            'option_id' => $pairing->option?->uuid,
            'option_name' => $pairing->option?->name,
            'capacity' => $capacity,
            'confirmed_count' => $confirmed,
            'member_count' => $pairing->members->count(),
            'progress' => $confirmed.'/'.$capacity,
            'check_in_date' => $pairing->check_in_date?->toDateString(),
            'check_out_date' => $pairing->check_out_date?->toDateString(),
            'nights' => $pairing->nights,
            'billable_check_in_date' => $pairing->billable_check_in_date?->toDateString() ?? $pairing->check_in_date?->toDateString(),
            'billable_check_out_date' => $pairing->billable_check_out_date?->toDateString() ?? $pairing->check_out_date?->toDateString(),
            'billable_nights' => $pairing->billable_nights ?? $pairing->nights,
            'incomplete' => $pairing->status === 'incomplete' || ($pairing->option?->require_full_occupancy && $pairing->members->count() < $capacity),
            'message' => $pairing->status === 'incomplete'
                ? 'Your accommodation sharing group is incomplete. Your selected accommodation will be finalized after the other participant(s) register and accept the sharing request.'
                : null,
            'members' => $pairing->members->map(function (EventAccommodationPairingMember $member) use ($pairing) {
                $registration = $member->registration;
                $metadata = is_array($registration?->metadata) ? $registration->metadata : [];
                $profile = is_array($metadata['profile'] ?? null) ? $metadata['profile'] : [];

                return [
                    'registration_id' => $registration?->uuid,
                    'registration_number' => $registration?->registration_number,
                    'name' => $registration?->contactName(),
                    'status' => $member->status,
                    'is_requester' => (int) $member->registration_id === (int) $pairing->requested_by_registration_id,
                    'check_in_date' => $member->check_in_date?->toDateString(),
                    'check_out_date' => $member->check_out_date?->toDateString(),
                    'actual_nights' => $member->actual_nights,
                    'membership' => $registration?->member_id ? 'member' : 'visitor',
                    'country' => $registration?->person?->country?->name,
                    'state' => $registration?->person?->region,
                    'category' => $profile['participant_category'] ?? $profile['category'] ?? null,
                ];
            })->values(),
        ];
    }

    /**
     * @return Collection<int, EventAccommodationPairing>
     */
    public function pairingsForRegistration(EventRegistration $registration)
    {
        return EventAccommodationPairing::query()
            ->whereHas('members', fn ($q) => $q->where('registration_id', $registration->id))
            ->with(['members.registration.person', 'option'])
            ->get();
    }

    private function finalizePairingIfReady(EventAccommodationPairing $pairing, ?User $actor): void
    {
        $pairing->loadMissing(['members', 'option']);
        $pending = $pairing->members->contains(fn (EventAccommodationPairingMember $member) => $member->status !== 'confirmed');
        if ($pending) {
            return;
        }

        $option = $pairing->option;
        if ($option && $option->require_full_occupancy && $pairing->members->count() < (int) $option->capacity) {
            $pairing->status = 'incomplete';
            $pairing->save();

            return;
        }

        $pairing->status = 'confirmed';
        $pairing->confirmed_at = now();
        $pairing->save();
        $this->recalculateSharedBilling($pairing, $actor);

        if ($option) {
            foreach ($pairing->members as $member) {
                $registration = EventRegistration::query()->find($member->registration_id);
                if ($registration) {
                    $this->allocate(
                        $registration,
                        $option,
                        $actor,
                        1,
                        $pairing->id,
                        $member->check_in_date ?: $pairing->check_in_date,
                        $member->check_out_date ?: $pairing->check_out_date,
                    );
                }
            }
        }
    }

    private function applyMemberStay(EventAccommodationPairingMember $member, EventRegistration $registration, mixed $checkIn, mixed $checkOut): void
    {
        $member->loadMissing('pairing.option');
        $in = $this->dateString($checkIn);
        $out = $this->dateString($checkOut);
        if ($in && $out) {
            $nights = EventAccommodationOption::nightsBetween($in, $out);
            $this->assertStayWindow($registration->event, $member->pairing?->option, $in, $out, $nights);
            $member->check_in_date = $in;
            $member->check_out_date = $out;
            $member->actual_nights = $nights;
            $member->save();
            $registration->arrival_date = $in;
            $registration->departure_date = $out;
            $registration->save();
        }
    }

    public function recalculateSharedBilling(EventAccommodationPairing $pairing, ?User $actor = null): void
    {
        $pairing->loadMissing(['members', 'option']);
        $dated = $pairing->members->filter(function (EventAccommodationPairingMember $member): bool {
            return in_array($member->status, ['pending', 'confirmed'], true)
                && $member->check_in_date
                && $member->check_out_date;
        });
        if ($dated->isEmpty()) {
            return;
        }
        $longest = $dated->sortByDesc(fn (EventAccommodationPairingMember $member) => (int) $member->actual_nights)->first();
        $billableNights = max(1, (int) ($longest?->actual_nights ?: 1));
        $pairing->billable_nights = $billableNights;
        $pairing->billable_check_in_date = $longest?->check_in_date;
        $pairing->billable_check_out_date = $longest?->check_out_date;
        $pairing->nights = $billableNights;
        $pairing->check_in_date = $longest?->check_in_date;
        $pairing->check_out_date = $longest?->check_out_date;
        $pairing->save();

        if ($pairing->status !== 'confirmed' || $pairing->option === null) {
            return;
        }

        $quote = $pairing->option->quote($billableNights, AccommodationOccupancyType::Shared->value);
        foreach ($pairing->members->where('status', 'confirmed') as $member) {
            $registration = $member->registration ?: EventRegistration::query()->find($member->registration_id);
            if ($registration === null) {
                continue;
            }
            $service = EventRegService::query()
                ->where('registration_id', $registration->id)
                ->where('type', EventRegServiceType::Accommodation)
                ->first();
            if ($service === null) {
                continue;
            }
            $details = is_array($service->details) ? $service->details : [];
            $service->details = array_merge($details, [
                'actual_nights' => $member->actual_nights,
                'check_in' => $member->check_in_date?->toDateString(),
                'check_out' => $member->check_out_date?->toDateString(),
                'billable_nights' => $billableNights,
                'billable_check_in' => $pairing->billable_check_in_date?->toDateString(),
                'billable_check_out' => $pairing->billable_check_out_date?->toDateString(),
                'person_night' => $quote['person_night'],
                'person_total' => $quote['person_total'],
                'currency' => $quote['currency'],
            ]);
            $service->save();

            $paid = (float) EventRegistrationPayment::query()
                ->where('registration_id', $registration->id)
                ->where('purpose', 'accommodation')
                ->where('status', PaymentStatus::Paid->value)
                ->sum('amount');
            $remaining = max(0, round($quote['person_total'] - $paid, 2));
            if ($remaining > 0) {
                $this->upsertServicePayment(
                    $registration,
                    $service,
                    'accommodation',
                    $remaining,
                    $quote['currency'],
                    'Accommodation: '.$pairing->option->name,
                );
            }
        }
    }

    private function enableEventFlag(Event $event, string $flag): void
    {
        if (! $event->{$flag}) {
            $event->{$flag} = true;
            $event->save();
        }
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        }

        return \Illuminate\Support\Carbon::parse((string) $value)->toDateString();
    }

    private function assertStayWindow(?Event $event, ?EventAccommodationOption $option, mixed $checkIn, mixed $checkOut, int $nights): void
    {
        if ($checkIn && $checkOut && EventAccommodationOption::nightsBetween($checkIn, $checkOut) < 1) {
            throw ValidationException::withMessages(['departure_date' => ['Departure must be after arrival.']]);
        }
        if ($option?->min_nights && $nights < (int) $option->min_nights) {
            throw ValidationException::withMessages(['departure_date' => ['This accommodation requires at least '.$option->min_nights.' night(s).']]);
        }
        if ($option?->max_nights && $nights > (int) $option->max_nights) {
            throw ValidationException::withMessages(['departure_date' => ['This accommodation allows at most '.$option->max_nights.' night(s).']]);
        }
        if ($event?->starts_at && $checkIn) {
            $start = $event->starts_at->copy()->startOfDay()->subDay();
            if (\Illuminate\Support\Carbon::parse((string) $checkIn)->lt($start)) {
                throw ValidationException::withMessages(['arrival_date' => ['Stay dates must fall within the event window.']]);
            }
        }
        if ($event?->ends_at && $checkOut) {
            $end = $event->ends_at->copy()->endOfDay()->addDay();
            if (\Illuminate\Support\Carbon::parse((string) $checkOut)->gt($end)) {
                throw ValidationException::withMessages(['departure_date' => ['Stay dates must fall within the event window.']]);
            }
        }
    }

    private function assertNoActivePairingConflict(EventRegistration $registration, ?int $ignorePairingId): void
    {
        $exists = EventAccommodationPairingMember::query()
            ->where('registration_id', $registration->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereHas('pairing', function ($query) use ($ignorePairingId): void {
                $query->whereIn('status', ['pending_confirmation', 'incomplete', 'confirmed']);
                if ($ignorePairingId) {
                    $query->where('id', '!=', $ignorePairingId);
                }
            })
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'registration_ids' => ['A participant already belongs to another active accommodation group for this event.'],
            ]);
        }
    }

    private function resolveOption(int $eventId, mixed $optionId, bool $required): ?EventAccommodationOption
    {
        if ($optionId === null || $optionId === '') {
            if ($required) {
                throw ValidationException::withMessages(['option_id' => ['Accommodation option is required.']]);
            }

            return null;
        }
        $option = EventAccommodationOption::query()
            ->where('event_id', $eventId)
            ->where(function ($q) use ($optionId) {
                $q->where('uuid', $optionId);
                if (is_numeric($optionId)) {
                    $q->orWhere('id', (int) $optionId);
                }
            })
            ->first();
        if ($option === null) {
            throw ValidationException::withMessages(['option_id' => ['Accommodation option was not found for this event.']]);
        }

        return $option;
    }

    private function upsertServicePayment(
        EventRegistration $registration,
        EventRegService $service,
        string $purpose,
        float $amount,
        string $currency,
        string $notes,
    ): EventRegistrationPayment {
        $existing = EventRegistrationPayment::query()
            ->where('registration_id', $registration->id)
            ->where('service_id', $service->id)
            ->where('purpose', $purpose)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->first();

        $payload = [
            'registration_id' => $registration->id,
            'event_id' => $registration->event_id,
            'service_id' => $service->id,
            'purpose' => $purpose,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Pending,
            'payment_method' => PaymentMethodType::Offline,
            'notes' => $notes,
        ];

        if ($existing) {
            $existing->fill($payload);
            $existing->save();

            return $existing;
        }

        return EventRegistrationPayment::query()->create($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function optionAttributes(Event $event, array $data, bool $update = false): array
    {
        $keys = [
            'name', 'description', 'location', 'address', 'distance_from_venue', 'room_type', 'amenities',
            'image_media_ids', 'occupancy_type', 'capacity', 'unit_count', 'price', 'price_basis',
            'price_per_night', 'private_price', 'shared_price', 'currency', 'min_nights', 'max_nights',
            'check_in_info', 'check_out_info', 'notes', 'require_full_occupancy', 'is_active', 'status', 'sort_order',
        ];
        $out = $update ? [] : [
            'event_id' => $event->id,
            'occupancy_type' => $data['occupancy_type'] ?? 'shared',
            'capacity' => max(1, (int) ($data['capacity'] ?? 1)),
            'unit_count' => max(0, (int) ($data['unit_count'] ?? 1)),
            'price' => (float) ($data['price'] ?? $data['price_per_night'] ?? 0),
            'price_basis' => $data['price_basis'] ?? 'per_night',
            'currency' => $data['currency'] ?? ($event->currency ?? 'USD'),
            'status' => $data['status'] ?? 'available',
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'require_full_occupancy' => array_key_exists('require_full_occupancy', $data) ? (bool) $data['require_full_occupancy'] : true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'name' => $data['name'] ?? 'Accommodation',
        ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        if (isset($out['capacity'])) {
            $out['capacity'] = max(1, (int) $out['capacity']);
        }
        if (isset($out['unit_count'])) {
            $out['unit_count'] = max(0, (int) $out['unit_count']);
        }

        return $out;
    }
}
