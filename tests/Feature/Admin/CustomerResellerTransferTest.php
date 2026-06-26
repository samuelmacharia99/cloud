<?php

namespace Tests\Feature\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TicketHandledBy;
use App\Mail\CustomerAccountTransferredMail;
use App\Mail\ResellerCustomerAssignedMail;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\InvoiceGenerationScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerResellerTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'smtp_host'], ['value' => 'smtp.example.com']);
        Setting::updateOrCreate(['key' => 'mail_from_address'], ['value' => 'noreply@example.com']);
    }

    private function createAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function createReseller(string $name = 'Reseller A'): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'name' => $name,
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
            'settings' => [
                'branding' => [
                    'company_name' => $name.' Hosting',
                    'custom_domain' => null,
                ],
            ],
        ]);
    }

    public function test_admin_can_reassign_customer_to_another_reseller_and_cancel_open_invoices(): void
    {
        Mail::fake();

        $admin = $this->createAdmin();
        $resellerA = $this->createReseller('Reseller A');
        $resellerB = $this->createReseller('Reseller B');

        $customer = User::factory()->create(['reseller_id' => $resellerA->id]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $resellerA->id,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $resellerA->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-TRANSFER-1',
            'status' => 'unpaid',
            'due_date' => now(),
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);

        $paidInvoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-TRANSFER-PAID',
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 50,
            'tax' => 0,
            'total' => 50,
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'status' => PaymentStatus::Completed,
            'payment_method' => PaymentMethod::Manual,
        ]);

        $domainOrder = ResellerDomainOrder::create([
            'reseller_id' => $resellerA->id,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'domain_name' => 'example',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 500,
            'retail_amount' => 200,
            'status' => 'queued',
            'push_mode' => 'auto',
        ]);

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'reseller_id' => $resellerA->id,
            'handled_by' => TicketHandledBy::Reseller->value,
            'title' => 'Help',
            'description' => 'Need help',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.customers.transfer-to-reseller', $customer), [
                'target_reseller_id' => $resellerB->id,
            ])
            ->assertRedirect(route('admin.customers.index'))
            ->assertSessionHas('success');

        $customer->refresh();
        $service->refresh();
        $domain->refresh();
        $invoice->refresh();
        $paidInvoice->refresh();
        $payment->refresh();
        $domainOrder->refresh();
        $ticket->refresh();

        $this->assertSame($resellerB->id, $customer->reseller_id);
        $this->assertSame($customer->id, $service->user_id);
        $this->assertSame($resellerB->id, $service->reseller_id);
        $this->assertSame($customer->id, $domain->user_id);
        $this->assertSame($resellerB->id, $domain->reseller_id);
        $this->assertSame($customer->id, $payment->user_id);
        $this->assertSame($resellerB->id, $domainOrder->reseller_id);
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
        $this->assertSame(InvoiceStatus::Paid, $paidInvoice->status);
        $this->assertSame($resellerB->id, $ticket->reseller_id);
        $this->assertSame(TicketHandledBy::Reseller->value, $ticket->handled_by);

        Mail::assertSent(CustomerAccountTransferredMail::class, fn ($mail) => $mail->hasTo($customer->email));
        Mail::assertSent(ResellerCustomerAssignedMail::class, fn ($mail) => $mail->hasTo($resellerB->email));
    }

    public function test_transfer_preview_returns_checklist_json(): void
    {
        $admin = $this->createAdmin();
        $reseller = $this->createReseller();
        $customer = User::factory()->create(['reseller_id' => null]);

        Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-PREVIEW-1',
            'status' => 'unpaid',
            'due_date' => now(),
            'subtotal' => 200,
            'tax' => 0,
            'total' => 200,
        ]);

        Service::factory()->create(['user_id' => $customer->id]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.customers.transfer-preview', $customer).'?target_reseller_id='.$reseller->id);

        $response->assertOk()
            ->assertJsonPath('counts.services', 1)
            ->assertJsonPath('counts.open_invoices', 1)
            ->assertJsonPath('will_cancel_invoices', true)
            ->assertJsonPath('will_send_customer_email', true);
    }

    public function test_reseller_managed_services_excluded_from_platform_renewal_cron_query(): void
    {
        $reseller = $this->createReseller();
        $platformCustomer = User::factory()->create(['reseller_id' => null]);
        $resellerCustomer = User::factory()->create(['reseller_id' => $reseller->id]);

        $platformService = Service::factory()->create([
            'user_id' => $platformCustomer->id,
            'reseller_id' => null,
            'status' => 'active',
            'next_due_date' => now()->addDays(5),
            'billing_cycle' => 'monthly',
        ]);

        $resellerService = Service::factory()->create([
            'user_id' => $resellerCustomer->id,
            'reseller_id' => $reseller->id,
            'status' => 'active',
            'next_due_date' => now()->addDays(5),
            'billing_cycle' => 'monthly',
        ]);

        $schedule = app(InvoiceGenerationScheduleService::class);
        $ids = $schedule->servicesDueForRenewalInvoiceQuery()->pluck('id');

        $this->assertTrue($ids->contains($platformService->id));
        $this->assertFalse($ids->contains($resellerService->id));
    }

    public function test_admin_can_transfer_customer_back_to_platform_without_cancelling_invoices(): void
    {
        Mail::fake();

        $admin = $this->createAdmin();
        $reseller = $this->createReseller();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-BACK-1',
            'status' => 'unpaid',
            'due_date' => now(),
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.customers.transfer-to-reseller', $customer), [
                'target_reseller_id' => 'platform',
            ])
            ->assertRedirect(route('admin.customers.index'))
            ->assertSessionHas('success');

        $customer->refresh();
        $service->refresh();
        $invoice->refresh();

        $this->assertNull($customer->reseller_id);
        $this->assertNull($service->reseller_id);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);

        Mail::assertNothingSent();
    }

    public function test_platform_to_reseller_transfer_maps_closest_catalog_plan(): void
    {
        Mail::fake();

        $admin = $this->createAdmin();
        $reseller = $this->createReseller();

        $product = Product::create([
            'name' => 'Platform Bronze',
            'slug' => 'platform-bronze-'.uniqid(),
            'type' => 'vps',
            'monthly_price' => 2000,
            'yearly_price' => 20000,
            'order' => 2,
            'is_active' => true,
        ]);

        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'name' => 'Reseller VPS Bronze',
            'type' => 'vps',
            'monthly_price' => 2500,
            'yearly_price' => 25000,
            'is_active' => true,
        ]);

        $customer = User::factory()->create(['reseller_id' => null]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => null,
            'product_id' => $product->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.customers.transfer-to-reseller', $customer), [
                'target_reseller_id' => $reseller->id,
            ])
            ->assertRedirect(route('admin.customers.index'))
            ->assertSessionHas('success');

        $service->refresh();

        $this->assertSame($reseller->id, $customer->fresh()->reseller_id);
        $this->assertSame($reseller->id, $service->reseller_id);
        $this->assertSame($listing->id, (int) ($service->service_meta['reseller_product_id'] ?? 0));
        $this->assertSame($product->id, $service->product_id);
    }

    public function test_cannot_transfer_platform_customer_to_platform_again(): void
    {
        $admin = $this->createAdmin();
        $customer = User::factory()->create(['reseller_id' => null]);

        $this->actingAs($admin)
            ->post(route('admin.customers.transfer-to-reseller', $customer), [
                'target_reseller_id' => 'platform',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_cannot_transfer_customer_to_same_reseller(): void
    {
        $admin = $this->createAdmin();
        $reseller = $this->createReseller();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($admin)
            ->post(route('admin.customers.transfer-to-reseller', $customer), [
                'target_reseller_id' => $reseller->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
