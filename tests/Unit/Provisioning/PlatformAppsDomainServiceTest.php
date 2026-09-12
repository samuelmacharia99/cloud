<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dns\CloudflareDnsService;
use App\Services\Provisioning\ContainerDeploymentEventRecorder;
use App\Services\Provisioning\NginxProxyService;
use App\Services\Provisioning\PlatformAppsDomainService;
use App\Services\Provisioning\PlatformWildcardCertificateService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform hostname is what a stack answers on before a customer binds a
 * domain, so it has to exist from the first deploy, survive a move to another
 * node, and never be something the deploy itself can fail on.
 */
class PlatformAppsDomainServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $cloudflare;

    private MockInterface $nginx;

    private MockInterface $certificates;

    /** @var list<string> */
    private array $sshDisconnects = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cloudflare = Mockery::mock(CloudflareDnsService::class);
        $this->cloudflare->shouldReceive('apiToken')->andReturn('panel-token')->byDefault();
        $this->nginx = Mockery::mock(NginxProxyService::class);
        $this->certificates = Mockery::mock(PlatformWildcardCertificateService::class);

        Setting::setValue(PlatformAppsDomainService::SETTING_ZONE, 'Apps.Example.COM.');
        Setting::setValue(PlatformAppsDomainService::SETTING_ZONE_ID, 'zone-1');
        Setting::setValue(PlatformAppsDomainService::SETTING_DNS_TOKEN, 'dns-token');
    }

    #[Test]
    public function nothing_happens_until_an_operator_names_the_zone(): void
    {
        Setting::setValue(PlatformAppsDomainService::SETTING_ZONE, '');
        [$service] = $this->deployedService();

        $this->cloudflare->shouldNotReceive('listRecords');
        $this->nginx->shouldNotReceive('bind');

        $this->assertFalse($this->service()->isEnabled());
        $this->assertNull($this->service()->attach($service));
        $this->assertSame(0, ContainerDomain::query()->count());
    }

    #[Test]
    public function a_first_deploy_gets_a_record_a_vhost_and_the_wildcard_certificate(): void
    {
        [$service, $deployment, $node] = $this->deployedService();
        $hostname = $deployment->container_name.'.apps.example.com';

        $this->cloudflare->shouldReceive('listRecords')
            ->once()->with('zone-1', ['type' => 'A', 'name' => $hostname])
            ->andReturn(['success' => true, 'message' => 'OK', 'records' => []]);
        $this->cloudflare->shouldReceive('createRecord')
            ->once()->with('zone-1', 'A', $hostname, '10.20.30.40', 300, null, false)
            ->andReturn(['success' => true, 'message' => 'Record created.']);
        $this->certificates->shouldReceive('ensureOnNode')
            ->once()
            ->withArgs(fn (SSHService $ssh, Node $n, string $zone, string $token, string $email): bool => $n->is($node) && $zone === 'apps.example.com' && $token === 'dns-token')
            ->andReturn(['cert' => '/etc/letsencrypt/live/apps.example.com/fullchain.pem', 'key' => '/etc/letsencrypt/live/apps.example.com/privkey.pem']);
        $this->nginx->shouldReceive('bind')->once()->withArgs(function (ContainerDomain $domain) use ($hostname): bool {
            return $domain->domain === $hostname
                && $domain->ssl_enabled
                && $domain->ssl_certificate_path === '/etc/letsencrypt/live/apps.example.com/fullchain.pem';
        });

        $domain = $this->service()->attach($service);

        $this->assertNotNull($domain);
        $this->assertSame($hostname, $domain->domain);
        $this->assertSame(ContainerDomain::PURPOSE_PLATFORM, $domain->purpose);
        $this->assertTrue($domain->isPlatformHostname());
        $this->assertNull($domain->error_message);
        $this->assertSame([$node->id], $this->sshDisconnects);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => PlatformAppsDomainService::EVENT_ATTACHED,
        ]);
    }

    #[Test]
    public function a_redeploy_updates_the_existing_record_instead_of_creating_a_second_one(): void
    {
        [$service, $deployment] = $this->deployedService();
        $hostname = $deployment->container_name.'.apps.example.com';
        ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => $hostname,
            'purpose' => ContainerDomain::PURPOSE_PLATFORM,
            'status' => 'active',
            'nginx_config_path' => '/etc/nginx/sites-enabled/'.$hostname.'.conf',
        ]);

        $this->cloudflare->shouldReceive('listRecords')->once()
            ->andReturn(['success' => true, 'message' => 'OK', 'records' => [['id' => 'rec-9', 'type' => 'A', 'name' => $hostname, 'content' => '1.1.1.1', 'ttl' => 300, 'priority' => null, 'proxied' => false]]]);
        $this->cloudflare->shouldReceive('updateRecord')->once()
            ->with('zone-1', 'rec-9', 'A', $hostname, '10.20.30.40', 300, null, false)
            ->andReturn(['success' => true, 'message' => 'Record updated.']);
        $this->cloudflare->shouldNotReceive('createRecord');
        $this->certificates->shouldReceive('ensureOnNode')->once()->andReturn(['cert' => '/c', 'key' => '/k']);
        $this->nginx->shouldReceive('bind')->once();

        $this->service()->attach($service);

        $this->assertSame(1, ContainerDomain::query()->where('purpose', ContainerDomain::PURPOSE_PLATFORM)->count());
    }

    #[Test]
    public function a_dns_failure_is_recorded_on_the_row_and_never_thrown_at_the_deploy(): void
    {
        [$service, $deployment] = $this->deployedService();

        $this->cloudflare->shouldReceive('listRecords')->once()->andReturn(['success' => false, 'message' => 'Authentication error']);
        $this->nginx->shouldNotReceive('bind');
        $this->certificates->shouldNotReceive('ensureOnNode');

        $domain = $this->service()->attach($service);

        $this->assertNotNull($domain);
        $this->assertSame('failed', $domain->status);
        $this->assertStringContainsString('Authentication error', $domain->error_message);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => PlatformAppsDomainService::EVENT_FAILED,
        ]);
    }

    #[Test]
    public function the_hostname_label_is_dns_safe_and_a_hostname_owned_elsewhere_is_refused(): void
    {
        [$service, $deployment] = $this->deployedService(['container_name' => 'User_1--Service_10']);
        $this->assertSame('user-1-service-10.apps.example.com', $this->service()->hostnameFor($deployment));

        $other = ContainerDeployment::factory()->create(['service_id' => Service::factory()->create()->id]);
        ContainerDomain::create([
            'container_deployment_id' => $other->id,
            'domain' => 'user-1-service-10.apps.example.com',
            'status' => 'active',
        ]);
        $this->cloudflare->shouldNotReceive('listRecords');

        $this->assertNull($this->service()->attach($service));
        $this->assertDatabaseHas('container_deployment_events', ['service_id' => $service->id, 'event' => PlatformAppsDomainService::EVENT_FAILED]);
    }

    #[Test]
    public function the_record_follows_the_node_and_is_removed_on_release(): void
    {
        [, $deployment] = $this->deployedService();
        $hostname = $deployment->container_name.'.apps.example.com';
        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => $hostname,
            'purpose' => ContainerDomain::PURPOSE_PLATFORM,
            'status' => 'active',
        ]);
        $record = ['id' => 'rec-1', 'type' => 'A', 'name' => $hostname, 'content' => '10.20.30.40', 'ttl' => 300, 'priority' => null, 'proxied' => false];

        $this->cloudflare->shouldReceive('listRecords')->twice()->andReturn(['success' => true, 'message' => 'OK', 'records' => [$record]]);
        $this->cloudflare->shouldReceive('updateRecord')->once()
            ->with('zone-1', 'rec-1', 'A', $hostname, '10.99.0.1', 300, null, false)
            ->andReturn(['success' => true, 'message' => 'Record updated.']);
        $this->cloudflare->shouldReceive('deleteRecord')->once()->with('zone-1', 'rec-1')->andReturn(['success' => true, 'message' => 'OK']);

        $this->service()->pointAt($domain, '10.99.0.1');
        $this->service()->release($domain);

        $this->assertDatabaseHas('container_deployment_events', ['event' => PlatformAppsDomainService::EVENT_RELEASED]);
    }

    #[Test]
    public function renewal_touches_each_node_once_regardless_of_how_many_stacks_it_serves(): void
    {
        [, $first, $node] = $this->deployedService();
        [, $second] = $this->deployedService([], $node);
        foreach ([$first, $second] as $deployment) {
            ContainerDomain::create([
                'container_deployment_id' => $deployment->id,
                'domain' => $deployment->container_name.'.apps.example.com',
                'purpose' => ContainerDomain::PURPOSE_PLATFORM,
                'status' => 'active',
            ]);
        }

        $this->certificates->shouldReceive('renewOnNode')->once()
            ->withArgs(fn (SSHService $ssh, Node $n, string $zone): bool => $n->is($node) && $zone === 'apps.example.com');

        $report = $this->service()->renewCertificates();

        $this->assertSame(['renewed' => 1, 'failed' => 0, 'errors' => []], $report);
    }

    private function service(): PlatformAppsDomainService
    {
        return new PlatformAppsDomainService(
            $this->cloudflare,
            $this->nginx,
            $this->certificates,
            new ContainerDeploymentEventRecorder,
            function (Node $node): SSHService {
                $ssh = Mockery::mock(SSHService::class);
                $ssh->shouldReceive('disconnect')->andReturnUsing(function () use ($node): void {
                    $this->sshDisconnects[] = $node->id;
                });

                return $ssh;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $deploymentAttributes
     * @return array{0: Service, 1: ContainerDeployment, 2: Node}
     */
    private function deployedService(array $deploymentAttributes = [], ?Node $node = null): array
    {
        $node ??= Node::factory()->containerHost()->create(['ip_address' => '10.20.30.40']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);
        $deployment = ContainerDeployment::factory()->create(array_merge([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ], $deploymentAttributes));

        return [$service->fresh(['containerDeployment.node']), $deployment, $node];
    }
}
