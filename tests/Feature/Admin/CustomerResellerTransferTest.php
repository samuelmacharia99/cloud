<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerResellerTransferTest extends TestCase
{
    use RefreshDatabase;

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
        ]);
    }

    public function test_admin_can_reassign_customer_to_another_reseller_without_changing_ownership(): void
    {
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
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
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
        $payment->refresh();
        $domainOrder->refresh();

        $this->assertSame($resellerB->id, $customer->reseller_id);
        $this->assertSame($customer->id, $service->user_id);
        $this->assertSame($resellerB->id, $service->reseller_id);
        $this->assertSame($customer->id, $domain->user_id);
        $this->assertSame($resellerB->id, $domain->reseller_id);
        $this->assertSame($customer->id, $invoice->user_id);
        $this->assertSame($customer->id, $payment->user_id);
        $this->assertSame($resellerB->id, $domainOrder->reseller_id);
    }

    public function test_admin_can_transfer_customer_back_to_platform(): void
    {
        $admin = $this->createAdmin();
        $reseller = $this->createReseller();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

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

        $this->assertNull($customer->reseller_id);
        $this->assertNull($service->reseller_id);
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
