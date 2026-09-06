<?php

namespace Tests\Unit\Provisioning;

use App\Enums\DaConvertBatchItemStatus;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\DaConvertBatch;
use App\Models\DaConvertBatchItem;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\Provisioning\ContainerDomainBindingService;
use App\Services\Provisioning\DaAccountSnapshotService;
use App\Services\Provisioning\DaConvertOfframpService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use App\Services\Provisioning\NginxProxyService;
use App\Services\ResellerScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DaConvertOfframpServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_records_external_dns_checklist_and_cloudflare_flag(): void
    {
        [$item, $binding, $cloudflare, $nginx] = $this->convertedItem();

        $cloudflare->shouldReceive('resolvePlatformDomainForHostname')->andReturn(null);
        $nginx->shouldReceive('checkDns')->with('shop.example.com', '203.0.113.10')->andReturn(false);
        $binding->shouldReceive('bindHostnamePair')->once()->andReturn([]);

        $offramp = $this->offramp($binding, $cloudflare, $nginx);

        $refreshed = $offramp->refreshCutoverStatus($item->fresh('service') ?? $item);
        $this->assertFalse($refreshed->dns_ok);
        $this->assertFalse($refreshed->dns_managed);
        $this->assertSame('203.0.113.10', $refreshed->target_ip);
        $this->assertStringContainsString('203.0.113.10', (string) ($refreshed->cutover_notes['instruction'] ?? ''));
        $this->assertStringContainsString('www.shop.example.com', (string) ($refreshed->cutover_notes['www'] ?? ''));

        $result = $offramp->cutWebDns($item->batch()->first() ?? $item->batch, [$item->id]);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['skipped']);
    }

    #[Test]
    public function it_flags_talksasa_cloudflare_domains_as_managed(): void
    {
        [$item, $binding, $cloudflare, $nginx] = $this->convertedItem();

        $cloudflare->shouldReceive('resolvePlatformDomainForHostname')
            ->andReturn(new Domain(['domain_name' => 'example.com']));
        $nginx->shouldReceive('checkDns')->andReturn(false);

        $refreshed = $this->offramp($binding, $cloudflare, $nginx)
            ->refreshCutoverStatus($item->fresh('service') ?? $item);

        $this->assertTrue($refreshed->dns_managed);
        $this->assertStringContainsString('Cut web DNS', (string) ($refreshed->cutover_notes['instruction'] ?? ''));
    }

    #[Test]
    public function it_marks_dns_ok_when_public_a_record_matches_the_container_host(): void
    {
        [$item, $binding, $cloudflare, $nginx] = $this->convertedItem(sslEnabled: true);

        $cloudflare->shouldReceive('resolvePlatformDomainForHostname')->andReturn(null);
        $nginx->shouldReceive('checkDns')->with('shop.example.com', '203.0.113.10')->andReturn(true);

        $refreshed = $this->offramp($binding, $cloudflare, $nginx)
            ->refreshCutoverStatus($item->fresh(['service.containerDeployment.domains']) ?? $item);

        $this->assertTrue($refreshed->dns_ok);
        $this->assertTrue($refreshed->ssl_ok);
        $this->assertSame(DaConvertBatchItemStatus::Done, $refreshed->status);
    }

    #[Test]
    public function convert_job_serializes_per_directadmin_node(): void
    {
        $node = Node::factory()->directAdmin()->create();
        $service = Service::factory()->create(['node_id' => $node->id]);
        $job = new ConvertDirectAdminServiceToContainerJob((int) $service->id, 1);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('da-convert-node-'.$node->id, $middleware[0]->key);
    }

    /**
     * @return array{0: DaConvertBatchItem, 1: ContainerDomainBindingService, 2: DomainCloudflareDnsService, 3: NginxProxyService}
     */
    private function convertedItem(bool $sslEnabled = false): array
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);
        $containerHost = Node::factory()->containerHost()->create(['ip_address' => '203.0.113.10']);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'name' => 'shop.example.com',
            'service_meta' => ['domain' => 'shop.example.com'],
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $containerHost->id,
            'status' => 'running',
            'domain' => 'shop.example.com',
        ]);
        ContainerDomain::query()->create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'shop.example.com',
            'status' => 'active',
            'ssl_enabled' => $sslEnabled,
        ]);

        $batch = DaConvertBatch::query()->create([
            'reseller_user_id' => User::factory()->reseller()->create()->id,
            'admin_user_id' => User::factory()->admin()->create()->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => 'ready_for_cutover',
        ]);
        $item = DaConvertBatchItem::query()->create([
            'da_convert_batch_id' => $batch->id,
            'service_id' => $service->id,
            'status' => 'waiting_dns',
            'mailbox_count' => 0,
            'hostname' => 'shop.example.com',
        ]);

        $binding = Mockery::mock(ContainerDomainBindingService::class);
        $binding->shouldReceive('resolvePrimaryHostname')->andReturn('shop.example.com');

        return [
            $item,
            $binding,
            Mockery::mock(DomainCloudflareDnsService::class),
            Mockery::mock(NginxProxyService::class),
        ];
    }

    private function offramp(
        ContainerDomainBindingService $binding,
        DomainCloudflareDnsService $cloudflare,
        NginxProxyService $nginx,
    ): DaConvertOfframpService {
        return new DaConvertOfframpService(
            Mockery::mock(DirectAdminToContainerConvertService::class),
            app(ResellerScopeService::class),
            $binding,
            $cloudflare,
            $nginx,
            Mockery::mock(DaAccountSnapshotService::class),
        );
    }
}
