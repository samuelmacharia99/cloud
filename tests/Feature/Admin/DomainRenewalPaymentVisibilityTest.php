<?php

namespace Tests\Feature\Admin;

use App\Enums\InvoiceStatus;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\DomainRenewalPushService;
use App\Services\DomainRenewalService;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainRenewalPaymentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function seedRetailPricing(): DomainExtension
    {
        $extension = DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'retail',
            'price' => 2000,
            'renewal_price' => 1800,
            'enabled' => true,
        ]);

        return $extension;
    }

    public function test_paid_platform_customer_renewal_is_pushed_and_listed_for_admin(): void
    {
        $this->mock(RegistrarFulfillmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillRenewal')->once();
        });

        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['country' => 'KE']);
        $this->seedRetailPricing();

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'payme',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addDays(20),
        ]);

        $renewalService = app(DomainRenewalService::class);
        $renewalOrder = $renewalService->initiateRenewal($domain, $customer, 1);
        $invoice = $renewalService->createInvoice($renewalOrder);

        $this->assertSame($invoice->id, $renewalOrder->fresh()->customer_invoice_id);
        $this->assertSame($invoice->id, $renewalOrder->fresh()->invoice_id);

        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        $renewalOrder->refresh();
        $this->assertSame('pushed', $renewalOrder->status);
        $this->assertNotNull($renewalOrder->admin_order_id);
        $this->assertNotNull($renewalOrder->paid_at);

        $this->actingAs($admin)
            ->get(route('admin.domain-renewals.index'))
            ->assertOk()
            ->assertSee('payme.com')
            ->assertSee('Pushed');
    }

    public function test_legacy_invoice_id_only_renewal_still_pushes_after_payment(): void
    {
        $this->mock(RegistrarFulfillmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillRenewal')->once();
        });

        $customer = User::factory()->customer()->create(['country' => 'KE']);
        $this->seedRetailPricing();

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'legacy',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $renewalService = app(DomainRenewalService::class);
        $renewalOrder = $renewalService->initiateRenewal($domain, $customer, 1);
        $invoice = $renewalService->createInvoice($renewalOrder);

        // Simulate historical rows that never set customer_invoice_id.
        $renewalOrder->update(['customer_invoice_id' => null]);

        $invoice->update([
            'status' => InvoiceStatus::Paid,
            'paid_date' => now(),
        ]);

        app(DomainRenewalPushService::class)->handlePaidInvoice($invoice->fresh());

        $renewalOrder->refresh();
        $this->assertSame('pushed', $renewalOrder->status);
        $this->assertNotNull($renewalOrder->admin_order_id);
    }
}
