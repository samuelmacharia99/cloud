<?php

namespace Tests\Feature\Admin;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\ResellerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInvoiceAddPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_manual_payment_on_reseller_invoice_with_wallet_applied(): void
    {
        $this->mock(InvoiceProvisioningService::class, function ($mock) {
            $mock->shouldReceive('provisionPendingServicesForInvoice')
                ->andReturn(['provisioned' => 0, 'failed' => [], 'skipped' => true]);
        });

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_reseller' => false])->save();
        $reseller = User::factory()->reseller()->create();

        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 0]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'unpaid',
            'subtotal' => 2000,
            'tax' => 0,
            'total' => 2000,
            'wallet_amount_applied' => 500,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.invoices.add-payment', $invoice), [
            'amount' => 1500,
            'payment_method' => 'manual',
            'transaction_reference' => 'ADM-001',
            'paid_at' => now()->format('Y-m-d'),
        ]);

        $response->assertRedirect(route('admin.invoices.show', $invoice));
        $response->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
    }
}
