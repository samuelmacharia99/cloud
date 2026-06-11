<?php

namespace Tests\Unit\Services;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceCurrencyService;
use App\Services\UserCurrencyService;
use App\Support\CountryCurrency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MulticurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    public function test_country_maps_to_currency(): void
    {
        $this->assertSame('KES', CountryCurrency::forCountry('KE'));
        $this->assertSame('NGN', CountryCurrency::forCountry('NG'));
        $this->assertSame('USD', CountryCurrency::forCountry('US'));
    }

    public function test_user_currency_defaults_from_country(): void
    {
        Currency::where('code', 'NGN')->update(['exchange_rate' => 0.011]);

        $user = User::factory()->create([
            'country' => 'NG',
            'preferred_currency' => null,
        ]);

        app(UserCurrencyService::class)->syncFromCountry($user, true);

        $this->assertSame('NGN', $user->fresh()->preferred_currency);
        $this->assertSame('NGN', app(UserCurrencyService::class)->codeFor($user));
    }

    public function test_invoice_snapshot_converts_kes_amounts_to_user_currency(): void
    {
        Currency::where('code', 'USD')->update(['exchange_rate' => 0.0077]);

        $user = User::factory()->create([
            'country' => 'US',
            'preferred_currency' => 'USD',
        ]);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-1',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->assertSame('USD', $invoice->currency);
        $this->assertEquals(1000, (float) $invoice->total_base_kes);
        $this->assertEquals(7.7, (float) $invoice->total);
    }

    public function test_paypal_settlement_converts_remaining_balance_to_usd(): void
    {
        Currency::where('code', 'USD')->update(['exchange_rate' => 0.01]);

        $user = User::factory()->create(['preferred_currency' => 'USD']);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-2',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $settlement = app(InvoiceCurrencyService::class)->settlementAmount($invoice, 'USD');

        $this->assertSame('USD', $settlement['currency']);
        $this->assertEquals(10.0, $settlement['amount']);
    }
}
