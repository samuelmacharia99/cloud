<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\DaAccountSnapshot;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ServiceRenewalPricingService;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerDeployResult;
use App\Services\Provisioning\ContainerDomainBindingService;
use App\Services\Provisioning\ContainerGoLiveService;
use App\Services\Provisioning\DaAccountSnapshotService;
use App\Services\Provisioning\DaConvertProgress;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The whole convert with every remote collaborator mocked: proves the order
 * (site up before mail) and that a mail failure leaves the site converted.
 */
class DirectAdminConvertOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_goes_live_before_mail_and_a_mail_failure_does_not_roll_back(): void
    {
        Http::fake();
        [$service, $containerProduct, $emailProduct, $daProduct] = $this->fixture();
        $order = [];

        $migrator = Mockery::mock(DirectAdminToContainerMigrationService::class);
        $migrator->shouldReceive('inventory')->andReturn($this->inventory());
        $migrator->shouldReceive('stackMayExportDatabase')->andReturn(false);
        $migrator->shouldReceive('exportSiteFromDirectAdmin')->once()->andReturnUsing(function () use (&$order) {
            $order[] = 'export';

            return ['local_dump' => null, 'local_tar' => '/tmp/not-there.tar.gz', 'remote_work' => '/opt/talksasa/da-migrations/x', 'stack' => 'static_or_php', 'files_export_empty' => false];
        });
        $migrator->shouldReceive('importSiteIntoContainer')->once()->andReturnUsing(function () use (&$order) {
            $order[] = 'import';
        });
        $migrator->shouldReceive('recordExternalProgress')->once();

        $deployments = Mockery::mock(ContainerDeploymentService::class);
        $deployments->shouldReceive('assertHostHasCapacity')->once();
        $deployments->shouldReceive('deploy')->once()->andReturnUsing(function (Service $target) use (&$order) {
            $order[] = 'deploy';
            ContainerDeployment::factory()->create(['service_id' => $target->id, 'node_id' => $target->node_id ?? Node::query()->where('type', 'container_host')->value('id'), 'status' => 'running']);
            $target->update(['status' => 'active']);

            return new ContainerDeployResult(false, null);
        });

        $mail = Mockery::mock(DirectAdminToMailcowMigrationService::class);
        $mail->shouldReceive('listMailboxesByDomains')->andReturn([
            'all' => [['account' => 'info', 'email' => 'info@example.com', 'domain' => 'example.com']],
            'by_domain' => ['example.com' => [['account' => 'info', 'email' => 'info@example.com', 'domain' => 'example.com']]],
            'errors' => [],
            'ssh_scanned' => true,
        ]);
        $mail->shouldReceive('pullFromDirectAdminUser')->once()->andReturnUsing(function () use (&$order) {
            $order[] = 'mail';

            return ['success' => false, 'message' => 'Mailcow node "Mail-1" SSH login failed at 2.28.0.13'];
        });

        $snapshots = Mockery::mock(DaAccountSnapshotService::class);
        $snapshot = new DaAccountSnapshot;
        $snapshot->id = 77;
        $snapshots->shouldReceive('captureOrFail')->andReturn($snapshot);

        $pricing = Mockery::mock(ServiceRenewalPricingService::class);
        $pricing->shouldReceive('unitPrice')->andReturn(2500.0);

        $goLive = Mockery::mock(ContainerGoLiveService::class);
        $goLive->shouldReceive('goLive')->once()->andReturnUsing(function (Service $s, string $host, callable $step) use (&$order) {
            $order[] = 'go-live';
            $step('DNS for '.$host.' is managed by Talksasa (zone example.com): A records for '.$host.' and www.'.$host.' now point at 203.0.113.10');
            $step('https://'.$host.' is live');

            return ['managed' => true, 'hosts' => [$host, 'www.'.$host], 'resolved' => [$host], 'ssl' => [$host => true], 'node_ip' => '203.0.113.10'];
        });

        $this->mock(ContainerDomainBindingService::class, function (MockInterface $binding) use (&$order) {
            $binding->shouldReceive('bindHostnamePair')->once()->andReturnUsing(function () use (&$order) {
                $order[] = 'bind';

                return [];
            });
        });

        $convert = new DirectAdminToContainerConvertService($migrator, $deployments, $pricing, $mail, $snapshots, app(DaConvertProgress::class), $goLive);

        $result = $convert->convertInPlace($service, $containerProduct, acknowledgeExtraMailboxes: true, emailProduct: $emailProduct);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('site is live. Mail pull failed', $result['message']);
        $this->assertSame(['export', 'deploy', 'import', 'bind', 'go-live', 'mail'], $order);

        $service->refresh();
        $this->assertSame($containerProduct->id, $service->product_id, 'a mail failure must not roll the site back to DirectAdmin');
        $this->assertSame('container', $service->provisioning_driver_key);
        $convertMeta = $service->service_meta['da_convert'];
        $this->assertSame('completed', $convertMeta['status']);
        $this->assertSame('failed', $convertMeta['mail_status']);
        $this->assertStringContainsString('SSH login failed', $convertMeta['mail_error']);

        $steps = implode("\n", $convertMeta['steps']);
        $this->assertStringContainsString('order: export, deploy, import, go live, then mail', $steps);
        $this->assertStringContainsString('https://example.com is live', $steps);
        $this->assertStringContainsString('Mail pull failed: Mailcow node "Mail-1" SSH login failed at 2.28.0.13 The site is live; fix the cause and use Retry mail pull.', $steps);
        $this->assertLessThan(
            strpos($steps, 'Site is up. Pulling mailboxes'),
            strpos($steps, 'Bound example.com'),
            'the site must be bound before mail starts',
        );
        $this->assertStringContainsString('Mail pull FAILED', $steps);
    }

    public function test_a_second_attempt_states_why_the_first_failed(): void
    {
        Http::fake();
        [$service, $containerProduct, $emailProduct] = $this->fixture();
        $meta = $service->service_meta;
        $meta['da_convert'] = ['status' => 'failed', 'error' => 'MySQL sidecar did not become ready', 'attempt' => 1, 'previous' => [
            'product_id' => $service->product_id, 'node_id' => $service->node_id, 'provisioning_driver_key' => 'directadmin', 'custom_price' => null, 'status' => 'active',
        ]];
        $service->update(['service_meta' => $meta]);

        $migrator = Mockery::mock(DirectAdminToContainerMigrationService::class);
        $migrator->shouldReceive('inventory')->andReturn($this->inventory());
        $migrator->shouldReceive('stackMayExportDatabase')->andReturn(false);
        $migrator->shouldReceive('exportSiteFromDirectAdmin')->once()->andThrow(new \RuntimeException('DirectAdmin SSH unreachable'));
        $deployments = Mockery::mock(ContainerDeploymentService::class);
        $deployments->shouldReceive('assertHostHasCapacity')->once();
        $mail = Mockery::mock(DirectAdminToMailcowMigrationService::class);
        $mail->shouldReceive('listMailboxesByDomains')->andReturn([
            'all' => [['account' => 'info', 'email' => 'info@example.com', 'domain' => 'example.com']],
            'by_domain' => ['example.com' => [['account' => 'info', 'email' => 'info@example.com', 'domain' => 'example.com']]],
            'errors' => [],
            'ssh_scanned' => true,
        ]);
        $mail->shouldNotReceive('pullFromDirectAdminUser');
        $snapshots = Mockery::mock(DaAccountSnapshotService::class);
        $snapshot = new DaAccountSnapshot;
        $snapshot->id = 78;
        $snapshots->shouldReceive('captureOrFail')->andReturn($snapshot);

        $convert = new DirectAdminToContainerConvertService($migrator, $deployments, Mockery::mock(ServiceRenewalPricingService::class), $mail, $snapshots, app(DaConvertProgress::class), Mockery::mock(ContainerGoLiveService::class));

        try {
            $convert->convertInPlace($service, $containerProduct, acknowledgeExtraMailboxes: true, emailProduct: $emailProduct);
            $this->fail('export failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('DirectAdmin SSH unreachable', $e->getMessage());
        }

        $meta = $service->fresh()->service_meta['da_convert'];
        $this->assertSame('failed', $meta['status']);
        $this->assertSame(2, $meta['attempt']);
        $this->assertSame('Previous attempt failed: MySQL sidecar did not become ready', $meta['steps'][0]);
        $this->assertStringContainsString('attempt 2', $meta['steps'][1]);
        $this->assertSame('directadmin', $service->fresh()->provisioning_driver_key, 'an export failure still rolls back to DirectAdmin');
    }

    /**
     * @return array<string, mixed>
     */
    private function inventory(): array
    {
        return [
            'domain' => 'example.com',
            'docroot' => '/home/exampleuser/domains/example.com/public_html',
            'app_root' => '/home/exampleuser/domains/example.com/public_html',
            'stack' => 'static_or_php',
            'has_wp_config' => false,
            'databases' => [],
            'sites' => [],
            'addon_site_count' => 0,
            'account' => ['counts' => ['email' => 1]],
        ];
    }

    /**
     * @return array{0: Service, 1: Product, 2: Product, 3: Product}
     */
    private function fixture(): array
    {
        $customer = User::factory()->create();
        $daNode = Node::factory()->directAdmin()->create();
        Node::factory()->containerHost()->create(['status' => 'online', 'is_active' => true, 'ip_address' => '203.0.113.10']);
        Node::factory()->mailcow()->create();
        $template = ContainerTemplate::query()->firstOrCreate(['slug' => 'static-site'], ['name' => 'Static', 'docker_image' => 'nginx:alpine', 'is_active' => true]);
        $daProduct = Product::factory()->create(['type' => 'shared_hosting', 'provisioning_driver_key' => 'directadmin']);
        $containerProduct = Product::factory()->containerHosting()->create(['container_template_id' => $template->id, 'name' => 'App Hosting']);
        $emailProduct = Product::factory()->emailHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $daProduct->id,
            'node_id' => $daNode->id,
            'status' => 'active',
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'exampleuser',
            'service_meta' => ['username' => 'exampleuser', 'domain' => 'example.com'],
        ]);

        return [$service->fresh(['node', 'product', 'user']), $containerProduct, $emailProduct, $daProduct];
    }
}
