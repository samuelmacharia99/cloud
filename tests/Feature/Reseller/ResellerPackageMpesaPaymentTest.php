<?php

namespace Tests\Feature\Reseller;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\ResellerPackage;
use App\Models\Setting;
use App\Models\User;
use App\Services\DomainPushService;
use App\Services\ResellerPackageSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerPackageMpesaPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('mpesa_enabled', '1');
        Setting::setValue('mpesa_environment', 'sandbox');
        Setting::setValue('mpesa_shortcode', '174379');
        Setting::setValue('mpesa_consumer_key', 'platform-key');
        Setting::setValue('mpesa_consumer_secret', 'platform-secret');
        Setting::setValue('mpesa_passkey', 'platform-passkey');
        Setting::setValue('site_url', 'https://example.test');
        Setting::setValue('tax_enabled', 'false');

        $this->mock(DomainPushService::class, function ($mock) {
            $mock->shouldReceive('ensurePaidInvoiceDomainOrdersPushed')->andReturnNull();
            $mock->shouldReceive('handlePaidResellerInvoice')->andReturnNull();
        });
    }

    private function createPackage(): ResellerPackage
    {
        return ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 10,
            'price' => 5300,
            'active' => true,
        ]);
    }

    public function test_mpesa_poll_marks_subscription_invoice_paid_and_activates_package(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'platform-token'], 200),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResultCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
            ], 200),
        ]);

        $reseller = User::factory()->reseller()->create();
        $package = $this->createPackage();
        $invoice = app(ResellerPackageSubscriptionService::class)->createSubscriptionInvoice($reseller, $package);

        $payment = Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'mpesa',
            'status' => PaymentStatus::Pending,
            'amount' => $invoice->total,
            'transaction_reference' => 'ws_CO_package_sub',
        ]);

        $response = $this->actingAs($reseller)->getJson(route('reseller.payment.mpesa-status', [
            'invoice' => $invoice,
            'checkout_request_id' => $payment->transaction_reference,
        ]));

        $response->assertOk();
        $response->assertJson(['status' => 'completed']);

        $reseller->refresh();
        $invoice->refresh();
        $payment->refresh();

        $this->assertSame('completed', $payment->status->value);
        $this->assertSame('paid', $invoice->status->value);
        $this->assertSame($package->id, $reseller->reseller_package_id);
        $this->assertNotNull($reseller->package_expires_at);
    }

    public function test_mpesa_poll_does_not_report_completed_when_payment_settled_but_invoice_unpaid(): void
    {
        $reseller = User::factory()->reseller()->create();
        $package = $this->createPackage();
        $invoice = app(ResellerPackageSubscriptionService::class)->createSubscriptionInvoice($reseller, $package);

        $payment = Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'mpesa',
            'status' => PaymentStatus::Completed,
            'amount' => 100,
            'transaction_reference' => 'ws_CO_underpaid',
        ]);

        $response = $this->actingAs($reseller)->getJson(route('reseller.payment.mpesa-status', [
            'invoice' => $invoice,
            'checkout_request_id' => $payment->transaction_reference,
        ]));

        $response->assertOk();
        $response->assertJson(['status' => 'pending']);

        $reseller->refresh();
        $this->assertNull($reseller->reseller_package_id);
    }
}
