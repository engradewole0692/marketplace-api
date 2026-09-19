<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Authoritative ISO 3166-1 / 3166-2 catalog for person location and phone calling codes.
 */
final class GeoCatalog
{
    /** @var array<string, array{iso2: string, name: string, dial: string, flag: string}>|null */
    private static ?array $countries = null;

    /** @var array<string, list<array{code: string, name: string}>>|null */
    private static ?array $subdivisions = null;

    /**
     * @return list<array{iso2: string, name: string, dial: string, flag: string}>
     */
    public static function countries(): array
    {
        self::boot();

        return array_values(self::$countries ?? []);
    }

    /**
     * @return list<array{iso2: string, name: string, dial: string, flag: string}>
     */
    public static function phoneCountries(): array
    {
        return array_values(array_filter(
            self::countries(),
            static fn (array $row): bool => $row['dial'] !== '',
        ));
    }

    /**
     * @return array{iso2: string, name: string, dial: string, flag: string}|null
     */
    public static function country(?string $iso2): ?array
    {
        $iso2 = self::normalizeIso($iso2);
        if ($iso2 === null) {
            return null;
        }
        self::boot();

        return self::$countries[$iso2] ?? null;
    }

    public static function dialForIso(?string $iso2): ?string
    {
        $country = self::country($iso2);

        return $country !== null && $country['dial'] !== '' ? $country['dial'] : null;
    }

    /**
     * Accept ISO2, +dial, dial digits, or "NG +234 Nigeria" labels.
     */
    public static function resolveIso(?string $input): ?string
    {
        $raw = trim((string) $input);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}$/i', $raw) === 1) {
            $iso = strtoupper($raw);
            if (self::country($iso) !== null) {
                return $iso;
            }
        }

        if (preg_match('/^([A-Z]{2})\s/i', $raw, $match) === 1) {
            $iso = strtoupper($match[1]);
            if (self::country($iso) !== null) {
                return $iso;
            }
        }

        $lower = strtolower($raw);
        foreach (self::countries() as $row) {
            if (strtolower($row['name']) === $lower) {
                return $row['iso2'];
            }
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits !== '') {
            foreach (self::phoneCountries() as $row) {
                if ($row['dial'] === $digits) {
                    return $row['iso2'];
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function subdivisions(?string $iso2): array
    {
        $iso2 = self::normalizeIso($iso2);
        if ($iso2 === null) {
            return [];
        }
        self::boot();

        return self::$subdivisions[$iso2] ?? [];
    }

    public static function hasSubdivisions(?string $iso2): bool
    {
        $iso2 = self::resolveIso($iso2) ?? self::normalizeIso($iso2);

        return self::subdivisions($iso2) !== [];
    }

    public static function isValidSubdivision(?string $iso2, ?string $value): bool
    {
        $iso2 = self::resolveIso($iso2) ?? self::normalizeIso($iso2);
        $value = trim((string) $value);
        if ($iso2 === null || $value === '') {
            return false;
        }

        $rows = self::subdivisions($iso2);
        if ($rows === []) {
            return true;
        }

        $needle = strtolower($value);
        foreach ($rows as $row) {
            if (strtolower($row['code']) === $needle || strtolower($row['name']) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Catalog names/codes are preferred. Historical free-text values remain valid
     * unless they look like an ISO 3166-2 code that is not in the catalog.
     */
    public static function isAcceptableSubdivision(?string $iso2, ?string $value): bool
    {
        if (self::isValidSubdivision($iso2, $value)) {
            return true;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        return ! preg_match('/^[A-Z]{2}-[A-Z0-9]+$/i', $value);
    }

    public static function subdivisionName(?string $iso2, ?string $value): ?string
    {
        $iso2 = self::normalizeIso($iso2);
        $value = trim((string) $value);
        if ($iso2 === null || $value === '') {
            return null;
        }

        foreach (self::subdivisions($iso2) as $row) {
            if (strcasecmp($row['code'], $value) === 0 || strcasecmp($row['name'], $value) === 0) {
                return $row['name'];
            }
        }

        return $value;
    }

    public static function flagEmoji(string $iso2): string
    {
        $iso2 = strtoupper($iso2);
        if (strlen($iso2) !== 2 || ! ctype_alpha($iso2)) {
            return '🌍';
        }

        $chars = str_split($iso2);
        $out = '';
        foreach ($chars as $char) {
            $code = 127397 + ord($char);
            $out .= mb_chr($code, 'UTF-8') ?: '';
        }

        return $out !== '' ? $out : '🌍';
    }

    private static function normalizeIso(?string $iso2): ?string
    {
        $iso2 = strtoupper(trim((string) $iso2));

        return preg_match('/^[A-Z]{2}$/', $iso2) === 1 ? $iso2 : null;
    }

    private static function boot(): void
    {
        if (self::$countries !== null) {
            return;
        }

        $isoPath = database_path('data/iso-3166-2.json');
        $dialPath = database_path('data/calling-codes.json');
        $iso = is_file($isoPath) ? json_decode((string) file_get_contents($isoPath), true) : [];
        $dials = is_file($dialPath) ? json_decode((string) file_get_contents($dialPath), true) : [];
        if (! is_array($iso)) {
            $iso = [];
        }
        if (! is_array($dials)) {
            $dials = [];
        }

        self::$countries = [];
        self::$subdivisions = [];

        foreach ($iso as $code => $payload) {
            $iso2 = strtoupper((string) $code);
            if (strlen($iso2) !== 2) {
                continue;
            }
            $name = is_array($payload) ? (string) ($payload['name'] ?? $iso2) : $iso2;
            $dial = (string) ($dials[$iso2] ?? '');
            self::$countries[$iso2] = [
                'iso2' => $iso2,
                'name' => $name,
                'dial' => $dial,
                'flag' => self::flagEmoji($iso2),
            ];

            $divisions = is_array($payload) && is_array($payload['divisions'] ?? null) ? $payload['divisions'] : [];
            $rows = [];
            foreach ($divisions as $divCode => $divName) {
                $label = trim((string) $divName);
                if ($label === '') {
                    continue;
                }
                $rows[] = [
                    'code' => (string) $divCode,
                    'name' => $label,
                ];
            }
            usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
            self::$subdivisions[$iso2] = $rows;
        }

        foreach ($dials as $iso2 => $dial) {
            $iso2 = strtoupper((string) $iso2);
            if (isset(self::$countries[$iso2]) || strlen($iso2) !== 2) {
                continue;
            }
            self::$countries[$iso2] = [
                'iso2' => $iso2,
                'name' => $iso2,
                'dial' => (string) $dial,
                'flag' => self::flagEmoji($iso2),
            ];
            self::$subdivisions[$iso2] = self::$subdivisions[$iso2] ?? [];
        }

        uasort(self::$countries, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    }
}
