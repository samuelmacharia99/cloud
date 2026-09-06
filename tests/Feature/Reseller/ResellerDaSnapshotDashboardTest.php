<?php

namespace Tests\Feature\Reseller;

use App\Models\DaAccountSnapshot;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerDaSnapshotDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_sees_captured_dns_and_account_data_without_directadmin(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'directadmin',
            'status' => 'active',
            'name' => 'shop.example.com',
        ]);
        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'shop.example',
            'extension' => '.com',
            'type' => 'dns',
            'status' => 'active',
            'registrar' => 'external',
            'auto_renew' => false,
        ]);
        $zone = DnsZone::query()->create([
            'domain_id' => $domain->id,
            'service_id' => $service->id,
            'name' => 'shop.example.com',
            'status' => 'active',
            'provider' => 'directadmin_import',
        ]);
        DnsRecord::query()->create([
            'dns_zone_id' => $zone->id,
            'name' => '@',
            'type' => 'A',
            'content' => '203.0.113.20',
            'ttl' => 14400,
        ]);
        DaAccountSnapshot::query()->create([
            'service_id' => $service->id,
            'username' => 'da-shop',
            'primary_domain' => 'shop.example.com',
            'site_count' => 1,
            'database_count' => 2,
            'mailbox_count' => 3,
            'ftp_count' => 1,
            'dns_record_count' => 1,
            'dns_imported' => true,
            'status' => 'captured',
            'payload' => ['zones' => []],
        ]);

        $this->actingAs($reseller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Hosting on Talksasa')
            ->assertSee('shop.example.com')
            ->assertSee('1 DNS');

        $this->actingAs($reseller)
            ->get(route('reseller.services.show', $service))
            ->assertOk()
            ->assertSee('Captured from DirectAdmin')
            ->assertSee('3')
            ->assertSee('DNS records');

        $this->actingAs($reseller)
            ->get(route('reseller.domains.show', ['domain' => $domain, 'tab' => 'dns']))
            ->assertOk()
            ->assertSee('203.0.113.20')
            ->assertSee('imported from DirectAdmin');
    }

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
            'disk_pool_gb' => 100,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }
}
