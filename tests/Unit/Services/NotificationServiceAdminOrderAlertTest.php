<?php

namespace Tests\Unit\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotificationServiceAdminOrderAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('sms_enabled', 'true');
        Setting::setValue('sms_api_token', 'test-token');
        Setting::setValue('notify_admin_new_order', 'true');
        Setting::setValue('notify_admin_manual_payment', 'true');
        Setting::setValue('notify_admin_reseller_domain_push', 'true');
        Setting::setValue('notify_service_provision_failed', 'true');
    }

    private function createAdminWithPhones(): User
    {
        return User::factory()->create([
            'is_admin' => true,
            'notification_phones' => ['254712345678'],
        ]);
    }

    public function test_notify_new_order_sends_admin_sms_using_admin_new_order_setting(): void
    {
        $this->createAdminWithPhones();
        $customer = User::factory()->customer()->create(['name' => 'Jane Customer']);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'total' => 1500,
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'order_number' => 'ORD-TEST-1',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 1500,
            'tax' => 0,
            'total' => 1500,
        ]);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')
            ->once()
            ->with(['254712345678'], Mockery::on(fn (string $message) => str_contains($message, 'ORD-TEST-1') && str_contains($message, 'Jane Customer')));
        $this->app->instance(SmsService::class, $sms);

        app(NotificationService::class)->notifyNewOrder($order, $invoice, 'awaiting payment');
    }

    public function test_notify_new_order_can_skip_duplicate_admin_sms_on_payment(): void
    {
        $this->createAdminWithPhones();
        $customer = User::factory()->customer()->create();
        $invoice = Invoice::factory()->create(['user_id' => $customer->id, 'total' => 500]);
        $order = Order::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'order_number' => 'ORD-TEST-2',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 500,
            'tax' => 0,
            'total' => 500,
        ]);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')->never();
        $this->app->instance(SmsService::class, $sms);

        app(NotificationService::class)->notifyNewOrder($order, $invoice, 'mpesa', notifyAdmin: false);
    }

    public function test_notify_manual_payment_submitted_sends_admin_sms(): void
    {
        $this->createAdminWithPhones();
        $customer = User::factory()->customer()->create(['name' => 'Pay Customer']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-MAN-1',
            'total' => 900,
        ]);
        $payment = Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 900,
            'status' => 'pending',
            'payment_method' => 'manual',
        ]);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')
            ->once()
            ->with(['254712345678'], Mockery::on(fn (string $message) => str_contains($message, 'INV-MAN-1') && str_contains($message, 'Pay Customer')));
        $this->app->instance(SmsService::class, $sms);

        app(NotificationService::class)->notifyManualPaymentSubmitted($payment);
    }

    public function test_notify_admin_reseller_domain_order_sends_sms_on_push(): void
    {
        $this->createAdminWithPhones();
        $reseller = User::factory()->reseller()->create(['name' => 'Alpha Reseller']);
        $customer = User::factory()->create(['name' => 'End User', 'reseller_id' => $reseller->id]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
            'domain_name' => 'example',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 800,
            'retail_amount' => 200,
            'status' => 'pushed',
            'queued_at' => now(),
        ]);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')
            ->once()
            ->with(['254712345678'], Mockery::on(fn (string $message) => str_contains($message, 'example.com') && str_contains($message, 'Alpha Reseller')));
        $this->app->instance(SmsService::class, $sms);

        app(NotificationService::class)->notifyAdminResellerDomainOrder($order, 'pushed');
    }

    public function test_notify_new_order_skips_admin_sms_for_reseller_customer(): void
    {
        $this->createAdminWithPhones();
        $reseller = User::factory()->reseller()->create(['name' => 'Beta Reseller']);
        $customer = User::factory()->customer()->create([
            'name' => 'Reseller Client',
            'reseller_id' => $reseller->id,
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'total' => 2500,
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'order_number' => 'ORD-RESELLER-1',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 2500,
            'tax' => 0,
            'total' => 2500,
        ]);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')->never();
        $this->app->instance(SmsService::class, $sms);

        app(NotificationService::class)->notifyNewOrder($order, $invoice, 'awaiting payment');
    }

    public function test_notify_reseller_customer_domain_placed_skips_admin_sms(): void
    {
        $this->createAdminWithPhones();
        $reseller = User::factory()->reseller()->create(['name' => 'Gamma Reseller']);
        $customer = User::factory()->create(['name' => 'Domain Client', 'reseller_id' => $reseller->id]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
            'domain_name' => 'client',
            'extension' => '.co.ke',
            'years' => 1,
            'wholesale_amount' => 1200,
            'retail_amount' => 1800,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')->never();
        $this->app->instance(SmsService::class, $sms);

        app(NotificationService::class)->notifyAdminResellerDomainOrder($order, 'placed', 'awaiting payment');
    }

    public function test_notify_service_provision_failed_skips_admin_sms_for_reseller_customer(): void
    {
        $this->createAdminWithPhones();
        $reseller = User::factory()->reseller()->create(['name' => 'Beta Reseller']);
        $customer = User::factory()->customer()->create([
            'name' => 'Reseller Client',
            'reseller_id' => $reseller->id,
        ]);

        $service = Service::factory()->for($customer)->create([
            'status' => 'failed',
            'name' => 'Reseller Hosting',
        ]);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')->never();
        $this->app->instance(SmsService::class, $sms);

        app(NotificationService::class)->notifyServiceProvisionFailed($service, 'DirectAdmin API timeout');
    }

    public function test_notify_service_provision_failed_sends_admin_sms_for_platform_customer(): void
    {
        $this->createAdminWithPhones();
        $customer = User::factory()->customer()->create(['name' => 'Direct Client']);

        $service = Service::factory()->for($customer)->create([
            'status' => 'failed',
            'name' => 'Platform Hosting',
        ]);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')
            ->once()
            ->with(['254712345678'], Mockery::on(fn (string $message) => str_contains($message, 'Platform Hosting')));
        $this->app->instance(SmsService::class, $sms);

        app(NotificationService::class)->notifyServiceProvisionFailed($service, 'DirectAdmin API timeout');
    }
}
