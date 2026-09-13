<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum EventStaffRole: string
{
    case EventAdministrator = 'event_administrator';
    case AccommodationOfficer = 'accommodation_officer';
    case LogisticsOfficer = 'logistics_officer';
    case TravelOfficer = 'travel_officer';
    case FinanceOfficer = 'finance_officer';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::EventAdministrator => 'Event Administrator',
            self::AccommodationOfficer => 'Accommodation Officer',
            self::LogisticsOfficer => 'Logistics Officer',
            self::TravelOfficer => 'Travel Officer',
            self::FinanceOfficer => 'Finance Officer',
            self::Staff => 'Staff',
        };
    }

    /**
     * @return list<self>
     */
    public static function assignable(): array
    {
        return self::cases();
    }

    /**
     * @return list<string>
     */
    public static function acceptedValues(): array
    {
        $values = array_map(fn (self $role): string => $role->value, self::cases());

        return array_values(array_unique([
            ...$values,
            'coordinator',
            'administrator',
            'event-admin',
            'door',
            'usher',
        ]));
    }

    public static function normalize(?string $value): self
    {
        $raw = strtolower(trim((string) $value));
        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            'event_administrator', 'coordinator', 'administrator', 'event_admin' => self::EventAdministrator,
            'accommodation_officer', 'accommodation' => self::AccommodationOfficer,
            'logistics_officer', 'logistics' => self::LogisticsOfficer,
            'travel_officer', 'travel' => self::TravelOfficer,
            'finance_officer', 'finance' => self::FinanceOfficer,
            default => self::Staff,
        };
    }

    /**
     * @return list<EventStaffDomain>
     */
    public function domains(): array
    {
        return match ($this) {
            self::EventAdministrator => EventStaffDomain::cases(),
            self::AccommodationOfficer => [EventStaffDomain::Accommodation],
            self::LogisticsOfficer => [EventStaffDomain::Logistics],
            self::TravelOfficer => [EventStaffDomain::Travel],
            self::FinanceOfficer => [EventStaffDomain::Finance],
            self::Staff => [EventStaffDomain::Operations],
        };
    }

    public function canAccess(EventStaffDomain $domain): bool
    {
        return in_array($domain, $this->domains(), true);
    }
}
