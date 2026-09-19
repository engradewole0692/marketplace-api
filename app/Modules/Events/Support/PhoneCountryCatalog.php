<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Support\GeoCatalog;

final class PhoneCountryCatalog
{
    /**
     * @return list<array{iso2: string, name: string, dial: string, flag: string}>
     */
    public static function all(): array
    {
        return GeoCatalog::phoneCountries();
    }

    /**
     * Human labels for admin field-config fallbacks. Public forms use ISO2 values.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_map(
            fn (array $row): string => $row['flag'].' '.$row['name'].' +'.$row['dial'],
            self::all(),
        );
    }

    public static function dialForIso(?string $iso2): ?string
    {
        $iso = GeoCatalog::resolveIso($iso2) ?? strtoupper(trim((string) $iso2));

        return GeoCatalog::dialForIso($iso);
    }
}
