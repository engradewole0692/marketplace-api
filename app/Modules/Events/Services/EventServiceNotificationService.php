<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Support\CommunicationEventKeys;
use App\Modules\Communications\Services\CommunicationDispatchService;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventRegistration;
use App\Services\Membership\MemberNotificationQueueService;

final class EventServiceNotificationService implements ServiceContract
{
    public function __construct(
        private readonly CommunicationDispatchService $communicationDispatch,
        private readonly MemberNotificationQueueService $memberNotificationQueueService,
        private readonly RegistrationAuditService $auditService,
    ) {}

    public function notifyServiceConfirmed(
        EventRegistration $registration,
        ?EventRegService $service,
        EventRegServiceType $type,
    ): void {
        $eventKey = match ($type) {
            EventRegServiceType::Accommodation => CommunicationEventKeys::EVENT_ACCOMMODATION_CONFIRMED,
            EventRegServiceType::Transport => CommunicationEventKeys::EVENT_TRANSPORT_CONFIRMED,
            EventRegServiceType::Travel => CommunicationEventKeys::EVENT_TRAVEL_CONFIRMED,
        };

        $this->dispatch($registration, $service, $eventKey, $type->value.' confirmed');
        $this->auditService->record(
            RegistrationAuditEventType::ServiceConfirmed,
            $registration,
            null,
            null,
            ['service' => $type->value, 'event_key' => $eventKey],
        );
    }

    public function notifyServiceUpdated(EventRegistration $registration, EventRegService $service, string $title): void
    {
        $this->dispatch($registration, $service, CommunicationEventKeys::EVENT_SERVICE_UPDATED, $title);
        $this->auditService->record(
            RegistrationAuditEventType::ServiceUpdated,
            $registration,
            null,
            null,
            ['service' => $service->type instanceof \BackedEnum ? $service->type->value : (string) $service->type],
        );
    }

    public function notifyPaymentVerified(EventRegistration $registration, ?EventRegService $service = null): void
    {
        $this->dispatch($registration, $service, CommunicationEventKeys::EVENT_PAYMENT_VERIFIED, 'Payment verified');
        $this->auditService->record(RegistrationAuditEventType::PaymentVerified, $registration, null, null, []);
    }

    public function notifyServiceCancelled(EventRegistration $registration, EventRegService $service): void
    {
        $this->dispatch($registration, $service, CommunicationEventKeys::EVENT_SERVICE_CANCELLED, 'Service cancelled');
        $this->auditService->record(RegistrationAuditEventType::ServiceCancelled, $registration, null, null, []);
    }

    private function dispatch(
        EventRegistration $registration,
        ?EventRegService $service,
        string $eventKey,
        string $title,
    ): void {
        $registration->loadMissing(['event.venue', 'person.user', 'member.user']);
        $details = is_array($service?->details) ? $service->details : [];
        $detailLines = collect($details)
            ->filter(fn ($value) => $value !== null && $value !== '' && ! is_array($value))
            ->map(fn ($value, $key) => ucwords(str_replace('_', ' ', (string) $key)).': '.$value)
            ->implode('<br>');

        $variables = [
            'applicant_name' => $registration->contactName(),
            'email' => $registration->contactEmail() ?? '',
            'event_name' => $registration->event?->title ?? 'Event',
            'event_date' => $registration->event?->starts_at?->format('M j, Y') ?? '',
            'event_location' => $registration->event?->venue?->name ?? '',
            'registration_number' => $registration->registration_number ?? '',
            'service_type' => $service?->type instanceof \BackedEnum ? $service->type->value : (string) ($service?->type ?? ''),
            'service_status' => $service?->status instanceof \BackedEnum ? $service->status->label() : (string) ($service?->status ?? 'Confirmed'),
            'service_details_html' => $detailLines !== '' ? $detailLines : 'Details are available in your workspace.',
            'accommodation_type' => (string) ($details['option_name'] ?? $details['occupancy_type'] ?? ''),
            'location' => (string) ($details['location'] ?? $registration->event?->venue?->name ?? ''),
            'check_in' => (string) ($details['check_in'] ?? $details['arrival_date'] ?? ''),
            'check_out' => (string) ($details['check_out'] ?? $details['departure_date'] ?? ''),
            'pickup' => (string) ($details['pickup'] ?? $details['origin'] ?? ''),
            'destination' => (string) ($details['destination'] ?? $details['dropoff'] ?? ''),
            'vehicle' => (string) ($details['vehicle'] ?? ''),
            'driver' => (string) ($details['driver'] ?? $details['driver_name'] ?? ''),
            'contact' => (string) ($details['contact'] ?? $details['driver_phone'] ?? ''),
            'travel_dates' => (string) ($details['travel_dates'] ?? $details['departure_date'] ?? ''),
            'in_app_title' => $title,
            'in_app_body' => strip_tags(str_replace('<br>', "\n", $detailLines)),
            'subject' => $title.' — '.($registration->event?->title ?? 'Event'),
        ];

        $email = $registration->contactEmail();
        $user = $registration->person?->user ?? $registration->member?->user;
        $idempotency = $eventKey.':'.$registration->uuid.':'.($service?->uuid ?? 'none').':'.($service?->updated_at?->timestamp ?? time());

        try {
            $this->communicationDispatch->dispatchEvent(
                eventKey: $eventKey,
                section: 'events',
                variables: $variables,
                recipientUser: $user,
                recipientEmail: $email,
                recipientName: $registration->contactName(),
                related: $registration,
                includeRouting: false,
                idempotencyKey: $idempotency,
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        if ($registration->member) {
            try {
                $this->memberNotificationQueueService->queue(
                    $registration->member,
                    'in_app',
                    $eventKey,
                    array_merge($variables, [
                        'title' => $title,
                        'body' => $variables['in_app_body'],
                        'registration_id' => $registration->id,
                    ]),
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
