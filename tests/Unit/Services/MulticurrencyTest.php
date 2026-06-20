<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
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

        Currency::query()->update(['rate_updated_at' => now()]);
    }

    private function setUsableRate(string $code, float $rate): void
    {
        Currency::where('code', $code)->update([
            'exchange_rate' => $rate,
            'rate_updated_at' => now(),
        ]);
    }

    public function test_country_maps_to_currency(): void
    {
        $this->assertSame('KES', CountryCurrency::forCountry('KE'));
        $this->assertSame('TZS', CountryCurrency::forCountry('TZ'));
        $this->assertSame('UGX', CountryCurrency::forCountry('UG'));
        $this->assertSame('RWF', CountryCurrency::forCountry('RW'));
        $this->assertSame('BIF', CountryCurrency::forCountry('BI'));
        $this->assertSame('SSP', CountryCurrency::forCountry('SS'));
        $this->assertSame('SOS', CountryCurrency::forCountry('SO'));
        $this->assertSame('NGN', CountryCurrency::forCountry('NG'));
        $this->assertSame('USD', CountryCurrency::forCountry('US'));
    }

    public function test_tanzania_customer_gets_tzs_currency(): void
    {
        $this->setUsableRate('TZS', 18.5);

        $user = User::factory()->create([
            'country' => 'TZ',
            'preferred_currency' => null,
        ]);

        app(UserCurrencyService::class)->syncFromCountry($user, true);

        $this->assertSame('TZS', $user->fresh()->preferred_currency);
    }

    public function test_user_currency_defaults_from_country(): void
    {
        $this->setUsableRate('NGN', 0.011);

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
        $this->setUsableRate('USD', 0.0077);

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

    public function test_reseller_invoice_stays_on_kes_ledger(): void
    {
        $reseller = User::factory()->create([
            'is_reseller' => true,
            'country' => 'US',
            'preferred_currency' => 'USD',
        ]);

        $this->setUsableRate('USD', 0.01);

        $invoice = Invoice::create([
            'user_id' => $reseller->id,
            'invoice_number' => 'INV-RESELLER-1',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 2500,
            'tax' => 0,
            'total' => 2500,
        ]);

        $this->assertSame('KES', $invoice->currency);
        $this->assertEquals(2500, (float) $invoice->total);
    }

    public function test_wallet_topup_invoice_stays_on_kes_ledger(): void
    {
        $user = User::factory()->create([
            'country' => 'US',
            'preferred_currency' => 'USD',
        ]);

        $this->setUsableRate('USD', 0.01);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'TOPUP-'.strtoupper(uniqid()),
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 500,
            'tax' => 0,
            'total' => 500,
            'notes' => 'Wallet top-up: 500 KES',
        ]);

        $this->assertSame('KES', $invoice->currency);
        $this->assertEquals(500, (float) $invoice->total);
        $this->assertEquals(500, (float) $invoice->total_base_kes);
    }

    public function test_mpesa_settlement_uses_kes_remaining_balance(): void
    {
        $this->setUsableRate('USD', 0.01);

        $user = User::factory()->create(['preferred_currency' => 'USD']);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-MPESA',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $settlement = app(InvoiceCurrencyService::class)->settlementAmount($invoice, 'KES');

        $this->assertSame('KES', $settlement['currency']);
        $this->assertEquals(1000, $settlement['amount']);
    }

    public function test_paypal_settlement_uses_invoice_currency_when_usd(): void
    {
        $this->setUsableRate('USD', 0.01);

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

    public function test_get_amount_paid_converts_payment_currencies(): void
    {
        $this->setUsableRate('USD', 0.01);

        $user = User::factory()->create(['preferred_currency' => 'USD']);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-PAID',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        Payment::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'currency' => 'KES',
            'payment_method' => PaymentMethod::Mpesa,
            'transaction_reference' => 'mpesa-test-1',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $this->assertEquals(5.0, $invoice->fresh()->getAmountPaid());
    }

    public function test_overpayment_credit_is_stored_in_kes(): void
    {
        $this->setUsableRate('USD', 0.01);

        $user = User::factory()->create(['preferred_currency' => 'USD']);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-OVER',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => 1500,
            'currency' => 'KES',
            'payment_method' => PaymentMethod::Mpesa,
            'transaction_reference' => 'mpesa-test-over',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $this->assertTrue($payment->isOverpayment());
        $this->assertEquals(500.0, $payment->getOverpaymentAmount());
    }
}
