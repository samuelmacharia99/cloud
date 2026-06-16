<?php

namespace Tests\Unit\Models;

use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceDirectAdminPanelUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_hosting_panel_url_uses_customer_domain_on_port_2222(): void
    {
        $node = Node::create([
            'name' => 'DA Node',
            'hostname' => 'da.server.example.com',
            'type' => 'directadmin',
            'da_port' => '2222',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        $service = Service::create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'name' => 'Starter Hosting',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => 'directadmin',
            'service_meta' => [
                'domain' => 'client.example.com',
                'username' => 'clientex',
                'password' => 'secret',
            ],
        ]);

        $this->assertSame('https://client.example.com:2222', $service->getDirectAdminPanelUrl());
        $this->assertSame('https://client.example.com:2222', $service->getHostingCredentials()['panel_url']);
    }

    public function test_shared_hosting_without_domain_falls_back_to_node_hostname(): void
    {
        $node = Node::create([
            'name' => 'DA Node',
            'hostname' => 'da.server.example.com',
            'type' => 'directadmin',
            'da_port' => '2222',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        $service = Service::create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'name' => 'Starter Hosting',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'provisioning_driver_key' => 'directadmin',
            'service_meta' => [
                'username' => 'clientex',
                'password' => 'secret',
            ],
        ]);

        $this->assertSame('https://da.server.example.com:2222', $service->getDirectAdminPanelUrl());
    }
}
