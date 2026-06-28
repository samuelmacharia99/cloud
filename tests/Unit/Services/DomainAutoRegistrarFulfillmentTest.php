<?php

namespace Tests\Unit\Services;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Registrar;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerWallet;
use App\Models\User;
use App\Enums\RegistrarDriver;
use App\Services\DomainPushService;
use App\Services\Registrar\RegistrarFulfillmentService;
use App\Services\ResellerDomainOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DomainAutoRegistrarFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_wallet_push_triggers_automatic_registrar_fulfillment(): void
    {
        $this->mockRegistrarFulfillment(['success' => true, 'message' => 'Submitted']);

        $order = $this->createPaidResellerOrderWithWalletFunds();

        app(DomainPushService::class)->handlePaidDomainInvoice(
            $order->paidCustomerInvoice()->fresh(['items', 'user'])
        );

        $order->refresh();
        $this->assertSame('pushed', $order->status);
    }

    public function test_registrar_submission_failure_does_not_refund_wallet(): void
    {
        $this->mockRegistrarFulfillment(['success' => false, 'message' => 'Insufficient Openprovider balance']);

        $reseller = User::factory()->create(['is_reseller' => true]);
        ResellerWallet::create([
            'reseller_id' => $reseller->id,
            'balance' => 10000,
            'currency' => 'KES',
            'status' => 'active',
        ]);

        $extension = $this->createExtension();
        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'autofail',
            'extension' => '.com',
            'status' => 'pending',
            'ns1' => 'ns1.example.test',
            'ns2' => 'ns2.example.test',
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_id' => $domain->id,
            'domain_name' => 'autofail',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 500,
            'retail_amount' => 0,
            'status' => 'queued',
            'push_mode' => 'auto',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        app(DomainPushService::class)->pushOrderUsingWallet($order);

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertSame(9500.0, (float) ResellerWallet::where('reseller_id', $reseller->id)->value('balance'));
    }

    public function test_can_admin_push_to_registrar_is_false_when_submission_is_pending(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'pending',
            'extension' => '.com',
            'status' => 'pending',
            'registrar_external_id' => 12345,
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_id' => $domain->id,
            'domain_name' => 'pending',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 500,
            'retail_amount' => 0,
            'status' => 'pushed',
            'push_mode' => 'auto',
            'pushed_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        $this->assertTrue($order->hasPendingRegistrarSubmission());
        $this->assertFalse($order->canAdminPushToRegistrar());
    }

    public function test_manual_push_mode_skips_automatic_registrar_fulfillment(): void
    {
        $mock = Mockery::mock(RegistrarFulfillmentService::class);
        $mock->shouldNotReceive('attemptAutoFulfillment');
        $this->app->instance(RegistrarFulfillmentService::class, $mock);

        $reseller = User::factory()->create(['is_reseller' => true]);
        ResellerWallet::create([
            'reseller_id' => $reseller->id,
            'balance' => 10000,
            'currency' => 'KES',
            'status' => 'active',
        ]);

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'manualmode',
            'extension' => '.com',
            'status' => 'pending',
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_id' => $domain->id,
            'domain_name' => 'manualmode',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 500,
            'retail_amount' => 0,
            'status' => 'queued',
            'push_mode' => 'manual',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        app(DomainPushService::class)->pushOrderUsingWallet($order);

        $order->refresh();
        $this->assertSame('pushed', $order->status);
    }

    /**
     * @param  array{success: bool, message: string}  $result
     */
    private function mockRegistrarFulfillment(array $result): void
    {
        $mock = Mockery::mock(RegistrarFulfillmentService::class);
        $mock->shouldReceive('attemptAutoFulfillment')
            ->once()
            ->andReturnUsing(function ($order) use ($result) {
                if (! $result['success']) {
                    app(DomainPushService::class)->failRegistrarSubmission($order->fresh(), $result['message']);
                }

                return $result;
            });
        $mock->shouldReceive('fulfillStandaloneTransfer')->zeroOrMoreTimes();
        $this->app->instance(RegistrarFulfillmentService::class, $mock);
    }

    private function createPaidResellerOrderWithWalletFunds(): ResellerDomainOrder
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        ResellerWallet::create([
            'reseller_id' => $reseller->id,
            'balance' => 10000,
            'currency' => 'KES',
            'status' => 'active',
        ]);

        $this->createExtension();

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'pending',
        ]);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-AUTO-1',
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $order = app(ResellerDomainOrderService::class)->createForCustomerCheckout(
            $customer,
            $domain,
            $invoice,
            'example',
            '.com',
            1,
            1000,
        );

        InvoiceItem::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => 'example.com',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
        ], app(ResellerDomainOrderService::class)->invoiceItemAttributes($order)));

        return $order->fresh(['customerInvoice']);
    }

    private function createExtension(): DomainExtension
    {
        $registrar = Registrar::create([
            'name' => 'Openprovider Test',
            'slug' => 'openprovider-test',
            'driver' => RegistrarDriver::Openprovider,
            'environment' => 'sandbox',
            'is_active' => true,
            'is_default' => true,
            'config' => ['owner_handle' => 'XX000000-XX'],
        ]);

        $extension = DomainExtension::create([
            'extension' => '.com',
            'enabled' => true,
            'registrar_id' => $registrar->id,
            'registrar' => $registrar->slug,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'tier' => 'wholesale',
            'period_years' => 1,
            'price' => 500,
            'enabled' => true,
        ]);

        return $extension;
    }
}
