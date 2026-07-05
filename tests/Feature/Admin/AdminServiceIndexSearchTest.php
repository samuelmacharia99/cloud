<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Domain;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceIndexSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_services_by_service_meta_domain(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create(['name' => 'Unrelated Customer']);

        $matching = Service::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Shared Hosting Plan',
            'service_meta' => ['domain' => 'acme.co.ke'],
        ]);

        $other = Service::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Other Hosting',
            'service_meta' => ['domain' => 'other.com'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.services.index', ['search' => 'acme.co.ke']))
            ->assertOk()
            ->assertSee('#'.$matching->id, false)
            ->assertDontSee('#'.$other->id, false);
    }

    public function test_admin_can_search_services_by_linked_domain_record(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'linkeddomain',
            'extension' => '.com',
            'status' => 'active',
        ]);

        $matching = Service::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Linked Domain Hosting',
            'service_meta' => ['domain_id' => $domain->id],
        ]);

        $other = Service::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Different Hosting',
            'service_meta' => ['domain_id' => Domain::create([
                'user_id' => $customer->id,
                'name' => 'another',
                'extension' => '.net',
                'status' => 'active',
            ])->id],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.services.index', ['search' => 'linkeddomain.com']))
            ->assertOk()
            ->assertSee('#'.$matching->id, false)
            ->assertDontSee('#'.$other->id, false);
    }

    public function test_admin_can_search_services_by_container_domain(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();

        $matching = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Container App',
            'provisioning_driver_key' => 'container',
        ]);

        $deployment = ContainerDeployment::create([
            'service_id' => $matching->id,
            'container_name' => 'app-'.$matching->id,
            'status' => 'running',
            'domain' => 'app.example.org',
        ]);

        ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'custom.example.org',
            'status' => 'active',
        ]);

        $other = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Other Container',
            'provisioning_driver_key' => 'container',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.services.index', ['search' => 'custom.example.org']))
            ->assertOk()
            ->assertSee('#'.$matching->id, false)
            ->assertDontSee('#'.$other->id, false);
    }
}
