<?php

namespace Tests\Unit\Support;

use App\Support\Countries;
use Tests\TestCase;

class CountriesTest extends TestCase
{
    public function test_normalizes_legacy_country_names_to_iso_codes(): void
    {
        $this->assertSame('KE', Countries::normalize('Kenya'));
        $this->assertSame('UG', Countries::normalize('Uganda'));
        $this->assertSame('US', Countries::normalize('United States'));
    }

    public function test_validates_iso_country_codes(): void
    {
        $this->assertTrue(Countries::isValidCode('KE'));
        $this->assertFalse(Countries::isValidCode('Kenya'));
        $this->assertFalse(Countries::isValidCode(''));
    }

    public function test_displays_country_name_from_code(): void
    {
        $this->assertSame('Kenya', Countries::name('KE'));
        $this->assertSame('Kenya', Countries::display('KE'));
    }
}
