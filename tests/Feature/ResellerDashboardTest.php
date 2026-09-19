<?php

namespace Tests\Feature;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ResellerScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    /**
     * The dashboard used to run two extra queries for every container the
     * reseller sold — one for the newest disk metric and one for the transfer
     * counters — so a reseller with a couple of hundred applications loaded
     * their own dashboard on several hundred queries. Both are batched now, so
     * the cost is flat.
     */
    public function test_dashboard_query_count_does_not_grow_with_container_count(): void
    {
        $counts = [];

        foreach ([1, 12] as $containers) {
            // Unique package name: the shared helper hardcodes one, and this
            // test builds two resellers.
            $reseller = $this->createResellerWithPackage(['name' => 'Pool '.uniqid()]);
            $this->seedContainerServices($reseller, $containers);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($reseller)->get('/dashboard')->assertOk();
            $counts[$containers] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $this->assertSame(
            $counts[1],
            $counts[12],
            'Dashboard queries scaled with container count: '.json_encode($counts),
        );
    }

    /**
     * A reseller whose DirectAdmin binding is missing or broken is exactly the
     * one who most needs their dashboard to load. Sizing their accounts costs
     * two HTTP calls each and belongs to the nightly collector, never a render.
     */
    public function test_dashboard_never_calls_directadmin(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $reseller = $this->createResellerWithPackage();
        $customer = $this->createManagedCustomer($reseller);

        $product = Product::create([
            'name' => 'Shared Hosting',
            'slug' => 'shared-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 5,
            'yearly_price' => 50,
            'is_active' => true,
            'provisioning_driver_key' => 'directadmin',
        ]);

        Service::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'reseller_id' => $reseller->id,
            'name' => 'da-service',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'dauser',
        ]);

        $this->actingAs($reseller)->get('/dashboard')->assertOk();

        Http::assertNothingSent();
    }

    private function seedContainerServices(User $reseller, int $count): void
    {
        $product = Product::create([
            'name' => 'App Hosting',
            'slug' => 'app-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 10,
            'yearly_price' => 100,
            'is_active' => true,
            'provisioning_driver_key' => 'container',
        ]);

        for ($i = 0; $i < $count; $i++) {
            $customer = $this->createManagedCustomer($reseller);

            $service = Service::create([
                'user_id' => $customer->id,
                'product_id' => $product->id,
                'reseller_id' => $reseller->id,
                'name' => "app-{$i}",
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'next_due_date' => now()->addMonth(),
                'provisioning_driver_key' => 'container',
            ]);

            $deployment = ContainerDeployment::create([
                'service_id' => $service->id,
                'container_name' => 'c'.uniqid(),
                'status' => 'running',
                'assigned_port' => 30000 + $i + random_int(0, 100000),
            ]);

            ContainerMetric::create([
                'container_deployment_id' => $deployment->id,
                'disk_used_gb' => 1.5,
                'recorded_at' => now(),
            ]);
        }
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
        $response->assertSee('Server pulse');
    }

    public function test_reseller_dashboard_shows_disk_pool_in_gigabytes(): void
    {
        $reseller = $this->createResellerWithPackage([
            'max_services' => 10,
            'disk_pool_gb' => 100,
            'storage_space' => 100,
        ]);

        $this->actingAs($reseller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('0.00 / 100 GB')
            ->assertSee('100.0 GB remaining')
            ->assertSee('DirectAdmin 0.0 GB')
            ->assertSee('Containers 0.0 GB');
    }

    public function test_reseller_dashboard_shows_container_disk_against_the_package_pool(): void
    {
        $reseller = $this->createResellerWithPackage([
            'max_services' => 25,
            'disk_pool_gb' => 50,
            'storage_space' => 50,
        ]);
        $customer = $this->createManagedCustomer($reseller);
        $product = Product::factory()->containerHosting()->create([
            'resource_limits' => ['cpu' => 2, 'memory' => 4096, 'disk' => 20, 'bandwidth_gb' => 100],
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
        ]);
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'disk_used_gb' => 12.4,
            'cpu_percentage' => 10,
            'memory_used_mb' => 512,
            'recorded_at' => now(),
        ]);

        $this->actingAs($reseller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('12.4 / 50 GB')
            ->assertSee('37.6 GB remaining')
            ->assertSee('Containers 12.4 GB')
            ->assertSee('12.4 GB of 50 GB pool');
    }

    public function test_reseller_directadmin_panel_endpoint_returns_json(): void
    {
        $reseller = $this->createResellerWithPackage();

        $response = $this->actingAs($reseller)->getJson(route('reseller.dashboard.directadmin-panel'));

        $response->assertOk();
        $response->assertJsonStructure([
            'is_connected',
            'api_reachable',
            'hosted_user_count',
            'disk_used_gb',
            'disk_pool_gb',
            'disk_pool_percent',
            'payments_today',
            'payments_7d',
            'payments_30d',
            'chart' => ['labels', 'payments', 'disk_gb', 'hosted_users'],
            'updated_at',
        ]);
    }

    public function test_customer_cannot_access_reseller_directadmin_panel_endpoint(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->getJson(route('reseller.dashboard.directadmin-panel'))
            ->assertForbidden();
    }

    public function test_reseller_directadmin_live_endpoint_returns_json(): void
    {
        $reseller = $this->createResellerWithPackage();

        $response = $this->actingAs($reseller)->getJson(route('reseller.dashboard.directadmin-live'));

        $response->assertOk();
        $response->assertJsonStructure([
            'is_connected',
            'api_reachable',
            'hosted_user_count',
            'disk_used_gb',
            'disk_pool_gb',
            'disk_pool_percent',
            'payments_today',
            'updated_at',
        ]);
    }

    public function test_reseller_dashboard_activity_endpoint_returns_paginated_feed(): void
    {
        $reseller = $this->createResellerWithPackage();
        $customer = $this->createManagedCustomer($reseller, [
            'name' => 'Activity Customer',
        ]);

        foreach (range(1, 12) as $index) {
            Invoice::factory()->create([
                'user_id' => $customer->id,
                'status' => 'paid',
                'total' => 1000 + $index,
                'created_at' => now()->subMinutes($index),
            ]);
        }

        $firstPage = $this->actingAs($reseller)->getJson(route('reseller.dashboard.activity', ['offset' => 0]));
        $firstPage->assertOk();
        $firstPage->assertJsonCount(10, 'items');
        $firstPage->assertJsonPath('has_more', true);
        $firstPage->assertJsonPath('next_offset', 10);
        $firstPage->assertJsonPath('items.0.customer_name', 'Activity Customer');
        $firstPage->assertJsonPath('items.0.customer_url', route('reseller.customers.show', $customer));

        $secondPage = $this->actingAs($reseller)->getJson(route('reseller.dashboard.activity', ['offset' => 10]));
        $secondPage->assertOk();
        $secondPage->assertJsonPath('has_more', false);
        $this->assertGreaterThanOrEqual(2, count($secondPage->json('items')));
    }

    public function test_customer_cannot_access_reseller_dashboard_activity_endpoint(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->getJson(route('reseller.dashboard.activity'))
            ->assertForbidden();
    }

    public function test_customer_cannot_access_reseller_directadmin_live_endpoint(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->getJson(route('reseller.dashboard.directadmin-live'))
            ->assertForbidden();
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
