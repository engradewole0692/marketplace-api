<?php

declare(strict_types=1);

namespace App\Modules\Communications\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Central E.164 normalization for SMS and WhatsApp destinations.
 *
 * Numbers in international form (+ or 00 prefix) are parsed as-is. National-format numbers are parsed
 * against the record's own country when known, otherwise the configured default region, and are rejected
 * when libphonenumber does not consider them valid for that region. No dial code is blindly prepended.
 */
final class PhoneNumberService
{
    private PhoneNumberUtil $util;

    public function __construct()
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    /**
     * @return array{e164: ?string, valid: bool, reason: ?string, region: ?string, mobile: ?bool}
     */
    public function normalize(?string $raw, ?string $regionHint = null): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['e164' => null, 'valid' => false, 'reason' => 'missing_phone', 'region' => null, 'mobile' => null];
        }

        $candidate = preg_replace('/^whatsapp:/i', '', $raw) ?? $raw;
        $candidate = preg_replace('/[^\d+]/', '', $candidate) ?? '';
        if (str_starts_with($candidate, '00')) {
            $candidate = '+'.substr($candidate, 2);
        }
        if ($candidate === '' || strlen(ltrim($candidate, '+')) < 6) {
            return ['e164' => null, 'valid' => false, 'reason' => 'invalid_phone', 'region' => null, 'mobile' => null];
        }

        $region = $this->region($regionHint);

        try {
            $number = $this->util->parse($candidate, str_starts_with($candidate, '+') ? null : $region);
        } catch (NumberParseException) {
            return ['e164' => null, 'valid' => false, 'reason' => 'invalid_phone', 'region' => null, 'mobile' => null];
        }

        if (! $this->util->isValidNumber($number)) {
            return ['e164' => null, 'valid' => false, 'reason' => 'invalid_phone', 'region' => null, 'mobile' => null];
        }

        $type = $this->util->getNumberType($number);
        $mobile = in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true) ? true
            : ($type === PhoneNumberType::FIXED_LINE ? false : null);

        return [
            'e164' => $this->util->format($number, PhoneNumberFormat::E164),
            'valid' => true,
            'reason' => null,
            'region' => $this->util->getRegionCodeForNumber($number),
            'mobile' => $mobile,
        ];
    }

    public function e164(?string $raw, ?string $regionHint = null): ?string
    {
        return $this->normalize($raw, $regionHint)['e164'];
    }

    public static function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 6) {
            return '***';
        }

        return '+'.substr($digits, 0, 3).'*****'.substr($digits, -3);
    }

    private function region(?string $hint): ?string
    {
        $hint = strtoupper(trim((string) $hint));
        if (strlen($hint) === 2 && in_array($hint, $this->util->getSupportedRegions(), true)) {
            return $hint;
        }
        $default = strtoupper((string) config('communications.default_phone_region', ''));

        return strlen($default) === 2 ? $default : null;
    }
}
