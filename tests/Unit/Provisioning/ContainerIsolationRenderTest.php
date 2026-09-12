<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerTemplateEnvironmentService;
use App\Services\Provisioning\RuntimeImageProvisioner;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BootsBareFacades;

/**
 * How the deployment service hands a rendered stack to the isolation policy,
 * and the two rules around it: a legacy stack is only moved by a deploy or the
 * rollout command, never by a re-render, and a Redis sidecar never starts
 * without a password again.
 */
class ContainerIsolationRenderTest extends TestCase
{
    use BootsBareFacades;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBareFacades();
    }

    protected function tearDown(): void
    {
        $this->tearDownBareFacades();

        parent::tearDown();
    }

    #[Test]
    public function a_re_render_keeps_a_legacy_stack_legacy_and_an_isolated_stack_isolated(): void
    {
        $deployer = $this->deployer();
        $legacy = Yaml::dump([
            'services' => ['app' => ['image' => 'x', 'ports' => ['31010:80']]],
            'networks' => ['default' => ['name' => 'talksasa-net', 'external' => true]],
        ], 10, 2);
        $isolated = $deployer->isolationPolicy()->applyToYaml($legacy, 'app', 'user-1-service-2', '10.210.4.0/24');

        $deployment = new ContainerDeployment(['docker_compose_content' => $legacy]);
        $this->assertFalse($deployer->rerenderKeepsIsolation($deployment));
        $this->assertNull($deployer->rerenderSubnet($deployment));

        // The live file wins over the panel copy.
        $this->assertTrue($deployer->rerenderKeepsIsolation($deployment, $isolated));
        $this->assertSame('10.210.4.0/24', $deployer->rerenderSubnet($deployment, $isolated));

        $yaml = $this->render($deployer, ['applyIsolation' => false]);
        $this->assertStringContainsString("name: talksasa-net\n    external: true", $yaml);
        $this->assertStringContainsString("- '30100:80'", $yaml);
        $this->assertFalse($deployer->isolationPolicy()->isCurrent($yaml));

        $yaml = $this->render($deployer, ['networkSubnet' => '10.210.9.0/24']);
        $this->assertTrue($deployer->isolationPolicy()->isCurrent($yaml));
        $this->assertSame('10.210.9.0/24', $deployer->isolationPolicy()->subnetFromYaml($yaml));
    }

    #[Test]
    public function a_redis_sidecar_requires_the_password_the_app_was_given(): void
    {
        $deployer = $this->deployer();
        $redis = new DatabaseTemplate(['type' => 'redis', 'docker_image' => 'redis:7-alpine', 'default_port' => 6379]);

        $yaml = $this->render($deployer, ['databaseTemplate' => $redis, 'envVars' => ['REDIS_PASSWORD' => 's3cret-pw']]);
        $compose = Yaml::parse($yaml);
        $this->assertSame(['redis-server', '--requirepass', 's3cret-pw'], $compose['services']['db']['command']);

        $method = new ReflectionMethod($deployer, 'redisEnvironmentVariables');
        $kept = $method->invoke($deployer, ['REDIS_PASSWORD' => 'p@ss/word']);
        $this->assertSame('p@ss/word', $kept['REDIS_PASSWORD']);
        $this->assertSame('redis://:p%40ss%2Fword@db:6379', $kept['REDIS_URL']);
        $this->assertSame('db', $kept['REDIS_HOST']);

        $fresh = $method->invoke($deployer, []);
        $this->assertSame(32, strlen($fresh['REDIS_PASSWORD']));
        $this->assertStringContainsString($fresh['REDIS_PASSWORD'], $fresh['REDIS_URL']);
    }

    #[Test]
    public function a_loopback_bind_failure_still_names_the_busy_port(): void
    {
        $deployer = $this->deployer();

        foreach (['0.0.0.0', '127.0.0.1'] as $address) {
            $message = "Error response from daemon: driver failed programming external connectivity: Bind for {$address}:31012 failed: port is already allocated";
            $this->assertTrue($deployer->isDockerHostPortAllocated($message));
            $this->assertSame(31012, $deployer->dockerHostPortFromBindError($message));
        }

        $this->assertNull($deployer->dockerHostPortFromBindError('something else went wrong'));
    }

    #[Test]
    public function node_side_probes_target_loopback_and_carry_the_served_hostname(): void
    {
        $deployment = new ContainerDeployment(['assigned_port' => 31012]);
        $deployment->setRelation('domains', new Collection([
            new ContainerDomain(['domain' => 'pending.example.com', 'status' => 'pending']),
            new ContainerDomain(['domain' => 'app.example.com', 'status' => 'active']),
        ]));

        $this->assertSame('http://127.0.0.1:31012', $deployment->loopbackUrl());
        $this->assertSame('app.example.com', $deployment->probeHostHeader());

        $bare = new ContainerDeployment([]);
        $bare->setRelation('domains', new Collection);
        $this->assertNull($bare->loopbackUrl());
        $this->assertNull($bare->probeHostHeader());
    }

    private function deployer(): ContainerDeploymentService
    {
        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(false);

        return new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function render(ContainerDeploymentService $deployer, array $overrides = []): string
    {
        $template = new ContainerTemplate([
            'slug' => 'static-site',
            'docker_image' => 'nginx:alpine',
            'default_port' => 80,
            'required_cpu_cores' => 0.25,
            'required_ram_mb' => 128,
        ]);

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');

        return $method->invoke(
            $deployer,
            $template,
            'user-1-service-2',
            30100,
            $overrides['envVars'] ?? [],
            $overrides['databaseTemplate'] ?? null,
            null,
            null,
            null,
            null,
            null,
            false,
            'frontend',
            8001,
            null,
            $overrides['networkSubnet'] ?? null,
            $overrides['applyIsolation'] ?? true,
        );
    }
}
