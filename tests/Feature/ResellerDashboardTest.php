<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ResellerScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function createResellerWithPackage(array $packageOverrides = [], array $resellerOverrides = []): User
    {
        $package = ResellerPackage::create(array_merge([
            'name' => 'Starter',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 10,
            'price' => 1000,
            'active' => true,
        ], $packageOverrides));

        return User::factory()->reseller()->create(array_merge([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now(),
            'package_expires_at' => now()->addMonth(),
        ], $resellerOverrides));
    }

    private function createManagedCustomer(User $reseller, array $overrides = []): User
    {
        return User::factory()->customer()->create(array_merge([
            'reseller_id' => $reseller->id,
        ], $overrides));
    }

    private function createProduct(): Product
    {
        return Product::create([
            'name' => 'Test Hosting',
            'slug' => 'test-hosting-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 9.99,
            'yearly_price' => 99.99,
            'is_active' => true,
        ]);
    }

    private function createManagedService(User $reseller, User $customer, ?User $serviceReseller = null): Service
    {
        return Service::create([
            'user_id' => $customer->id,
            'product_id' => $this->createProduct()->id,
            'reseller_id' => $serviceReseller?->id ?? $reseller->id,
            'name' => 'Managed Service',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
        ]);
    }

    public function test_reseller_dashboard_shows_analytics_for_managed_customers(): void
    {
        $reseller = $this->createResellerWithPackage([], ['commission_rate' => 25]);
        $customer = $this->createManagedCustomer($reseller);

        Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
            'total' => 1000,
        ]);

        $response = $this->actingAs($reseller)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Invoice breakdown');
        $response->assertSee('Margins (30d)');
        $response->assertSee('Whitelabel dashboard');
    }

    public function test_reseller_can_view_managed_service_but_not_unrelated_service(): void
    {
        $reseller = $this->createResellerWithPackage();
        $otherReseller = $this->createResellerWithPackage(['name' => 'Other']);
        $customer = $this->createManagedCustomer($reseller);
        $stranger = User::factory()->customer()->create();

        $managedService = $this->createManagedService($reseller, $customer);

        $foreignService = $this->createManagedService($otherReseller, $stranger, $otherReseller);

        $this->actingAs($reseller)
            ->get(route('reseller.services.show', $managedService))
            ->assertOk();

        $this->actingAs($reseller)
            ->get(route('reseller.services.show', $foreignService))
            ->assertNotFound();
    }

    public function test_reseller_customer_invoice_access_is_scoped(): void
    {
        $reseller = $this->createResellerWithPackage();
        $customer = $this->createManagedCustomer($reseller);
        $stranger = User::factory()->customer()->create();

        $managedInvoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
        ]);
        $foreignInvoice = Invoice::factory()->create([
            'user_id' => $stranger->id,
            'status' => 'unpaid',
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.customer-invoices.show', $managedInvoice))
            ->assertOk();

        $this->actingAs($reseller)
            ->get(route('reseller.customer-invoices.show', $foreignInvoice))
            ->assertNotFound();
    }

    public function test_reseller_limits_middleware_redirects_when_over_user_limit(): void
    {
        $reseller = $this->createResellerWithPackage(['max_users' => 1]);
        $this->createManagedCustomer($reseller);

        $response = $this->actingAs($reseller)->get(route('reseller.customers.create'));

        $response->assertRedirect(route('reseller.packages.index'));
        $response->assertSessionHas('limit_exceeded', true);
    }

    public function test_reseller_tickets_index_only_shows_managed_customer_tickets(): void
    {
        $reseller = $this->createResellerWithPackage();
        $customer = $this->createManagedCustomer($reseller);
        $stranger = User::factory()->customer()->create();

        $managedTicket = Ticket::create([
            'user_id' => $customer->id,
            'title' => 'Managed customer ticket',
            'description' => 'Help needed',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $foreignTicket = Ticket::create([
            'user_id' => $stranger->id,
            'title' => 'Foreign ticket',
            'description' => 'Should not appear',
            'status' => 'open',
            'priority' => 'low',
        ]);

        $response = $this->actingAs($reseller)->get(route('reseller.tickets.index'));

        $response->assertOk();
        $response->assertSee($managedTicket->title);
        $response->assertDontSee($foreignTicket->title);
    }

    public function test_reseller_scope_service_uses_reseller_id_on_customer(): void
    {
        $reseller = $this->createResellerWithPackage();
        $customer = $this->createManagedCustomer($reseller);
        $scope = app(ResellerScopeService::class);

        $this->assertTrue($scope->ownsCustomer($reseller, $customer));
        $this->assertSame(1, $scope->managedCustomerCount($reseller));
        $this->assertSame([$customer->id], $scope->managedCustomerIds($reseller));
    }
}
