<?php

namespace Tests\Feature\Customer;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Hosting\CustomerHostingPanelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostingPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_open_hosting_panel_login_redirect(): void
    {
        $this->mock(CustomerHostingPanelService::class, function ($mock) {
            $mock->shouldReceive('createPanelLoginUrl')
                ->once()
                ->andReturn(['success' => true, 'url' => 'https://da.example.com:2222/api/login/url?username=u&key=k']);
        });

        $customer = User::factory()->create();
        $service = $this->createDirectAdminService($customer);

        $response = $this->actingAs($customer)->get(route('customer.services.hosting.panel-login', $service));

        $response->assertRedirect('https://da.example.com:2222/api/login/url?username=u&key=k');
    }

    public function test_customer_cannot_access_hosting_panel_for_other_users_service(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = $this->createDirectAdminService($owner);

        $response = $this->actingAs($other)->getJson(route('customer.services.hosting.dashboard', $service));

        $response->assertForbidden();
    }

    private function createDirectAdminService(User $customer): Service
    {
        $node = Node::factory()->create([
            'type' => 'directadmin',
            'hostname' => 'da.example.com',
            'api_url' => 'https://da.example.com:2222',
            'da_login_key' => 'secret',
        ]);

        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        return Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'siteuser',
            'service_meta' => [
                'username' => 'siteuser',
                'password' => 'secret',
                'domain' => 'example.com',
            ],
        ]);
    }
}
