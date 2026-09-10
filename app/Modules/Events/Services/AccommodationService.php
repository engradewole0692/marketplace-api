<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
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
        return EventAccommodationOption::query()->create([
            'event_id' => $event->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'amenities' => $data['amenities'] ?? [],
            'image_media_ids' => $data['image_media_ids'] ?? [],
            'occupancy_type' => $data['occupancy_type'] ?? 'shared',
            'capacity' => max(1, (int) ($data['capacity'] ?? 1)),
            'unit_count' => max(0, (int) ($data['unit_count'] ?? 1)),
            'price' => (float) ($data['price'] ?? 0),
            'price_basis' => $data['price_basis'] ?? 'per_person',
            'currency' => $data['currency'] ?? ($event->currency ?? 'USD'),
            'status' => $data['status'] ?? 'available',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOption(EventAccommodationOption $option, array $data): EventAccommodationOption
    {
        $option->fill($data);
        $option->save();

        return $option->fresh();
    }

    /**
     * @return array{total_spaces: int, allocated_spaces: int, available_spaces: int}
     */
    public function inventory(EventAccommodationOption $option): array
    {
        $allocated = (int) $option->allocations()
            ->whereIn('status', ['pending', 'confirmed'])
            ->sum('spaces');
        $total = $option->totalSpaces();

        return [
            'total_spaces' => $total,
            'allocated_spaces' => $allocated,
            'available_spaces' => max(0, $total - $allocated),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function requestForRegistration(EventRegistration $registration, array $data, ?User $actor = null): EventRegService
    {
        $registration->loadMissing('event');
        $option = null;
        if (! empty($data['option_id'])) {
            $option = EventAccommodationOption::query()
                ->where('event_id', $registration->event_id)
                ->where(function ($q) use ($data) {
                    $q->where('uuid', $data['option_id']);
                    if (is_numeric($data['option_id'])) {
                        $q->orWhere('id', (int) $data['option_id']);
                    }
                })
                ->first();
            if ($option === null) {
                throw ValidationException::withMessages(['option_id' => ['Accommodation option was not found for this event.']]);
            }
        }

        $service = EventRegService::query()->updateOrCreate(
            [
                'registration_id' => $registration->id,
                'type' => EventRegServiceType::Accommodation,
            ],
            [
                'status' => EventRegServiceStatus::Requested,
                'option_id' => $option?->id,
                'details' => array_filter([
                    'occupancy_type' => $data['occupancy_type'] ?? $option?->occupancy_type?->value,
                    'requested_option' => $option?->name,
                    'arrival_date' => $data['arrival_date'] ?? $registration->arrival_date?->toDateString(),
                    'departure_date' => $data['departure_date'] ?? $registration->departure_date?->toDateString(),
                    'notes' => $data['notes'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ],
        );

        $registration->accommodation_required = true;
        $registration->save();

        $this->auditService->record(
            RegistrationAuditEventType::ServiceUpdated,
            $registration,
            $actor,
            null,
            ['service' => 'accommodation', 'status' => 'requested'],
        );

        return $service->fresh();
    }

    /**
     * @param  list<string>  $registrationUuids
     */
    public function requestPairing(EventRegistration $requester, array $registrationUuids, ?string $optionId, User $actor): EventAccommodationPairing
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
                'registration_ids' => ['Every roommate must be registered for the same event.'],
            ]);
        }

        $option = null;
        if ($optionId) {
            $option = EventAccommodationOption::query()
                ->where('event_id', $requester->event_id)
                ->where('uuid', $optionId)
                ->first();
            if ($option === null) {
                throw ValidationException::withMessages(['option_id' => ['Accommodation option was not found.']]);
            }
            if ($partners->count() > (int) $option->capacity) {
                throw ValidationException::withMessages([
                    'registration_ids' => ['This room capacity is '.$option->capacity.'.'],
                ]);
            }
        }

        return DB::transaction(function () use ($requester, $partners, $option, $actor): EventAccommodationPairing {
            $pairing = EventAccommodationPairing::query()->create([
                'event_id' => $requester->event_id,
                'option_id' => $option?->id,
                'status' => 'pending_confirmation',
                'requested_by_registration_id' => $requester->id,
            ]);

            foreach ($partners as $partner) {
                EventAccommodationPairingMember::query()->create([
                    'pairing_id' => $pairing->id,
                    'registration_id' => $partner->id,
                    'status' => $partner->id === $requester->id ? 'confirmed' : 'pending',
                    'confirmed_at' => $partner->id === $requester->id ? now() : null,
                ]);
            }

            $this->auditService->record(
                RegistrationAuditEventType::PairingRequested,
                $requester,
                $actor,
                null,
                ['pairing_id' => $pairing->uuid, 'count' => $partners->count()],
            );

            return $pairing->fresh(['members.registration.person', 'option']);
        });
    }

    public function respondToPairing(EventAccommodationPairing $pairing, EventRegistration $registration, bool $accept, User $actor): EventAccommodationPairing
    {
        $member = EventAccommodationPairingMember::query()
            ->where('pairing_id', $pairing->id)
            ->where('registration_id', $registration->id)
            ->first();
        if ($member === null) {
            throw ValidationException::withMessages(['pairing' => ['This registration is not part of the pairing.']]);
        }

        if (! $accept) {
            $member->status = 'declined';
            $member->declined_at = now();
            $member->save();
            $pairing->status = 'declined';
            $pairing->declined_at = now();
            $pairing->save();
            $this->auditService->record(RegistrationAuditEventType::PairingDeclined, $registration, $actor, null, ['pairing_id' => $pairing->uuid]);

            return $pairing->fresh(['members.registration.person']);
        }

        $member->status = 'confirmed';
        $member->confirmed_at = now();
        $member->save();

        $pending = EventAccommodationPairingMember::query()
            ->where('pairing_id', $pairing->id)
            ->where('status', '!=', 'confirmed')
            ->exists();

        if (! $pending) {
            $pairing->status = 'confirmed';
            $pairing->confirmed_at = now();
            $pairing->save();
            $this->auditService->record(RegistrationAuditEventType::PairingConfirmed, $registration, $actor, null, ['pairing_id' => $pairing->uuid]);
        }

        return $pairing->fresh(['members.registration.person']);
    }

    public function allocate(EventRegistration $registration, EventAccommodationOption $option, User $actor, int $spaces = 1, ?int $pairingId = null): EventAccommodationAllocation
    {
        if ((int) $option->event_id !== (int) $registration->event_id) {
            throw ValidationException::withMessages(['option_id' => ['Option does not belong to this event.']]);
        }

        return DB::transaction(function () use ($registration, $option, $actor, $spaces, $pairingId): EventAccommodationAllocation {
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

            $allocation = EventAccommodationAllocation::query()->updateOrCreate(
                ['registration_id' => $registration->id],
                [
                    'event_id' => $registration->event_id,
                    'option_id' => $locked->id,
                    'pairing_id' => $pairingId,
                    'spaces' => $spaces,
                    'status' => 'pending',
                    'check_in_date' => $registration->arrival_date,
                    'check_out_date' => $registration->departure_date,
                ],
            );

            $service = EventRegService::query()->updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'type' => EventRegServiceType::Accommodation,
                ],
                [
                    'status' => EventRegServiceStatus::UnderReview,
                    'option_id' => $locked->id,
                    'allocation_id' => $allocation->id,
                    'details' => array_merge(is_array($allocation->details) ? $allocation->details : [], [
                        'option_name' => $locked->name,
                        'location' => $locked->location,
                        'occupancy_type' => $locked->occupancy_type instanceof \BackedEnum
                            ? $locked->occupancy_type->value
                            : $locked->occupancy_type,
                        'capacity' => $locked->capacity,
                    ]),
                ],
            );

            if ((float) $locked->price > 0) {
                EventRegistrationPayment::query()->create([
                    'registration_id' => $registration->id,
                    'event_id' => $registration->event_id,
                    'service_id' => $service->id,
                    'purpose' => 'accommodation',
                    'amount' => $locked->price,
                    'currency' => $locked->currency ?? 'USD',
                    'status' => PaymentStatus::Pending,
                    'payment_method' => PaymentMethodType::Offline,
                    'notes' => 'Accommodation: '.$locked->name,
                ]);
            }

            $this->auditService->record(
                RegistrationAuditEventType::AccommodationAllocated,
                $registration,
                $actor,
                null,
                ['option' => $locked->name, 'allocation_id' => $allocation->uuid],
            );

            return $allocation->fresh(['option', 'registration']);
        });
    }

    public function confirm(EventAccommodationAllocation $allocation, User $actor): EventAccommodationAllocation
    {
        return DB::transaction(function () use ($allocation, $actor): EventAccommodationAllocation {
            $allocation = EventAccommodationAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $allocation->loadMissing(['option', 'registration.event.venue', 'registration.person', 'registration.member']);
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
                    'occupancy_type' => $option->occupancy_type instanceof \BackedEnum ? $option->occupancy_type->value : $option->occupancy_type,
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
}
