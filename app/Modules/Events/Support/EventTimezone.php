<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use DateTimeZone;
use Exception;

final class EventTimezone
{
    /**
     * Common city / abbreviation values stored instead of IANA identifiers.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'LAGOS' => 'Africa/Lagos',
        'AFRICA/LAGOS' => 'Africa/Lagos',
        'WAT' => 'Africa/Lagos',
        'WEST AFRICA TIME' => 'Africa/Lagos',
        'ABUJA' => 'Africa/Lagos',
        'NIGERIA' => 'Africa/Lagos',
        'GMT' => 'UTC',
        'UTC' => 'UTC',
        'LONDON' => 'Europe/London',
        'BST' => 'Europe/London',
    ];

    public static function resolve(?string $timezone): string
    {
        $fallback = (string) config('app.timezone', 'UTC');
        $candidate = trim((string) $timezone);
        if ($candidate === '') {
            return self::safe($fallback);
        }

        $mapped = self::ALIASES[strtoupper($candidate)] ?? $candidate;
        if (self::isValid($mapped)) {
            return $mapped;
        }

        return self::safe($fallback);
    }

    public static function isValid(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    private static function safe(string $timezone): string
    {
        return self::isValid($timezone) ? $timezone : 'UTC';
    }
}
