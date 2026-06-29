<?php

namespace Tests\Feature\Customer;

use App\Enums\PaymentStatus;
use App\Enums\ServiceStatus;
use App\Models\DirectAdminPackage;
use App\Models\InvoiceItem;
use App\Models\Node;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\Customer\CustomerServiceRenewalService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRenewalOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function createHostingStack(): array
    {
        $node = Node::create([
            'name' => 'DA Node',
            'hostname' => 'da.example.com',
            'ip_address' => '10.0.0.1',
            'type' => 'directadmin',
            'status' => 'online',
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'is_active' => true,
        ]);

        $bronzePackage = DirectAdminPackage::create([
            'name' => 'Bronze',
            'package_key' => 'bronze',
            'node_id' => $node->id,
            'disk_quota' => 10,
            'bandwidth_quota' => 100,
            'num_databases' => 2,
            'is_active' => true,
        ]);

        $silverPackage = DirectAdminPackage::create([
            'name' => 'Silver',
            'package_key' => 'silver',
            'node_id' => $node->id,
            'disk_quota' => 50,
            'bandwidth_quota' => 500,
            'num_databases' => 10,
            'is_active' => true,
        ]);

        $bronze = Product::create([
            'name' => 'Bronze',
            'slug' => 'bronze-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 1000,
            'provisioning_driver_key' => 'directadmin',
            'direct_admin_package_id' => $bronzePackage->id,
            'order' => 1,
            'is_active' => true,
        ]);

        $silver = Product::create([
            'name' => 'Silver',
            'slug' => 'silver-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 2000,
            'provisioning_driver_key' => 'directadmin',
            'direct_admin_package_id' => $silverPackage->id,
            'order' => 2,
            'is_active' => true,
        ]);

        return compact('node', 'bronze', 'silver');
    }

    public function test_renew_page_lists_current_plan_and_upgrade_options(): void
    {
        ['node' => $node, 'bronze' => $bronze, 'silver' => $silver] = $this->createHostingStack();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'client1',
            'next_due_date' => now()->addDays(5),
        ]);

        $response = $this->actingAs($customer)->get(route('customer.services.renew', $service));

        $response->assertOk();
        $response->assertSee('Keep current plan');
        $response->assertSee('Upgrade while renewing');
        $response->assertSee('Bronze');
        $response->assertSee('Silver');
        $response->assertDontSee('Generate a renewal invoice');
    }

    public function test_renewal_with_same_plan_creates_standard_invoice(): void
    {
        ['node' => $node, 'bronze' => $bronze] = $this->createHostingStack();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'client1',
        ]);

        $this->mock(NotificationService::class)->shouldReceive('notifyInvoiceGenerated')->once();

        $response = $this->actingAs($customer)->post(route('customer.services.renew.store', $service), [
            'product_id' => $bronze->id,
        ]);

        $response->assertRedirect();

        $item = InvoiceItem::query()->where('service_id', $service->id)->latest('id')->first();
        $this->assertNotNull($item);
        $this->assertSame($bronze->id, $item->product_id);
        $this->assertNull($item->custom_options);
    }

    public function test_renewal_with_upgrade_marks_invoice_for_plan_change_and_billing_advance(): void
    {
        Carbon::setTestNow('2026-03-25 10:00:00');

        ['node' => $node, 'bronze' => $bronze, 'silver' => $silver] = $this->createHostingStack();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'client1',
            'next_due_date' => '2026-03-31',
        ]);

        $this->mock(NotificationService::class)->shouldReceive('notifyInvoiceGenerated')->once();

        $response = $this->actingAs($customer)->post(route('customer.services.renew.store', $service), [
            'product_id' => $silver->id,
        ]);

        $response->assertRedirect();

        $item = InvoiceItem::query()->where('service_id', $service->id)->latest('id')->first();
        $this->assertNotNull($item);
        $this->assertSame($silver->id, $item->product_id);
        $this->assertTrue($item->custom_options['hosting_renewal_upgrade'] ?? false);
        $this->assertTrue($item->custom_options['hosting_upgrade'] ?? false);

        $invoice = $item->invoice;
        $payment = Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'status' => PaymentStatus::Completed,
        ]);

        $this->mock(CustomerHostingUpgradeService::class)
            ->shouldReceive('applyPaidUpgradesForInvoice')
            ->once();

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        $service->refresh();
        $this->assertSame('2026-04-30', $service->next_due_date->toDateString());
    }

    public function test_renewal_options_service_excludes_downgrades(): void
    {
        ['node' => $node, 'bronze' => $bronze, 'silver' => $silver] = $this->createHostingStack();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $silver->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'client1',
        ]);

        $options = app(CustomerServiceRenewalService::class)->renewalOptions($service, $customer);

        $this->assertSame('Silver', $options['current']['name']);
        $this->assertTrue($options['upgrades']->isEmpty());
    }

    public function test_same_node_plans_include_downgrades_on_annual_renewal(): void
    {
        ['node' => $node, 'bronze' => $bronze, 'silver' => $silver] = $this->createHostingStack();

        $bronze->update(['monthly_price' => 500, 'yearly_price' => 4560]);
        $silver->update(['monthly_price' => 300, 'yearly_price' => 3000]);

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'client1',
        ]);

        $options = app(CustomerServiceRenewalService::class)->renewalOptions($service, $customer);

        $this->assertTrue($options['can_choose_plan']);
        $this->assertTrue($options['upgrades']->contains(fn (array $option) => $option['product']->id === $silver->id));
        $this->assertSame('downgrade', $options['upgrades']->first(
            fn (array $option) => $option['product']->id === $silver->id
        )['change_type']);
    }

    public function test_same_node_query_lists_all_platform_plans_when_plan_change_empty(): void
    {
        ['node' => $node, 'bronze' => $bronze, 'silver' => $silver] = $this->createHostingStack();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => null,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => null,
            'external_reference' => null,
        ]);

        $options = app(CustomerServiceRenewalService::class)->renewalOptions($service, $customer);

        $this->assertTrue($options['can_choose_plan']);
        $this->assertSame('Silver', $options['upgrades']->first()['name']);
    }

    public function test_category_fallback_lists_same_category_plans_when_service_node_id_missing(): void
    {
        ['node' => $node, 'bronze' => $bronze, 'silver' => $silver] = $this->createHostingStack();

        $bronze->update(['category' => 'DirectAdmin Hosting']);
        $silver->update(['category' => 'DirectAdmin Hosting']);

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => null,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => null,
            'external_reference' => null,
        ]);

        $options = app(CustomerServiceRenewalService::class)->renewalOptions($service, $customer);

        $this->assertTrue($options['can_choose_plan']);
        $this->assertSame('Silver', $options['upgrades']->first()['name']);
    }

    public function test_upgrade_invoice_uses_selected_billing_cycle(): void
    {
        ['node' => $node, 'bronze' => $bronze, 'silver' => $silver] = $this->createHostingStack();

        $bronze->update(['monthly_price' => 1000, 'yearly_price' => 10000]);
        $silver->update(['monthly_price' => 2000, 'yearly_price' => 20000]);

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'client1',
            'next_due_date' => now()->addMonths(6),
        ]);

        $this->mock(NotificationService::class)
            ->shouldReceive('notifyInvoiceGenerated')
            ->once();

        $invoice = app(CustomerHostingUpgradeService::class)->createUpgradeInvoice(
            $service,
            $customer,
            $silver,
            null,
            'monthly',
        );

        $item = $invoice->items()->first();
        $this->assertSame('monthly', $item->custom_options['to_billing_cycle']);
        $this->assertStringContainsString('Monthly', $item->description);
        $this->assertTrue($item->custom_options['pricing_summary']['is_prorated'] ?? false);
        $this->assertGreaterThan(0, $item->custom_options['pricing_summary']['prorated_subtotal'] ?? 0);
        $this->assertStringContainsString('Prorated upgrade', $item->description);
    }

    public function test_upgrade_lists_plans_on_node_when_product_is_matched_by_package_name(): void
    {
        ['node' => $node, 'bronze' => $bronze] = $this->createHostingStack();

        $silverPackage = DirectAdminPackage::create([
            'name' => 'Silver',
            'package_key' => 'silver',
            'node_id' => $node->id,
            'disk_quota' => 50,
            'bandwidth_quota' => 500,
            'num_databases' => 10,
            'is_active' => true,
        ]);

        $silver = Product::create([
            'name' => 'Silver',
            'slug' => 'silver-unlinked-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 2000,
            'provisioning_driver_key' => 'directadmin',
            'direct_admin_package_id' => null,
            'order' => 2,
            'is_active' => true,
        ]);

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'client1',
        ]);

        $options = app(CustomerHostingUpgradeService::class)->planChangeOptions($service, $customer);

        $this->assertTrue($options->contains(fn (array $option) => $option['product']->id === $silver->id));
        $this->assertSame(50.0, (float) $options->first(fn (array $option) => $option['product']->id === $silver->id)['disk_quota']);
    }

    public function test_plan_change_options_use_product_directadmin_node_when_service_node_missing(): void
    {
        ['node' => $node, 'bronze' => $bronze, 'silver' => $silver] = $this->createHostingStack();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronze->id,
            'node_id' => null,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'client1',
        ]);

        $options = app(CustomerHostingUpgradeService::class)
            ->planChangeOptions($service, $customer);

        $this->assertTrue($options->contains(fn (array $option) => $option['product']->id === $silver->id));
    }
}
