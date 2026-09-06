<?php

namespace Tests\Unit\Provisioning;

use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use App\Services\Provisioning\DaAccountSnapshotService;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class DaAccountSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_dns_and_account_data_on_the_platform(): void
    {
        $service = $this->daService();
        $this->bindInventory($service);

        $api = Mockery::mock(DirectAdminCustomerPanelApi::class);
        $api->shouldReceive('listDnsRecords')->once()->with('da-shop', 'shop.example.com')->andReturn([
            'success' => true,
            'message' => 'OK',
            'data' => [
                ['name' => '@', 'type' => 'A', 'value' => '198.51.100.10', 'ttl' => 14400],
                ['name' => 'www', 'type' => 'CNAME', 'value' => 'shop.example.com.', 'ttl' => 14400],
                ['name' => '@', 'type' => 'MX', 'value' => '10 mail.shop.example.com.', 'ttl' => 3600],
                ['name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 mx -all', 'ttl' => 3600],
            ],
        ]);
        $api->shouldReceive('listEmailAccounts')->andReturn([
            'success' => true,
            'data' => [['account' => 'info', 'email' => 'info@shop.example.com']],
            'message' => 'OK',
        ]);
        $api->shouldReceive('listSubdomains')->andReturn([
            'success' => true,
            'data' => [['subdomain' => 'blog', 'fqdn' => 'blog.shop.example.com']],
            'message' => 'OK',
        ]);
        $api->shouldReceive('listFtpAccounts')->andReturn([
            'success' => true,
            'data' => [['account' => 'ftp-shop']],
            'message' => 'OK',
        ]);
        $api->shouldReceive('getSslInfo')->andReturn([
            'success' => true,
            'data' => ['ssl_on' => true, 'letsencrypt' => true],
            'message' => 'OK',
        ]);

        $snapshot = app(DaAccountSnapshotService::class)->capture($service, $api);

        $this->assertTrue($snapshot->isCaptured());
        $this->assertSame(4, $snapshot->dns_record_count);
        $this->assertSame(1, $snapshot->mailbox_count);
        $this->assertSame(1, $snapshot->database_count);
        $this->assertSame($snapshot->id, $service->fresh()->service_meta['da_snapshot']['id'] ?? null);

        $domain = Domain::query()->where('name', 'shop.example')->where('extension', '.com')->first();
        $this->assertNotNull($domain);
        $this->assertSame('dns', $domain->type);
        $this->assertSame($service->user_id, $domain->user_id);

        $this->assertSame(4, DnsRecord::query()->count());
        $this->assertTrue(DnsRecord::query()->where('type', 'A')->where('content', '198.51.100.10')->exists());
        $mx = DnsRecord::query()->where('type', 'MX')->first();
        $this->assertSame(10, $mx?->priority);
        $this->assertSame('mail.shop.example.com.', $mx?->content);
    }

    #[Test]
    public function it_refuses_to_proceed_when_dns_cannot_be_read(): void
    {
        $service = $this->daService();
        $this->bindInventory($service);

        $api = Mockery::mock(DirectAdminCustomerPanelApi::class);
        $api->shouldReceive('listDnsRecords')->andReturn([
            'success' => false,
            'message' => 'CMD_API_DNS_CONTROL denied',
            'data' => [],
        ]);
        $api->shouldReceive('listEmailAccounts')->andReturn(['success' => true, 'data' => [], 'message' => 'OK']);
        $api->shouldReceive('listSubdomains')->andReturn(['success' => true, 'data' => [], 'message' => 'OK']);
        $api->shouldReceive('listFtpAccounts')->andReturn(['success' => true, 'data' => [], 'message' => 'OK']);
        $api->shouldReceive('getSslInfo')->andReturn(['success' => true, 'data' => [], 'message' => 'OK']);

        $snapshot = app(DaAccountSnapshotService::class)->capture($service, $api);
        $this->assertFalse($snapshot->isCaptured());
        $this->assertSame('failed', $snapshot->status);
        $this->assertSame(0, DnsRecord::query()->count());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CMD_API_DNS_CONTROL denied');
        app(DaAccountSnapshotService::class)->captureOrFail($service, $api);
    }

    #[Test]
    public function it_captures_the_parent_zone_when_subdomain_folders_are_not_da_zones(): void
    {
        $service = $this->daService();
        $migrator = Mockery::mock(DirectAdminToContainerMigrationService::class);
        $migrator->shouldReceive('inventory')->andReturn([
            'username' => 'da-shop',
            'domain' => 'shop.example.com',
            'databases' => [],
            'sites' => [
                ['domain' => 'shop.example.com', 'is_primary' => true, 'stack' => 'wordpress'],
                ['domain' => 'api.shop.example.com', 'is_primary' => false, 'stack' => 'nodejs'],
                ['domain' => 'booking.shop.example.com', 'is_primary' => false, 'stack' => 'static_or_php'],
            ],
            'account' => ['nameservers' => []],
        ]);
        $this->app->instance(DirectAdminToContainerMigrationService::class, $migrator);

        $api = Mockery::mock(DirectAdminCustomerPanelApi::class);
        $api->shouldReceive('listDnsRecords')->with('da-shop', 'shop.example.com')->andReturn([
            'success' => true,
            'message' => 'OK',
            'data' => [
                ['name' => '@', 'type' => 'A', 'value' => '198.51.100.10', 'ttl' => 14400],
                ['name' => 'api', 'type' => 'A', 'value' => '198.51.100.11', 'ttl' => 14400],
            ],
        ]);
        $api->shouldReceive('listDnsRecords')->with('da-shop', 'api.shop.example.com')->andReturn([
            'success' => false,
            'message' => 'DirectAdmin API HTTP 500: { "error": "Cannot View Dns Record", "result": "Domain does not belong to you" }',
            'data' => [],
        ]);
        $api->shouldReceive('listDnsRecords')->with('da-shop', 'booking.shop.example.com')->andReturn([
            'success' => false,
            'message' => 'DirectAdmin API HTTP 500: { "error": "Cannot View Dns Record", "result": "Domain does not belong to you" }',
            'data' => [],
        ]);
        $api->shouldReceive('listEmailAccounts')->andReturn(['success' => true, 'data' => [], 'message' => 'OK']);
        $api->shouldReceive('listSubdomains')->andReturn(['success' => true, 'data' => [], 'message' => 'OK']);
        $api->shouldReceive('listFtpAccounts')->andReturn(['success' => true, 'data' => [], 'message' => 'OK']);
        $api->shouldReceive('getSslInfo')->andReturn(['success' => true, 'data' => [], 'message' => 'OK']);

        $snapshot = app(DaAccountSnapshotService::class)->captureOrFail($service, $api);

        $this->assertTrue($snapshot->isCaptured());
        $this->assertSame(2, $snapshot->dns_record_count);
        $this->assertTrue(DnsRecord::query()->where('type', 'A')->where('content', '198.51.100.10')->exists());
        $this->assertSame(1, Domain::query()->count());
        $unowned = collect($snapshot->payload['zones'] ?? [])->where('dns_unowned', true)->pluck('hostname')->all();
        $this->assertEqualsCanonicalizing(['api.shop.example.com', 'booking.shop.example.com'], $unowned);
    }

    private function daService(): Service
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);
        $node = Node::factory()->directAdmin()->create();

        return Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $customer->reseller_id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'provisioning_driver_key' => 'directadmin',
            'status' => 'active',
            'name' => 'shop.example.com',
            'external_reference' => 'da-shop',
            'service_meta' => ['username' => 'da-shop', 'domain' => 'shop.example.com'],
        ]);
    }

    private function bindInventory(Service $service): void
    {
        $migrator = Mockery::mock(DirectAdminToContainerMigrationService::class);
        $migrator->shouldReceive('inventory')->andReturn([
            'username' => 'da-shop',
            'domain' => 'shop.example.com',
            'databases' => [['name' => 'da-shop_wp']],
            'sites' => [[
                'domain' => 'shop.example.com',
                'is_primary' => true,
                'stack' => 'wordpress',
            ]],
            'account' => ['nameservers' => ['ns1.example.com', 'ns2.example.com']],
        ]);
        $this->app->instance(DirectAdminToContainerMigrationService::class, $migrator);
    }
}
