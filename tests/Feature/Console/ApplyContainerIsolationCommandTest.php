<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ApplyContainerIsolationCommand;
use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentEventRecorder;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerDoctorService;
use App\Services\Provisioning\ContainerIsolationPolicy;
use App\Services\Provisioning\ContainerStackNetworkAllocator;
use App\Services\Provisioning\PlatformAppsDomainService;
use App\Services\SSH\SSHService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The rollout is the only path that moves a running stack off the shared
 * bridge. It has to list exactly the stacks that need moving, prove the move
 * happened by inspecting the node rather than trusting Compose, and put a
 * stack back the moment anything about it does not check out.
 */
class ApplyContainerIsolationCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{path: string, content: string}> */
    private array $uploads = [];

    /** @var list<string> */
    private array $commands = [];

    private MockInterface $deployments;

    private MockInterface $platformHostnames;

    /** @var array<string, string> Container name to the HostIp docker inspect reports. */
    private array $hostIpByContainer = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->deployments = Mockery::mock(ContainerDeploymentService::class);
        $this->deployments->shouldReceive('startComposeStack')->byDefault();
        $this->deployments->shouldReceive('waitForContainerRunning')->byDefault();
        $this->platformHostnames = Mockery::mock(PlatformAppsDomainService::class);
        $this->platformHostnames->shouldReceive('attach')->andReturnNull()->byDefault();
    }

    #[Test]
    public function a_dry_run_lists_only_stacks_still_on_the_shared_bridge_and_changes_nothing(): void
    {
        $node = Node::factory()->containerHost()->create();
        [$legacy] = $this->runningStack($node, 'user-1-service-1');
        [$isolated] = $this->runningStack($node, 'user-1-service-2');
        $this->stubLiveCompose([
            'user-1-service-1' => $this->legacyYaml('user-1-service-1'),
            'user-1-service-2' => (new ContainerIsolationPolicy)->applyToYaml($this->legacyYaml('user-1-service-2'), 'user-1-service-2', 'user-1-service-2', '10.210.0.0/24'),
        ]);

        [$code, $output] = $this->runRollout(['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('user-1-service-1', $output);
        $this->assertStringNotContainsString('user-1-service-2', $output);
        $this->assertStringContainsString('1 stack(s) would be moved. Nothing was modified.', $output);
        $this->assertSame([], $this->uploads);
        $this->assertNull($legacy->fresh()->network_subnet);
    }

    #[Test]
    public function a_stack_is_moved_verified_on_the_node_and_given_its_platform_hostname(): void
    {
        $node = Node::factory()->containerHost()->create();
        [$deployment, $service] = $this->runningStack($node, 'user-1-service-1');
        $this->stubLiveCompose(['user-1-service-1' => $this->legacyYaml('user-1-service-1')]);
        $this->hostIpByContainer = ['user-1-service-1' => '127.0.0.1'];
        $this->platformHostnames->shouldReceive('attach')->once()->andReturnNull();

        [$code, $output] = $this->runRollout(['--force' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('now on user-1-service-1-net (10.210.0.0/24), ports on loopback', $output);
        $this->assertCount(1, $this->uploads);
        $this->assertSame('/opt/talksasa/containers/user-1-service-1/docker-compose.yml', $this->uploads[0]['path']);
        $patched = Yaml::parse($this->uploads[0]['content']);
        $this->assertSame(['127.0.0.1:31010:80'], $patched['services']['user-1-service-1']['ports']);
        $this->assertSame('10.210.0.0/24', $patched['networks']['default']['ipam']['config'][0]['subnet']);
        $this->assertSame('10.210.0.0/24', $deployment->fresh()->network_subnet);
        $this->assertTrue((new ContainerIsolationPolicy)->isCurrent((string) $deployment->fresh()->docker_compose_content));
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => ApplyContainerIsolationCommand::EVENT_APPLIED,
        ]);
        $this->assertStringContainsString("docker ps -a --filter label=com.docker.compose.project='user-1-service-1'", implode("\n", $this->commands));
    }

    #[Test]
    public function a_domainless_wordpress_site_is_pointed_at_its_new_platform_hostname(): void
    {
        $node = Node::factory()->containerHost()->create();
        [$deployment, $service] = $this->runningStack($node, 'user-1-service-1', 'wordpress');
        $this->stubLiveCompose(['user-1-service-1' => $this->legacyYaml('user-1-service-1')]);
        $this->hostIpByContainer = ['user-1-service-1' => '127.0.0.1'];

        $hostname = new ContainerDomain([
            'container_deployment_id' => $deployment->id,
            'domain' => 'user-1-service-1.apps.example.com',
            'purpose' => ContainerDomain::PURPOSE_PLATFORM,
            'status' => 'active',
        ]);
        $this->platformHostnames->shouldReceive('attach')->once()->andReturn($hostname);

        $doctor = Mockery::mock(ContainerDoctorService::class);
        $doctor->shouldReceive('treat')
            ->once()
            ->withArgs(fn (Service $s, string $action): bool => $s->is($service) && $action === 'fix_wordpress_site_url')
            ->andReturn(['success' => true, 'message' => 'WordPress now points at https://user-1-service-1.apps.example.com.']);
        $this->app->instance(ContainerDoctorService::class, $doctor);

        [$code, $output] = $this->runRollout(['--force' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('WordPress URLs: WordPress now points at', $output);
    }

    #[Test]
    public function a_stack_that_still_publishes_publicly_is_put_back_and_stops_the_run(): void
    {
        $node = Node::factory()->containerHost()->create();
        [$first, $firstService] = $this->runningStack($node, 'user-1-service-1');
        [$second] = $this->runningStack($node, 'user-1-service-2');
        $this->stubLiveCompose([
            'user-1-service-1' => $this->legacyYaml('user-1-service-1'),
            'user-1-service-2' => $this->legacyYaml('user-1-service-2'),
        ]);
        // Docker still reports the port on every interface: the move did not take.
        $this->hostIpByContainer = ['user-1-service-1' => '0.0.0.0', 'user-1-service-2' => '127.0.0.1'];
        $this->platformHostnames->shouldNotReceive('attach');

        [$code, $output] = $this->runRollout(['--force' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('80/tcp is published on 0.0.0.0', $output);
        $this->assertStringContainsString('Stopping here', $output);
        $this->assertStringContainsString('Still on the shared bridge: service '.$firstService->id, $output);

        // Patched, then the original again.
        $this->assertCount(2, $this->uploads);
        $this->assertSame($this->legacyYaml('user-1-service-1'), $this->uploads[1]['content']);
        $this->assertSame($this->legacyYaml('user-1-service-1'), $first->fresh()->docker_compose_content);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $firstService->id,
            'event' => ApplyContainerIsolationCommand::EVENT_ROLLED_BACK,
        ]);
        // The second stack was never touched.
        $this->assertNull($second->fresh()->network_subnet);
        $this->assertStringNotContainsString('user-1-service-2/docker-compose.yml', implode("\n", array_column($this->uploads, 'path')));
    }

    #[Test]
    public function continue_on_failure_carries_on_to_the_next_stack(): void
    {
        $node = Node::factory()->containerHost()->create();
        $this->runningStack($node, 'user-1-service-1');
        [$second] = $this->runningStack($node, 'user-1-service-2');
        $this->stubLiveCompose([
            'user-1-service-1' => $this->legacyYaml('user-1-service-1'),
            'user-1-service-2' => $this->legacyYaml('user-1-service-2'),
        ]);
        $this->hostIpByContainer = ['user-1-service-1' => '0.0.0.0', 'user-1-service-2' => '127.0.0.1'];

        [$code, $output] = $this->runRollout(['--force' => true, '--continue-on-failure' => true, '--skip-platform-domain' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Moved: 1', $output);
        $this->assertStringContainsString('Failed: 1', $output);
        $this->assertSame('10.210.1.0/24', $second->fresh()->network_subnet);
        $this->platformHostnames->shouldNotHaveReceived('attach');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function runRollout(array $options): array
    {
        $command = new ApplyContainerIsolationCommand(
            $this->deployments,
            new ContainerIsolationPolicy,
            new ContainerStackNetworkAllocator,
            $this->platformHostnames,
            new ContainerDeploymentEventRecorder,
            fn (Node $node): SSHService => $this->ssh(),
        );
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $input = new ArrayInput($options);
        $command->setOutput(new OutputStyle($input, $output));

        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
    }

    /**
     * @param  array<string, string>  $byContainer
     */
    private function stubLiveCompose(array $byContainer): void
    {
        $this->deployments->shouldReceive('liveComposeYaml')->andReturnUsing(function (SSHService $ssh, ContainerDeployment $deployment) use ($byContainer): string {
            $name = (string) $deployment->container_name;
            $path = '/opt/talksasa/containers/'.$name.'/docker-compose.yml';
            foreach (array_reverse($this->uploads) as $upload) {
                if ($upload['path'] === $path) {
                    return $upload['content'];
                }
            }

            return $byContainer[$name] ?? '';
        });
    }

    private function ssh(): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('disconnect');
        $ssh->shouldReceive('upload')->andReturnUsing(function (string $content, string $path): void {
            $this->uploads[] = ['path' => $path, 'content' => $content];
        });
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command): string {
            $this->commands[] = $command;

            if (preg_match("/docker ps -a --filter label=com\.docker\.compose\.project='([^']+)'/", $command, $m)) {
                return $m[1]."\n".$m[1]."-db\n";
            }
            if (preg_match("/docker inspect --format .* '([^']+)'$/", $command, $m)) {
                $container = $m[1];
                $stack = str_ends_with($container, '-db') ? substr($container, 0, -3) : $container;
                $ports = str_ends_with($container, '-db')
                    ? 'null'
                    : json_encode(['80/tcp' => [['HostIp' => $this->hostIpByContainer[$stack] ?? '127.0.0.1', 'HostPort' => '31010']]]);

                return implode('||', [
                    $ports,
                    json_encode([$stack.'-net' => ['IPAddress' => '10.210.0.2']]),
                    json_encode(['no-new-privileges:true']),
                    json_encode(['NET_RAW', 'MKNOD', 'AUDIT_WRITE', 'SYS_CHROOT', 'SETFCAP']),
                    '1024',
                ]);
            }

            return '';
        });

        return $ssh;
    }

    private function legacyYaml(string $containerName): string
    {
        return Yaml::dump([
            'services' => [
                $containerName => ['image' => 'nginx:alpine', 'ports' => ['31010:80']],
                'db' => ['image' => 'mysql:8', 'networks' => ['default' => ['aliases' => [$containerName.'-db']]]],
            ],
            'networks' => ['default' => ['name' => 'talksasa-net', 'external' => true]],
        ], 10, 2);
    }

    /**
     * @return array{0: ContainerDeployment, 1: Service}
     */
    private function runningStack(Node $node, string $containerName, ?string $slug = null): array
    {
        $template = ContainerTemplate::factory()->create(['slug' => $slug ?? 'static-site-'.$containerName]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => Product::factory()->containerHosting()->create(['container_template_id' => $template->id])->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => $containerName,
            'status' => 'running',
            'docker_compose_content' => $this->legacyYaml($containerName),
        ]);

        return [$deployment, $service];
    }
}
