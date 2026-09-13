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
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventTravelRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TravelAssistanceService implements ServiceContract
{
    public const STATUSES = [
        'requested',
        'under_review',
        'quote_provided',
        'awaiting_payment',
        'booking_in_progress',
        'booked',
        'cancelled',
        'completed',
    ];

    public function __construct(
        private readonly RegistrationAuditService $auditService,
        private readonly EventServiceNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(EventRegistration $registration, array $data, ?User $actor = null): EventTravelRequest
    {
        return DB::transaction(function () use ($registration, $data, $actor): EventTravelRequest {
            $registration->loadMissing('event');
            $service = EventRegService::query()->updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'type' => EventRegServiceType::Travel,
                ],
                [
                    'status' => EventRegServiceStatus::Requested,
                    'details' => array_filter([
                        'origin' => $data['origin'] ?? null,
                        'destination' => $data['destination'] ?? null,
                        'trip_type' => $data['trip_type'] ?? 'return',
                    ], fn ($v) => $v !== null && $v !== ''),
                ],
            );

            $request = EventTravelRequest::query()->updateOrCreate(
                ['registration_id' => $registration->id],
                [
                    'event_id' => $registration->event_id,
                    'service_id' => $service->id,
                    'trip_type' => $data['trip_type'] ?? 'return',
                    'origin' => $data['origin'] ?? null,
                    'destination' => $data['destination'] ?? null,
                    'departure_date' => $data['departure_date'] ?? null,
                    'preferred_departure_time' => $data['preferred_departure_time'] ?? null,
                    'return_date' => $data['return_date'] ?? null,
                    'preferred_return_time' => $data['preferred_return_time'] ?? null,
                    'airline_preference' => $data['airline_preference'] ?? null,
                    'travel_class' => $data['travel_class'] ?? null,
                    'passengers' => max(1, (int) ($data['passengers'] ?? 1)),
                    'passenger_names' => $data['passenger_names'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'currency' => $data['currency'] ?? $registration->event?->currency ?? 'USD',
                    'status' => 'requested',
                ],
            );

            $this->auditService->record(
                RegistrationAuditEventType::TravelStatusChanged,
                $registration,
                $actor,
                null,
                ['status' => 'requested', 'travel_id' => $request->uuid],
            );
            $this->notifications->notifyServiceUpdated($registration, $service, 'Travel assistance requested');
            $event = $registration->event;
            if ($event && ! $event->travel_assistance_enabled) {
                $event->travel_assistance_enabled = true;
                $event->save();
            }

            return $request->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EventTravelRequest $request, array $data, ?User $actor = null): EventTravelRequest
    {
        $old = $request->only(['status', 'quote_amount']);
        if (isset($data['status']) && ! in_array($data['status'], self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Invalid travel assistance status.']]);
        }

        $request->fill($data);
        if (($data['status'] ?? null) === 'quote_provided' || isset($data['quote_amount'])) {
            $request->quoted_at = now();
            if ($request->status === 'requested' || $request->status === 'under_review') {
                $request->status = 'quote_provided';
            }
        }
        if (($data['status'] ?? null) === 'booked') {
            $request->booked_at = now();
        }
        if (($data['status'] ?? null) === 'cancelled') {
            $request->cancelled_at = now();
        }
        $request->save();

        $service = $request->service ?: EventRegService::query()->find($request->service_id);
        if ($service) {
            $service->status = $this->mapServiceStatus((string) $request->status);
            $service->details = array_merge(is_array($service->details) ? $service->details : [], [
                'origin' => $request->origin,
                'destination' => $request->destination,
                'travel_dates' => trim(($request->departure_date?->toDateString() ?? '').' – '.($request->return_date?->toDateString() ?? '')),
                'quote_amount' => $request->quote_amount,
                'travel_class' => $request->travel_class,
            ]);
            if ($service->status->isConfirmed()) {
                $service->confirmed_at = now();
            }
            $service->save();
        }

        if ($request->quote_amount && (float) $request->quote_amount > 0 && $service) {
            $existing = EventRegistrationPayment::query()
                ->where('registration_id', $request->registration_id)
                ->where('purpose', 'travel')
                ->whereIn('status', [PaymentStatus::Pending->value])
                ->first();
            $payload = [
                'registration_id' => $request->registration_id,
                'event_id' => $request->event_id,
                'service_id' => $service->id,
                'purpose' => 'travel',
                'amount' => $request->quote_amount,
                'currency' => $request->currency ?? 'USD',
                'status' => PaymentStatus::Pending,
                'payment_method' => PaymentMethodType::Offline,
                'notes' => 'Travel assistance quote',
            ];
            if ($existing) {
                $existing->fill($payload);
                $existing->save();
                $request->payment_id = $existing->id;
            } else {
                $payment = EventRegistrationPayment::query()->create($payload);
                $request->payment_id = $payment->id;
            }
            $request->save();
            if (in_array($request->status, ['quote_provided', 'awaiting_payment'], true)) {
                $this->notifications->notifyTravelQuote($request);
            }
        }

        if ($service && $service->status->isConfirmed()) {
            $this->notifications->notifyServiceConfirmed($request->registration, $service, EventRegServiceType::Travel);
        } elseif ($service && $request->status === 'cancelled') {
            $this->notifications->notifyServiceCancelled($request->registration, $service);
        } elseif ($service) {
            $this->notifications->notifyServiceUpdated($request->registration, $service, 'Travel status: '.$request->status);
        }

        $this->auditService->record(
            RegistrationAuditEventType::TravelStatusChanged,
            $request->registration,
            $actor,
            $old,
            $request->only(['status', 'quote_amount']),
        );

        return $request->fresh();
    }

    private function mapServiceStatus(string $status): EventRegServiceStatus
    {
        return match ($status) {
            'under_review' => EventRegServiceStatus::UnderReview,
            'quote_provided' => EventRegServiceStatus::QuoteProvided,
            'awaiting_payment' => EventRegServiceStatus::AwaitingPayment,
            'booking_in_progress' => EventRegServiceStatus::BookingInProgress,
            'booked', 'completed' => EventRegServiceStatus::Booked,
            'cancelled' => EventRegServiceStatus::Cancelled,
            default => EventRegServiceStatus::Requested,
        };
    }
}
