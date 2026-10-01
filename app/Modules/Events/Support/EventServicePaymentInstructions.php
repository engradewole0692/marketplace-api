<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Models\EventRegistration;

final class EventServicePaymentInstructions
{
    public const ACCOUNT_NAME = 'Luvanex International Limited';

    public const BANK = 'UBA (United Bank for Africa)';

    public const ACCOUNT_NUMBER = '1022860128';

    public const NOTICE = 'Thank you for identifying your preferred accommodation & transportation. Please note that you are responsible for payment of the accommodation & transportation selected. Thank you.';

    /**
     * @var list<array{name: string, phone: string, display: string, whatsapp_url: string}>
     */
    public const CONTACTS = [
        [
            'name' => 'Joy',
            'phone' => '+2347076383446',
            'display' => '+234 707 638 3446',
            'whatsapp_url' => 'https://wa.me/2347076383446',
        ],
        [
            'name' => 'Kenny',
            'phone' => '+2349030967597',
            'display' => '+234 903 096 7597',
            'whatsapp_url' => 'https://wa.me/2349030967597',
        ],
    ];

    public static function registrationNeedsInstructions(EventRegistration $registration): bool
    {
        if ($registration->accommodation_required || $registration->airport_pickup_required) {
            return true;
        }

        if (! $registration->relationLoaded('services')) {
            $registration->load('services');
        }

        return $registration->services->contains(function ($service): bool {
            $type = $service->type instanceof EventRegServiceType
                ? $service->type
                : EventRegServiceType::tryFrom((string) $service->type);

            return in_array($type, [EventRegServiceType::Accommodation, EventRegServiceType::Transport], true);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forRegistration(EventRegistration $registration): ?array
    {
        if (! self::registrationNeedsInstructions($registration)) {
            return null;
        }

        $coversAccommodation = (bool) $registration->accommodation_required
            || $registration->services->contains(function ($service): bool {
                $type = $service->type instanceof EventRegServiceType
                    ? $service->type
                    : EventRegServiceType::tryFrom((string) $service->type);

                return $type === EventRegServiceType::Accommodation;
            });
        $coversTransport = (bool) $registration->airport_pickup_required
            || $registration->services->contains(function ($service): bool {
                $type = $service->type instanceof EventRegServiceType
                    ? $service->type
                    : EventRegServiceType::tryFrom((string) $service->type);

                return $type === EventRegServiceType::Transport;
            });

        return self::payload($coversAccommodation, $coversTransport);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(bool $accommodation, bool $transport): array
    {
        $scope = match (true) {
            $accommodation && $transport => 'accommodation_and_transport',
            $accommodation => 'accommodation',
            default => 'transport',
        };

        return [
            'notice' => self::NOTICE,
            'account_name' => self::ACCOUNT_NAME,
            'bank' => self::BANK,
            'account_number' => self::ACCOUNT_NUMBER,
            'scope' => $scope,
            'covers_accommodation' => $accommodation,
            'covers_transport' => $transport,
            'whatsapp_contacts' => self::CONTACTS,
            'proof_instructions' => 'After making payment, send proof of payment or a screenshot via WhatsApp to Joy or Kenny for verification.',
        ];
    }
}
