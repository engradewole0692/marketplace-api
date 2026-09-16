<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

final class PhoneCountryCatalog
{
    /**
     * @return list<array{iso2: string, name: string, dial: string}>
     */
    public static function all(): array
    {
        return [
            ['iso2' => 'NG', 'name' => 'Nigeria', 'dial' => '234'],
            ['iso2' => 'GH', 'name' => 'Ghana', 'dial' => '233'],
            ['iso2' => 'KE', 'name' => 'Kenya', 'dial' => '254'],
            ['iso2' => 'ZA', 'name' => 'South Africa', 'dial' => '27'],
            ['iso2' => 'US', 'name' => 'United States', 'dial' => '1'],
            ['iso2' => 'GB', 'name' => 'United Kingdom', 'dial' => '44'],
            ['iso2' => 'CA', 'name' => 'Canada', 'dial' => '1'],
            ['iso2' => 'IN', 'name' => 'India', 'dial' => '91'],
            ['iso2' => 'AE', 'name' => 'United Arab Emirates', 'dial' => '971'],
            ['iso2' => 'SA', 'name' => 'Saudi Arabia', 'dial' => '966'],
            ['iso2' => 'FR', 'name' => 'France', 'dial' => '33'],
            ['iso2' => 'DE', 'name' => 'Germany', 'dial' => '49'],
            ['iso2' => 'IT', 'name' => 'Italy', 'dial' => '39'],
            ['iso2' => 'NL', 'name' => 'Netherlands', 'dial' => '31'],
            ['iso2' => 'AU', 'name' => 'Australia', 'dial' => '61'],
            ['iso2' => 'CM', 'name' => 'Cameroon', 'dial' => '237'],
            ['iso2' => 'UG', 'name' => 'Uganda', 'dial' => '256'],
            ['iso2' => 'TZ', 'name' => 'Tanzania', 'dial' => '255'],
            ['iso2' => 'RW', 'name' => 'Rwanda', 'dial' => '250'],
            ['iso2' => 'ZM', 'name' => 'Zambia', 'dial' => '260'],
            ['iso2' => 'ZW', 'name' => 'Zimbabwe', 'dial' => '263'],
            ['iso2' => 'CI', 'name' => "Côte d'Ivoire", 'dial' => '225'],
            ['iso2' => 'SN', 'name' => 'Senegal', 'dial' => '221'],
            ['iso2' => 'ET', 'name' => 'Ethiopia', 'dial' => '251'],
            ['iso2' => 'EG', 'name' => 'Egypt', 'dial' => '20'],
            ['iso2' => 'BR', 'name' => 'Brazil', 'dial' => '55'],
            ['iso2' => 'PH', 'name' => 'Philippines', 'dial' => '63'],
            ['iso2' => 'CN', 'name' => 'China', 'dial' => '86'],
            ['iso2' => 'JP', 'name' => 'Japan', 'dial' => '81'],
            ['iso2' => 'KR', 'name' => 'South Korea', 'dial' => '82'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_map(
            fn (array $row): string => $row['iso2'].' +'.$row['dial'].' '.$row['name'],
            self::all(),
        );
    }

    public static function dialForIso(?string $iso2): ?string
    {
        $iso2 = strtoupper(trim((string) $iso2));
        foreach (self::all() as $row) {
            if ($row['iso2'] === $iso2) {
                return $row['dial'];
            }
        }

        return null;
    }
}
