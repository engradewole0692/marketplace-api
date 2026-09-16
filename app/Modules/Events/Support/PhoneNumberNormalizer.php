<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Models\Person;

final class PhoneNumberNormalizer
{
    /**
     * @return array{phone: ?string, digits: ?string, country_code: ?string}
     */
    public static function normalize(?string $phone, ?string $countryCode = null): array
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        $countryCode = $countryCode === '' ? null : $countryCode;
        $raw = trim((string) $phone);
        if ($raw === '') {
            return ['phone' => null, 'digits' => null, 'country_code' => $countryCode];
        }

        $digits = Person::digits($raw);
        $dial = PhoneCountryCatalog::dialForIso($countryCode);

        if ($digits !== null && $dial !== null && ! str_starts_with($digits, $dial)) {
            $national = ltrim($digits, '0');
            $digits = $dial.$national;
        }

        return [
            'phone' => $digits !== null ? '+'.$digits : $raw,
            'digits' => $digits,
            'country_code' => $countryCode,
        ];
    }
}
