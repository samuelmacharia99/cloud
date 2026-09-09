<?php

namespace Tests\Unit\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ContainerDeploymentComposeConflictTest extends TestCase
{
    #[Test]
    public function it_detects_docker_container_name_conflicts(): void
    {
        $service = app(ContainerDeploymentService::class);

        $message = 'Error response from daemon: Conflict. The container name "/b0a1c30fec04_user-427-service-137-nodejs" is already in use by container "a7ea987621f93f7a6c86c067286a768ab2aac4abac3c1d113081cbcd15bb5570".';

        $this->assertTrue($service->isDockerContainerNameConflict($message));
        $this->assertFalse($service->isDockerContainerNameConflict('npm install failed'));
    }

    #[Test]
    public function it_extracts_conflicting_container_name_and_id(): void
    {
        $service = app(ContainerDeploymentService::class);

        $message = 'Conflict. The container name "/b0a1c30fec04_user-427-service-137-nodejs" is already in use by container "a7ea987621f93f7a6c86c067286a768ab2aac4abac3c1d113081cbcd15bb5570".';

        $this->assertSame([
            'b0a1c30fec04_user-427-service-137-nodejs',
            'a7ea987621f93f7a6c86c067286a768ab2aac4abac3c1d113081cbcd15bb5570',
        ], $service->conflictingDockerRefsFromError($message));
    }

    #[Test]
    public function it_detects_containers_marked_for_removal(): void
    {
        $service = app(ContainerDeploymentService::class);

        $message = 'Container user-493-service-454-nodejs Starting Container user-493-service-454-nodejs Error response from daemon: container is marked for removal and cannot be started';

        $this->assertTrue($service->isDockerContainerMarkedForRemoval($message));
        $this->assertFalse($service->isDockerContainerMarkedForRemoval('npm install failed'));
        $this->assertSame([
            'user-493-service-454-nodejs',
        ], $service->dockerContainerRefsFromComposeError($message));
    }

    #[Test]
    public function it_detects_host_port_already_allocated(): void
    {
        $service = app(ContainerDeploymentService::class);

        $message = 'Error response from daemon: failed to set up container networking: Bind for 0.0.0.0:30001 failed: port is already allocated';

        $this->assertTrue($service->isDockerHostPortAllocated($message));
        $this->assertSame(30001, $service->dockerHostPortFromBindError($message));
        $this->assertFalse($service->isDockerHostPortAllocated('npm install failed'));
        $this->assertNull($service->dockerHostPortFromBindError('npm install failed'));
    }

    #[Test]
    public function compose_up_removes_orphan_services_from_a_previous_file(): void
    {
        $service = app(ContainerDeploymentService::class);

        $this->assertSame(
            'cd /opt/talksasa/containers/user-493-service-457-python && docker compose up -d --remove-orphans',
            $service->composeUpCommand('/opt/talksasa/containers/user-493-service-457-python', false)
        );
        $this->assertStringContainsString(
            '--pull never',
            $service->composeUpCommand('/opt/talksasa/containers/user-1-service-1-laravel', true, true)
        );
        $this->assertStringContainsString(
            '-f docker-compose.yml',
            $service->composeUpCommand('/opt/talksasa/containers/user-1-service-1-laravel', true, true)
        );
    }

    #[Test]
    public function reclaim_script_only_targets_this_stack_on_the_busy_port(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(function (string $command) {
                return str_contains($command, 'publish=')
                    && str_contains($command, '30001')
                    && str_contains($command, 'user-493-service-457-python')
                    && str_contains($command, '${name}-db');
            })
            ->andReturn('');

        app(ContainerDeploymentService::class)->reclaimStalePublishedPort(
            $ssh,
            '/opt/talksasa/containers/user-493-service-457-python',
            30001,
            'user-493-service-457-python'
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function compose_up_retries_after_reclaiming_an_allocated_port(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $portError = new SSHCommandException(
            'cd /opt/talksasa/containers/user-493-service-457-python && docker compose up -d --remove-orphans',
            "Container user-493-service-457-python-db Created\n"
            .'Error response from daemon: Bind for 0.0.0.0:30001 failed: port is already allocated',
            'Command exited with status 1'
        );

        $ssh->shouldReceive('exec')->once()->andReturn('ok');
        $ssh->shouldReceive('exec')->once()->andThrow($portError);
        $ssh->shouldReceive('exec')->once()->andReturn('');
        $ssh->shouldReceive('exec')->once()->andReturn('');

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'composeUp');
        $method->invoke(
            app(ContainerDeploymentService::class),
            $ssh,
            '/opt/talksasa/containers/user-493-service-457-python',
            false,
            false,
            120,
            'user-493-service-457-python'
        );
    }
}
