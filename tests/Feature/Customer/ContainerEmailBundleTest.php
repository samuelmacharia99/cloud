<?php

namespace Tests\Feature\Customer;

use App\Enums\ServiceStatus;
use App\Models\ContainerTemplate;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\Checkout\ContainerEmailBundleService;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContainerEmailBundleTest extends TestCase
{
    use RefreshDatabase;

    private function makeBundledProducts(array $emailOverrides = [], array $containerOverrides = []): array
    {
        $template = ContainerTemplate::factory()->create();
        $email = Product::factory()->emailHosting()->create(array_merge([
            'name' => 'Free Email',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_active' => true,
        ], $emailOverrides));

        $container = Product::factory()->containerHosting()->create(array_merge([
            'name' => 'App Starter',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'container_template_id' => $template->id,
            'bundled_email_product_id' => $email->id,
            'bundle_email_include_in_invoice' => true,
            'bundle_email_billing_cycle' => 'monthly',
            'bundle_email_billing_delay_months' => 1,
            'is_active' => true,
        ], $containerOverrides));

        return [$container, $email];
    }

    public function test_admin_can_save_container_email_bundle_settings(): void
    {
        $admin = User::factory()->admin()->create();
        $template = ContainerTemplate::factory()->create();
        $email = Product::factory()->emailHosting()->create(['is_active' => true, 'monthly_price' => 0]);

        $response = $this->actingAs($admin)->post(route('admin.products.store'), [
            'name' => 'Bundled App Plan',
            'slug' => 'bundled-app-plan',
            'type' => 'container_hosting',
            'container_template_id' => $template->id,
            'monthly_price' => 1500,
            'yearly_price' => 15000,
            'setup_fee' => 0,
            'is_active' => 1,
            'bundled_email_product_id' => $email->id,
            'bundle_email_include_in_invoice' => 1,
            'bundle_email_billing_cycle' => 'annual',
            'bundle_email_billing_delay_months' => 2,
            'resource_limits' => ['cpu' => 1, 'memory' => 512, 'disk' => 10],
        ]);

        $response->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseHas('products', [
            'slug' => 'bundled-app-plan',
            'bundled_email_product_id' => $email->id,
            'bundle_email_include_in_invoice' => 1,
            'bundle_email_billing_cycle' => 'annual',
            'bundle_email_billing_delay_months' => 2,
        ]);
    }

    public function test_bundle_attach_requires_domain_and_respects_billing_delay(): void
    {
        Node::factory()->mailcow()->create();
        [$container, $email] = $this->makeBundledProducts();
        $customer = User::factory()->customer()->create();
        $invoice = Invoice::factory()->create(['user_id' => $customer->id, 'status' => 'unpaid', 'total' => 0, 'subtotal' => 0, 'tax' => 0]);
        $order = Order::factory()->create(['user_id' => $customer->id, 'invoice_id' => $invoice->id]);
        $appService = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $container->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceStatus::Pending,
            'billing_cycle' => 'monthly',
            'service_meta' => [],
        ]);

        $request = Request::create('/checkout', 'POST', [
            'bundle_primary_domain' => ['cart-1' => 'myapp.example.com'],
        ]);

        $emailService = app(ContainerEmailBundleService::class)->attachToContainerService(
            $request,
            'cart-1',
            $customer,
            $container,
            $appService,
            $invoice,
            $order,
            ['billing_cycle' => 'monthly'],
        );

        $this->assertNotNull($emailService);
        $this->assertSame($email->id, $emailService->product_id);
        $this->assertSame('myapp.example.com', $emailService->service_meta['mailcow_domain']);
        $this->assertSame($appService->id, $emailService->service_meta['bundled_from_service_id']);
        // delay 1 + monthly cycle 1 = 2 months
        $this->assertTrue($emailService->next_due_date->equalTo(now()->addMonths(2)->startOfSecond())
            || $emailService->next_due_date->diffInMinutes(now()->addMonths(2)) < 2);

        $appService->refresh();
        $this->assertSame('myapp.example.com', $appService->service_meta['primary_domain']);
        $this->assertSame($emailService->id, $appService->service_meta['bundled_email_service_id']);

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'service_id' => $emailService->id,
            'product_id' => $email->id,
            'amount' => 0,
        ]);
    }

    public function test_bundle_without_include_in_invoice_skips_invoice_line(): void
    {
        Node::factory()->mailcow()->create();
        [$container, $email] = $this->makeBundledProducts([], [
            'bundle_email_include_in_invoice' => false,
            'bundle_email_billing_delay_months' => 0,
        ]);
        $customer = User::factory()->customer()->create();
        $invoice = Invoice::factory()->create(['user_id' => $customer->id]);
        $order = Order::factory()->create(['user_id' => $customer->id, 'invoice_id' => $invoice->id]);
        $appService = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $container->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceStatus::Pending,
        ]);

        $request = Request::create('/checkout', 'POST', [
            'bundle_primary_domain' => ['cart-1' => 'freebie.example.com'],
        ]);

        $emailService = app(ContainerEmailBundleService::class)->attachToContainerService(
            $request,
            'cart-1',
            $customer,
            $container->fresh(),
            $appService,
            $invoice,
            $order,
            ['billing_cycle' => 'monthly'],
        );

        $this->assertNotNull($emailService);
        $this->assertDatabaseMissing('invoice_items', [
            'invoice_id' => $invoice->id,
            'service_id' => $emailService->id,
        ]);
        $this->assertTrue($emailService->next_due_date->diffInMinutes(now()->addMonth()) < 2);
    }

    public function test_zero_total_invoice_settles_and_finalizes_pending_services(): void
    {
        Setting::setValue('tax_enabled', 'false');

        $customer = User::factory()->customer()->create();
        $product = Product::factory()->emailHosting()->create(['monthly_price' => 0]);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceStatus::Pending,
            'custom_price' => 0,
        ]);

        $this->mock(InvoiceProvisioningService::class, function ($mock) use ($invoice) {
            $mock->shouldReceive('provisionPendingServicesForInvoice')
                ->once()
                ->withArgs(fn ($arg) => $arg->id === $invoice->id)
                ->andReturn(['provisioned' => 1, 'failed' => 0, 'skipped' => 0]);
        });

        $this->mock(ProvisioningService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });

        $settled = app(InvoiceSettlementService::class)->settleFullyPaid($invoice);

        $this->assertTrue($settled);
        $this->assertSame('paid', $invoice->fresh()->status->value);
    }

    public function test_checkout_validation_requires_bundle_domain(): void
    {
        [$container] = $this->makeBundledProducts();
        $cart = [
            'app-1' => [
                'type' => 'product',
                'product_id' => $container->id,
                'billing_cycle' => 'monthly',
            ],
        ];

        $request = Request::create('/checkout', 'POST', []);

        $this->expectException(ValidationException::class);
        app(ContainerEmailBundleService::class)->validateCheckoutRequest($request, $cart);
    }
}
