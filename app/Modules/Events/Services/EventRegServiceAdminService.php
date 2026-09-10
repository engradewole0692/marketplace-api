<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Validation\ValidationException;

final class EventRegServiceAdminService implements ServiceContract
{
    public function __construct(
        private readonly EventServiceNotificationService $notifications,
        private readonly RegistrationAuditService $auditService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(EventRegistration $registration, EventRegServiceType $type, array $data, User $actor): EventRegService
    {
        $status = EventRegServiceStatus::tryFrom((string) ($data['status'] ?? EventRegServiceStatus::Requested->value))
            ?? EventRegServiceStatus::Requested;
        $existing = EventRegService::query()
            ->where('registration_id', $registration->id)
            ->where('type', $type)
            ->first();
        $details = array_merge(is_array($existing?->details) ? $existing->details : [], is_array($data['details'] ?? null) ? $data['details'] : []);

        $service = EventRegService::query()->updateOrCreate(
            [
                'registration_id' => $registration->id,
                'type' => $type,
            ],
            [
                'status' => $status,
                'details' => $details,
                'confirmed_at' => $status->isConfirmed() ? ($existing?->confirmed_at ?? now()) : null,
            ],
        );

        if ($type === EventRegServiceType::Transport) {
            $registration->airport_pickup_required = true;
            $registration->save();
        }

        $this->auditService->record(
            $type === EventRegServiceType::Travel
                ? RegistrationAuditEventType::TravelStatusChanged
                : RegistrationAuditEventType::ServiceUpdated,
            $registration,
            $actor,
            null,
            ['type' => $type->value, 'status' => $status->value],
        );

        if ($status->isConfirmed()) {
            $this->notifications->notifyServiceConfirmed($registration, $service, $type);
        } elseif ($status === EventRegServiceStatus::Cancelled) {
            $this->notifications->notifyServiceCancelled($registration, $service);
        } else {
            $this->notifications->notifyServiceUpdated($registration, $service, ucfirst($type->value).' updated');
        }

        return $service->fresh();
    }

    public function findForRegistration(EventRegistration $registration, string $serviceUuid): EventRegService
    {
        $service = EventRegService::query()
            ->where('registration_id', $registration->id)
            ->where('uuid', $serviceUuid)
            ->first();
        if ($service === null) {
            throw ValidationException::withMessages(['service' => ['Service was not found for this registration.']]);
        }

        return $service;
    }
}
