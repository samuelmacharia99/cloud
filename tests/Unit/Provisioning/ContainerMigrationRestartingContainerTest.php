<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDatabaseMigrationService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Running migrations on a crash-looping app returned a Docker daemon sentence:
 * "Container ... is restarting, wait until the container is running".
 *
 * The platform already had a one-off container path for a stopped stack, and
 * chose between them by reading the deployment row. A crash-looping container
 * is recorded as running, because Docker reports it running for the second
 * between restarts. So the one state where somebody reaches for migrations,
 * their application will not boot, was the state that refused them.
 */
class ContainerMigrationRestartingContainerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_restarting_container_is_not_offered_an_exec(): void
    {
        $this->assertFalse($this->acceptsExec('restarting|false|false|1|true|9'));
    }

    #[Test]
    public function a_container_that_is_genuinely_up_still_gets_an_exec(): void
    {
        $this->assertTrue($this->acceptsExec('running|true|false|0|false|0'));
    }

    #[Test]
    public function a_container_that_is_gone_is_not_offered_an_exec(): void
    {
        $this->assertFalse($this->acceptsExec(''));
    }

    #[Test]
    public function a_node_that_cannot_be_asked_falls_back_to_what_the_row_says(): void
    {
        // The assumption the platform made before it could ask. A wrong guess
        // here costs one clear error, not a silent wrong path.
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andThrow(new \RuntimeException('node unreachable'));

        $this->assertTrue($this->invoke($ssh, 'running'));
        $this->assertFalse($this->invoke($ssh, 'stopped'));
    }

    private function acceptsExec(string $dockerInspect): bool
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn($dockerInspect);

        return $this->invoke($ssh, 'running');
    }

    private function invoke(SSHService $ssh, string $rowStatus): bool
    {
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-493-service-457-python-'.uniqid(),
            'status' => $rowStatus,
        ]);

        $method = new \ReflectionMethod(ContainerDatabaseMigrationService::class, 'appContainerAcceptsExec');

        return (bool) $method->invoke(app(ContainerDatabaseMigrationService::class), $ssh, $deployment);
    }
}
