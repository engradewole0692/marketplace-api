<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Models\Person;
use App\Support\GeoCatalog;

final class PhoneNumberNormalizer
{
    /**
     * @return array{phone: ?string, digits: ?string, country_code: ?string}
     */
    public static function normalize(?string $phone, ?string $countryCode = null): array
    {
        $iso = GeoCatalog::resolveIso($countryCode);
        $raw = trim((string) $phone);
        if ($raw === '') {
            return ['phone' => null, 'digits' => null, 'country_code' => $iso];
        }

        $alreadyInternational = str_starts_with($raw, '+');
        $digits = Person::digits($raw);
        if ($digits === null || $digits === '') {
            return ['phone' => $raw, 'digits' => null, 'country_code' => $iso];
        }

        $dial = $iso !== null ? GeoCatalog::dialForIso($iso) : null;

        if ($alreadyInternational) {
            if ($iso === null) {
                $iso = self::guessIsoFromDigits($digits);
            }

            return [
                'phone' => '+'.$digits,
                'digits' => $digits,
                'country_code' => $iso,
            ];
        }

        if ($dial !== null && ! str_starts_with($digits, $dial)) {
            $national = ltrim($digits, '0');
            if ($national !== '') {
                $digits = $dial.$national;
            }
        } elseif ($iso === null) {
            $iso = self::guessIsoFromDigits($digits);
        }

        return [
            'phone' => '+'.$digits,
            'digits' => $digits,
            'country_code' => $iso,
        ];
    }

    /**
     * Normalize common payload phone keys without inventing numbers.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload, array $keys = ['phone'], ?string $countryKey = 'phone_country_code'): array
    {
        $country = $countryKey !== null ? (isset($payload[$countryKey]) ? (string) $payload[$countryKey] : null) : null;
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload) || ! is_string($payload[$key])) {
                continue;
            }
            $normalized = self::normalize($payload[$key], $country);
            if ($normalized['phone'] !== null) {
                $payload[$key] = $normalized['phone'];
            }
            if ($countryKey !== null && $normalized['country_code'] !== null) {
                $payload[$countryKey] = $normalized['country_code'];
            }
        }

        return $payload;
    }

    private static function guessIsoFromDigits(string $digits): ?string
    {
        $best = null;
        $bestLen = 0;
        foreach (GeoCatalog::phoneCountries() as $row) {
            $dial = $row['dial'];
            if ($dial !== '' && str_starts_with($digits, $dial) && strlen($dial) > $bestLen) {
                $best = $row['iso2'];
                $bestLen = strlen($dial);
            }
        }

        return $best;
    }
}
