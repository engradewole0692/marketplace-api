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
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventTransportOption;
use App\Modules\Events\Models\EventTransportTrip;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransportService implements ServiceContract
{
    public function __construct(
        private readonly RegistrationAuditService $auditService,
        private readonly EventServiceNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOption(Event $event, array $data, ?User $actor = null): EventTransportOption
    {
        $option = EventTransportOption::query()->create([
            'event_id' => $event->id,
            'name' => $data['name'],
            'route' => $data['route'] ?? null,
            'origin' => $data['origin'] ?? null,
            'destination' => $data['destination'] ?? null,
            'description' => $data['description'] ?? null,
            'price' => (float) ($data['price'] ?? 0),
            'price_basis' => $data['price_basis'] ?? 'per_trip',
            'currency' => $data['currency'] ?? ($event->currency ?? 'USD'),
            'service_date' => $data['service_date'] ?? null,
            'time_windows' => $data['time_windows'] ?? [],
            'vehicle_type' => $data['vehicle_type'] ?? null,
            'vehicle_name' => $data['vehicle_name'] ?? null,
            'make_model' => $data['make_model'] ?? null,
            'passenger_capacity' => $data['passenger_capacity'] ?? null,
            'luggage_capacity' => $data['luggage_capacity'] ?? null,
            'image_media_ids' => $data['image_media_ids'] ?? [],
            'vehicle_details' => $data['vehicle_details'] ?? null,
            'pickup_instructions' => $data['pickup_instructions'] ?? null,
            'dropoff_instructions' => $data['dropoff_instructions'] ?? null,
            'status' => $data['status'] ?? 'available',
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        if (! $event->transport_enabled) {
            $event->transport_enabled = true;
            $event->save();
        }
        app(EventAuditService::class)->record(
            \App\Modules\Events\Enums\EventAuditEventType::TransportOptionChanged,
            $event,
            $actor,
            EventTransportOption::class,
            $option->id,
            null,
            ['name' => $option->name, 'price' => $option->price, 'currency' => $option->currency],
            ['action' => 'created'],
        );

        return $option;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOption(EventTransportOption $option, array $data, ?User $actor = null): EventTransportOption
    {
        $option->loadMissing('event');
        $old = ['name' => $option->name, 'price' => $option->price, 'currency' => $option->currency, 'is_active' => $option->is_active];
        $option->fill($data);
        $option->save();
        if ($option->is_active !== false && $option->event && ! $option->event->transport_enabled) {
            $option->event->transport_enabled = true;
            $option->event->save();
        }
        app(EventAuditService::class)->record(
            \App\Modules\Events\Enums\EventAuditEventType::TransportOptionChanged,
            $option->event,
            $actor,
            EventTransportOption::class,
            $option->id,
            $old,
            ['name' => $option->name, 'price' => $option->price, 'currency' => $option->currency, 'is_active' => $option->is_active],
            ['action' => 'updated'],
        );

        return $option->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function requestTrip(EventRegistration $registration, array $data, ?User $actor = null): EventTransportTrip
    {
        $option = null;
        if (! empty($data['option_id'])) {
            $option = EventTransportOption::query()
                ->where('event_id', $registration->event_id)
                ->where('uuid', $data['option_id'])
                ->first();
            if ($option === null) {
                throw ValidationException::withMessages(['option_id' => ['Transport option was not found for this event.']]);
            }
        }

        $passengers = max(1, (int) ($data['passengers'] ?? 1));
        $amount = $option
            ? $option->calculateAmount($passengers, 1)
            : (float) ($data['amount'] ?? 0);

        $existing = EventTransportTrip::query()
            ->where('registration_id', $registration->id)
            ->where('option_id', $option?->id)
            ->where('route', $data['route'] ?? $option?->route)
            ->where('trip_date', $data['trip_date'] ?? $data['date'] ?? null)
            ->where('trip_time', $data['trip_time'] ?? $data['time'] ?? null)
            ->first();
        if ($existing) {
            return $existing->load('option');
        }

        return DB::transaction(function () use ($registration, $data, $option, $passengers, $amount, $actor): EventTransportTrip {
            $service = EventRegService::query()->updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'type' => EventRegServiceType::Transport,
                ],
                [
                    'status' => EventRegServiceStatus::Requested,
                    'details' => array_filter([
                        'route' => $data['route'] ?? $option?->route,
                        'pickup' => $data['pickup_location'] ?? null,
                    ], fn ($v) => $v !== null && $v !== ''),
                ],
            );

            $registration->airport_pickup_required = true;
            $registration->save();

            $trip = EventTransportTrip::query()->create([
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'option_id' => $option?->id,
                'service_id' => $service->id,
                'route' => $data['route'] ?? $option?->route,
                'pickup_location' => $data['pickup_location'] ?? null,
                'dropoff_location' => $data['dropoff_location'] ?? null,
                'trip_date' => $data['trip_date'] ?? $data['date'] ?? null,
                'trip_time' => $data['trip_time'] ?? $data['time'] ?? null,
                'passengers' => $passengers,
                'luggage' => $data['luggage'] ?? null,
                'special_requirements' => $data['special_requirements'] ?? null,
                'flight_info' => $data['flight_info'] ?? null,
                'amount' => $amount,
                'currency' => $option?->currency ?? $registration->event?->currency ?? 'USD',
                'status' => 'requested',
                'details' => [
                    'vehicle_type' => $option?->vehicle_type,
                    'vehicle_name' => $option?->vehicle_name,
                    'price_basis' => $option?->price_basis,
                ],
            ]);

            if ($amount > 0) {
                $payment = EventRegistrationPayment::query()->create([
                    'registration_id' => $registration->id,
                    'event_id' => $registration->event_id,
                    'service_id' => $service->id,
                    'purpose' => 'transport',
                    'amount' => $amount,
                    'currency' => $trip->currency,
                    'status' => PaymentStatus::Pending,
                    'payment_method' => PaymentMethodType::Offline,
                    'notes' => 'Transport: '.($trip->route ?: 'trip').' '.$trip->uuid,
                ]);
                $trip->payment_id = $payment->id;
                $trip->save();
            }

            $this->auditService->record(
                RegistrationAuditEventType::ServiceUpdated,
                $registration,
                $actor,
                null,
                ['service' => 'transport', 'trip_id' => $trip->uuid, 'amount' => $amount],
            );
            $this->notifications->notifyServiceUpdated($registration, $service, 'Transport requested');

            return $trip->fresh(['option']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTrip(EventTransportTrip $trip, array $data, ?User $actor = null): EventTransportTrip
    {
        $old = $trip->only(['status', 'assigned_vehicle', 'assigned_driver_user_id']);
        if (isset($data['status'])) {
            $allowed = ['requested', 'confirmed', 'assigned', 'in_progress', 'completed', 'cancelled'];
            if (! in_array($data['status'], $allowed, true)) {
                throw ValidationException::withMessages(['status' => ['Invalid transport status.']]);
            }
        }

        $trip->fill($data);
        if (! empty($data['assigned_vehicle']) || ! empty($data['assigned_driver_user_id'])) {
            $trip->assigned_at = now();
            $trip->assigned_by_user_id = $actor?->id;
            if (($trip->status === 'requested' || $trip->status === 'confirmed') && empty($data['status'])) {
                $trip->status = 'assigned';
            }
        }
        $trip->save();

        $service = $trip->service ?: EventRegService::query()->find($trip->service_id);
        if ($service && in_array($trip->status, ['confirmed', 'assigned', 'completed'], true)) {
            $service->status = $trip->status === 'completed' ? EventRegServiceStatus::Confirmed : EventRegServiceStatus::UnderReview;
            $service->details = array_merge(is_array($service->details) ? $service->details : [], [
                'vehicle' => $trip->assigned_vehicle,
                'pickup' => $trip->pickup_location,
                'destination' => $trip->dropoff_location,
                'route' => $trip->route,
            ]);
            $service->save();
            if ($trip->status === 'confirmed' || $trip->status === 'completed') {
                $this->notifications->notifyServiceConfirmed($trip->registration, $service, EventRegServiceType::Transport);
            } elseif ($trip->status === 'assigned') {
                $this->notifications->notifyTransportAssigned($trip);
            } else {
                $this->notifications->notifyServiceUpdated($trip->registration, $service, 'Transport updated');
            }
        }

        $this->auditService->record(
            $trip->status === 'confirmed' ? RegistrationAuditEventType::TransportConfirmed : RegistrationAuditEventType::ServiceUpdated,
            $trip->registration,
            $actor,
            $old,
            $trip->only(['status', 'assigned_vehicle', 'assigned_driver_user_id']),
        );

        return $trip->fresh(['option']);
    }
}
