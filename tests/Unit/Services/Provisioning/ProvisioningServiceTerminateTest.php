<?php

namespace Tests\Unit\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProvisioningServiceTerminateTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminate_skips_directadmin_api_when_account_missing(): void
    {
        Notification::fake();

        Http::fake([
            '*' => Http::response('error=1&text=User+does+not+exist', 200),
        ]);

        $node = Node::factory()->create([
            'type' => 'directadmin',
            'api_url' => 'https://lani.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'da_port' => '2222',
            'verify_ssl' => false,
        ]);

        $product = Product::create([
            'name' => 'Shared Hosting',
            'slug' => 'shared-hosting-'.uniqid(),
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
            'monthly_price' => 500,
            'yearly_price' => 5000,
            'is_active' => true,
        ]);

        $customer = User::factory()->customer()->create();

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'external_reference' => 'devkiste',
            'provisioning_driver_key' => 'directadmin',
            'status' => ServiceStatus::Suspended,
            'service_meta' => ['username' => 'devkiste'],
            'live_status' => 'terminated',
            'live_status_label' => 'Account not found on DirectAdmin',
        ]);

        app(ProvisioningService::class)->terminate($service);

        $this->assertSame('terminated', $service->fresh()->status->value);

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'CMD_API_ACCOUNT_USER');
        });
    }
}
