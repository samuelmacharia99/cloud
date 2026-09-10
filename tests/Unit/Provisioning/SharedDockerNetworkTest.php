<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shared bridge is ensured before every deploy and every auto-restart
 * check, so the one thing it must never do is fail on a host where the network
 * is already there. Split across an inspect and a create, an SSH hiccup on
 * either half turned a healthy host into a failed check every two minutes.
 */
class SharedDockerNetworkTest extends TestCase
{
    #[Test]
    public function it_ensures_the_network_in_a_single_idempotent_command(): void
    {
        $commands = [];
        $ssh = $this->ssh($commands);

        app(ContainerDeploymentService::class)->ensureSharedDockerNetwork($ssh);

        $this->assertCount(1, $commands);
        $this->assertStringContainsString('docker network inspect', $commands[0]);
        $this->assertStringContainsString('||', $commands[0]);
        $this->assertStringContainsString('docker network create', $commands[0]);
    }

    #[Test]
    public function a_lost_exit_status_is_not_a_failure_when_the_network_is_there(): void
    {
        $commands = [];
        $ssh = $this->ssh(
            $commands,
            fn (string $command): ?string => str_contains($command, 'echo yes') ? 'yes' : null,
            'SSH server did not provide a command exit status',
        );

        app(ContainerDeploymentService::class)->ensureSharedDockerNetwork($ssh);

        $this->assertTrue(true, 'A host that already has the network is not an error.');
    }

    #[Test]
    public function a_real_failure_on_a_host_without_the_network_still_raises(): void
    {
        $commands = [];
        $ssh = $this->ssh(
            $commands,
            fn (string $command): ?string => str_contains($command, 'echo yes') ? 'no' : null,
            'Cannot connect to the Docker daemon',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to the Docker daemon');

        app(ContainerDeploymentService::class)->ensureSharedDockerNetwork($ssh);
    }

    #[Test]
    public function an_exhausted_address_pool_falls_back_to_an_explicit_subnet(): void
    {
        $commands = [];
        $ssh = $this->ssh(
            $commands,
            fn (string $command): ?string => str_contains($command, 'echo yes') ? 'no' : null,
            'could not find an available, non-overlapping IPv4 address pool among the defaults: all predefined address pools have been fully subnetted',
        );

        app(ContainerDeploymentService::class)->ensureSharedDockerNetwork($ssh);

        $this->assertStringContainsString('--subnet 10.201.0.0/16', end($commands));
    }

    /**
     * @param  list<string>  $commands
     */
    private function ssh(array &$commands, ?callable $answer = null, ?string $ensureError = null): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(
            function (string $command) use (&$commands, $answer, $ensureError): string {
                $commands[] = $command;

                $canned = $answer ? $answer($command) : null;
                if ($canned !== null) {
                    return $canned;
                }

                if ($ensureError !== null && str_contains($command, 'docker network create') && ! str_contains($command, '--subnet')) {
                    throw new \RuntimeException($ensureError);
                }

                return '';
            }
        );

        return $ssh;
    }
}
