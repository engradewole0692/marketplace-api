<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventTransportTrip;

final class EventServicePaymentInstructions
{
    /** Public labels for configured transport route keys (mirrors the registration form). */
    public const ROUTE_LABELS = [
        'airport_to_accommodation' => 'Airport pickup to accommodation',
        'hotel_to_venue' => 'Hotel to Convergence venue',
    ];

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

        return self::payload($coversAccommodation, $coversTransport, self::selections($registration));
    }

    /**
     * Human-readable summary of the services persisted on the registration.
     *
     * @return list<array{type: string, title: string, lines: list<string>}>
     */
    public static function selections(EventRegistration $registration): array
    {
        $registration->loadMissing('services');

        $selections = [];
        $accommodation = self::serviceOfType($registration, EventRegServiceType::Accommodation);
        if ($accommodation !== null || $registration->accommodation_required) {
            $selections[] = [
                'type' => 'accommodation',
                'title' => 'Accommodation',
                'lines' => self::accommodationLines($registration, $accommodation),
            ];
        }

        $transport = self::serviceOfType($registration, EventRegServiceType::Transport);
        if ($transport !== null || $registration->airport_pickup_required) {
            $registration->loadMissing('transportTrips.option');
            $selections[] = [
                'type' => 'transport',
                'title' => 'Transportation',
                'lines' => self::transportLines($registration, $transport),
            ];
        }

        return $selections;
    }

    private static function serviceOfType(EventRegistration $registration, EventRegServiceType $type): ?EventRegService
    {
        return $registration->services->first(function ($service) use ($type): bool {
            $serviceType = $service->type instanceof EventRegServiceType
                ? $service->type
                : EventRegServiceType::tryFrom((string) $service->type);

            return $serviceType === $type;
        });
    }

    /**
     * @return list<string>
     */
    private static function accommodationLines(EventRegistration $registration, ?EventRegService $service): array
    {
        $details = is_array($service?->details) ? $service->details : [];
        $name = $details['option_name'] ?? $details['requested_option'] ?? null;
        $location = $details['location'] ?? null;
        $occupancy = $details['occupancy_type'] ?? $details['occupancy'] ?? null;
        $checkIn = $details['check_in'] ?? $details['arrival_date'] ?? $registration->arrival_date?->toDateString();
        $checkOut = $details['check_out'] ?? $details['departure_date'] ?? $registration->departure_date?->toDateString();
        $nights = isset($details['nights']) ? (int) $details['nights'] : null;
        $total = isset($details['person_total']) ? (float) $details['person_total'] : null;

        $lines = [];
        if ($name) {
            $lines[] = 'Option: '.$name.($location ? ' ('.$location.')' : '');
        }
        if ($occupancy) {
            $lines[] = 'Occupancy: '.ucfirst((string) $occupancy);
        }
        if ($checkIn) {
            $lines[] = 'Check-in: '.$checkIn;
        }
        if ($checkOut) {
            $lines[] = 'Check-out: '.$checkOut;
        }
        if ($nights) {
            $lines[] = 'Nights: '.$nights;
        }
        if ($total !== null && $total > 0) {
            $lines[] = 'Estimated amount: '.self::money($total, $details['currency'] ?? $registration->event?->currency);
        }

        return $lines === [] ? ['Accommodation requested'] : $lines;
    }

    /**
     * @return list<string>
     */
    private static function transportLines(EventRegistration $registration, ?EventRegService $service): array
    {
        $lines = $registration->transportTrips
            ->map(function (EventTransportTrip $trip): string {
                $option = $trip->option;
                $label = ($option?->public_route_key ? (self::ROUTE_LABELS[$option->public_route_key] ?? null) : null)
                    ?? $option?->name
                    ?? $trip->route
                    ?? 'Transportation';
                $parts = [$label];
                if ($trip->trip_date) {
                    $parts[] = $trip->trip_date instanceof \DateTimeInterface ? $trip->trip_date->format('Y-m-d') : (string) $trip->trip_date;
                }
                $passengers = max(1, (int) $trip->passengers);
                $parts[] = $passengers.' '.($passengers === 1 ? 'passenger' : 'passengers');
                if ((float) $trip->amount > 0) {
                    $parts[] = 'estimated '.self::money((float) $trip->amount, $trip->currency);
                }

                return 'Route: '.implode(' — ', $parts);
            })
            ->values()
            ->all();

        if ($lines === []) {
            $route = is_array($service?->details) ? ($service->details['route'] ?? null) : null;
            $lines[] = $route ? 'Route: '.$route : 'Transportation requested';
        }

        return $lines;
    }

    private static function money(float $amount, ?string $currency): string
    {
        $formatted = number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);

        return trim(($currency ?: '').' '.$formatted);
    }

    /**
     * @param  list<array{type: string, title: string, lines: list<string>}>  $selections
     * @return array<string, mixed>
     */
    public static function payload(bool $accommodation, bool $transport, array $selections = []): array
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
            'proof_instructions' => self::PROOF_INSTRUCTIONS,
            'manual_payment_notice' => self::manualPaymentNotice($accommodation, $transport),
            'selections' => $selections,
        ];
    }

    public const PROOF_INSTRUCTIONS = 'After making payment, send proof of payment or a screenshot via WhatsApp to Joy or Kenny for verification.';

    public static function manualPaymentNotice(bool $accommodation, bool $transport): string
    {
        $services = match (true) {
            $accommodation && $transport => 'accommodation and transportation',
            $accommodation => 'accommodation',
            default => 'transportation',
        };

        return "Payment for the {$services} you selected is made manually by bank transfer to the account below. There is no online payment for {$services} on this website, and payment is not confirmed automatically. Our team verifies your payment after receiving your proof of payment on WhatsApp.";
    }

    /**
     * Plain-text block for email templates; the template renderer escapes HTML.
     */
    public static function plainText(?array $payload): string
    {
        if ($payload === null) {
            return '';
        }

        $contacts = collect($payload['whatsapp_contacts'] ?? [])
            ->map(fn (array $row): string => '- '.$row['name'].': '.$row['display'])
            ->implode("\n");

        $selectionLines = [];
        foreach ($payload['selections'] ?? [] as $selection) {
            $selectionLines[] = $selection['title'].':';
            foreach ($selection['lines'] as $line) {
                $selectionLines[] = '- '.$line;
            }
            $selectionLines[] = '';
        }
        if ($selectionLines !== []) {
            array_unshift($selectionLines, 'Your service selections:');
        }

        return implode("\n", [
            ...$selectionLines,
            (string) $payload['notice'],
            '',
            (string) ($payload['manual_payment_notice'] ?? ''),
            '',
            'Account Name: '.$payload['account_name'],
            'Bank: '.$payload['bank'],
            'Account Number: '.$payload['account_number'],
            '',
            (string) ($payload['proof_instructions'] ?? self::PROOF_INSTRUCTIONS),
            $contacts,
        ]);
    }
}
