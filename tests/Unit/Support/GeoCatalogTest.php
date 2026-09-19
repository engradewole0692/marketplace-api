<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Modules\Events\Support\PhoneNumberNormalizer;
use App\Support\GeoCatalog;
use Tests\TestCase;

final class GeoCatalogTest extends TestCase
{
    public function test_catalog_includes_international_countries_and_calling_codes(): void
    {
        $iso = array_column(GeoCatalog::countries(), 'iso2');
        $this->assertContains('NG', $iso);
        $this->assertContains('GH', $iso);
        $this->assertContains('US', $iso);
        $this->assertContains('FR', $iso);
        $this->assertContains('JP', $iso);
        $this->assertGreaterThan(100, count($iso));
        $this->assertSame('234', GeoCatalog::dialForIso('NG'));
        $this->assertSame('233', GeoCatalog::dialForIso('GH'));
    }

    public function test_subdivisions_load_for_country_and_empty_countries_are_graceful(): void
    {
        $ghana = GeoCatalog::subdivisions('GH');
        $this->assertNotEmpty($ghana);
        $this->assertTrue(GeoCatalog::isValidSubdivision('GH', 'Greater Accra'));
        $this->assertTrue(GeoCatalog::isValidSubdivision('GH', 'GH-AA'));
        $this->assertFalse(GeoCatalog::isValidSubdivision('GH', 'Not A Region'));
        $this->assertTrue(GeoCatalog::isValidSubdivision('Nigeria', 'Lagos'));
        $this->assertSame('NG', GeoCatalog::resolveIso('Nigeria'));
        $this->assertTrue(GeoCatalog::isAcceptableSubdivision('United Kingdom', 'Greater London'));
        $this->assertTrue(GeoCatalog::isAcceptableSubdivision('Mexico', 'CDMX'));
        $this->assertFalse(GeoCatalog::isAcceptableSubdivision('GH', 'XX-FAKE'));
    }

    public function test_phone_normalizer_uses_iso_and_preserves_existing_e164(): void
    {
        $national = PhoneNumberNormalizer::normalize('0241234567', 'GH');
        $this->assertSame('+233241234567', $national['phone']);
        $this->assertSame('GH', $national['country_code']);

        $fromLabel = PhoneNumberNormalizer::normalize('08012345678', 'NG +234 Nigeria');
        $this->assertSame('+2348012345678', $fromLabel['phone']);
        $this->assertSame('NG', $fromLabel['country_code']);

        $existing = PhoneNumberNormalizer::normalize('+14155552671', 'US');
        $this->assertSame('+14155552671', $existing['phone']);
        $this->assertSame('US', $existing['country_code']);
    }
}
