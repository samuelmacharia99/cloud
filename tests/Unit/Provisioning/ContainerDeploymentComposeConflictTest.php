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

    #[Test]
    public function it_recognises_every_wording_docker_uses_for_a_taken_host_port(): void
    {
        $service = app(ContainerDeploymentService::class);

        // The wording that slipped through and failed a live deploy.
        $modern = 'failed to set up container networking: driver failed programming external connectivity on endpoint '
            .'user-512-service-516-wordpress (f33b95): failed to bind host port 127.0.0.1:30054/tcp: address already in use';
        $this->assertTrue($service->isDockerHostPortAllocated($modern));
        $this->assertSame(30054, $service->dockerHostPortFromBindError($modern));

        $classic = 'Bind for 0.0.0.0:30001 failed: port is already allocated';
        $this->assertTrue($service->isDockerHostPortAllocated($classic));
        $this->assertSame(30001, $service->dockerHostPortFromBindError($classic));

        $node = 'Error: EADDRINUSE 0.0.0.0:30077: address already in use';
        $this->assertTrue($service->isDockerHostPortAllocated($node));
        $this->assertSame(30077, $service->dockerHostPortFromBindError($node));

        $this->assertFalse($service->isDockerHostPortAllocated('no space left on device'));
        $this->assertNull($service->dockerHostPortFromBindError('no space left on device'));
    }

    #[Test]
    public function a_port_held_by_something_else_is_swapped_for_a_free_one_and_the_stack_comes_up(): void
    {
        $service = app(ContainerDeploymentService::class);
        $reassigned = [];

        $ssh = Mockery::mock(SSHService::class);
        // network ensure, failed up, reclaim probe(s), free-port check, listing, successful up
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) {
            if (str_contains($command, 'docker compose') && str_contains($command, 'up -d')) {
                static $attempts = 0;
                $attempts++;
                if ($attempts === 1) {
                    throw new SSHCommandException($command, 'failed to bind host port 127.0.0.1:30054/tcp: address already in use', 'Command exited with status 1');
                }

                return '';
            }
            if (str_contains($command, 'docker ps -q --filter publish')) {
                return 'c0ffee';        // still held, so reclaiming freed nothing
            }

            return '';
        });

        $composeUp = new ReflectionMethod(ContainerDeploymentService::class, 'composeUp');
        $composeUp->invoke(
            $service,
            $ssh,
            '/opt/talksasa/containers/user-512-service-516-wordpress',
            true,
            false,
            120,
            'user-512-service-516-wordpress',
            function (?int $busyPort) use (&$reassigned): bool {
                $reassigned[] = $busyPort;

                return true;
            },
        );

        $this->assertSame([30054], $reassigned, 'the busy port is handed to the caller so it can pick another');
    }

    #[Test]
    public function a_port_the_stack_itself_left_behind_is_reclaimed_without_moving_the_stack(): void
    {
        $service = app(ContainerDeploymentService::class);
        $reassigned = 0;

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) {
            if (str_contains($command, 'docker compose') && str_contains($command, 'up -d')) {
                static $attempts = 0;
                $attempts++;
                if ($attempts === 1) {
                    throw new SSHCommandException($command, 'Bind for 127.0.0.1:30054 failed: port is already allocated', 'status 1');
                }

                return '';
            }
            if (str_contains($command, 'docker ps -q --filter publish')) {
                return '';              // reclaim freed it
            }

            return '';
        });

        (new ReflectionMethod(ContainerDeploymentService::class, 'composeUp'))->invoke(
            $service,
            $ssh,
            '/opt/talksasa/containers/user-1-service-2-php',
            true,
            false,
            120,
            'user-1-service-2-php',
            function () use (&$reassigned): bool {
                $reassigned++;

                return true;
            },
        );

        $this->assertSame(0, $reassigned, 'our own leftover was cleared, so the stack keeps its port');
    }

    #[Test]
    public function the_node_is_asked_what_is_listening_not_only_docker(): void
    {
        $service = app(ContainerDeploymentService::class);

        foreach ([$service->portListenerProbeCommand(30054), $service->listeningPortsCommand()] as $command) {
            $file = tempnam(sys_get_temp_dir(), 'talksasa-port').'.sh';
            file_put_contents($file, $command);
            exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
            @unlink($file);
            $this->assertSame(0, $code, implode("\n", $out));
        }

        $this->assertStringContainsString('ss -ltnH', $service->portListenerProbeCommand(30054));
        $this->assertStringContainsString('netstat', $service->portListenerProbeCommand(30054), 'a host without ss still has to answer');

        // ss output and docker port columns both yield ports, and only ours.
        $ports = $service->parseListeningPorts(
            "LISTEN 0 4096 127.0.0.1:30054 0.0.0.0:*\n"
            ."LISTEN 0 511 0.0.0.0:443 0.0.0.0:*\n"
            ."127.0.0.1:30055->80/tcp, 3306/tcp\n"
        );
        sort($ports);
        $this->assertSame([30054, 30055], $ports, 'ports outside the platform range are none of our business');

        // A node that cannot answer must not block a deploy.
        $failing = Mockery::mock(SSHService::class);
        $failing->shouldReceive('exec')->andThrow(new \RuntimeException('ssh down'));
        $this->assertSame([], $service->nodeListeningPorts($failing));
        $this->assertTrue($failing instanceof SSHService);
    }
}
