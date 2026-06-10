<?php

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_enabled_accepts_checkbox_and_legacy_values(): void
    {
        Setting::setValue('tax_enabled', '1');
        $this->assertTrue(TaxService::isEnabled());

        Setting::setValue('tax_enabled', 'true');
        $this->assertTrue(TaxService::isEnabled());

        Setting::setValue('tax_enabled', '0');
        $this->assertFalse(TaxService::isEnabled());
    }

    public function test_exclusive_tax_is_added_on_top(): void
    {
        Setting::setValue('tax_enabled', '1');
        Setting::setValue('tax_inclusive', '0');
        Setting::setValue('tax_rate', '16');

        $breakdown = TaxService::calculate(1000);

        $this->assertSame(1000.0, $breakdown['subtotal']);
        $this->assertSame(160.0, $breakdown['tax']);
        $this->assertSame(1160.0, $breakdown['total']);
        $this->assertFalse($breakdown['inclusive']);
    }

    public function test_inclusive_tax_is_extracted_from_displayed_price(): void
    {
        Setting::setValue('tax_enabled', '1');
        Setting::setValue('tax_inclusive', '1');
        Setting::setValue('tax_rate', '16');

        $breakdown = TaxService::calculate(1160);

        $this->assertSame(1000.0, $breakdown['subtotal']);
        $this->assertSame(160.0, $breakdown['tax']);
        $this->assertSame(1160.0, $breakdown['total']);
        $this->assertTrue($breakdown['inclusive']);
    }

    public function test_tax_disabled_returns_zero_tax(): void
    {
        Setting::setValue('tax_enabled', '0');
        Setting::setValue('tax_rate', '16');

        $breakdown = TaxService::calculate(500);

        $this->assertSame(500.0, $breakdown['subtotal']);
        $this->assertSame(0.0, $breakdown['tax']);
        $this->assertSame(500.0, $breakdown['total']);
        $this->assertFalse($breakdown['enabled']);
    }
}
