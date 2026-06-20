<?php

namespace Tests\Unit\Services;

use App\Models\Currency;
use App\Services\CurrencyCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_provisions_currencies_when_table_is_empty(): void
    {
        $this->assertSame(0, Currency::count());

        $currency = app(CurrencyCatalogService::class)->ensure('KES');

        $this->assertSame('KES', $currency->code);
        $this->assertGreaterThan(0, Currency::count());
    }

    public function test_ensure_provisions_missing_east_african_currency_code(): void
    {
        Currency::create([
            'code' => 'KES',
            'name' => 'Kenyan Shilling',
            'symbol' => 'KSh',
            'exchange_rate' => 1.0,
            'is_active' => true,
            'order' => 1,
        ]);

        $currency = app(CurrencyCatalogService::class)->ensure('TZS');

        $this->assertSame('TZS', $currency->code);
        $this->assertSame('Tanzanian Shilling', $currency->name);
    }
}
