<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerStackNetworkLocator;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * During the rollout a host carries stacks on both layouts, so the node is
 * asked which network a stack is on rather than the platform guessing.
 */
class ContainerStackNetworkLocatorTest extends TestCase
{
    #[Test]
    public function a_stack_with_its_own_network_is_placed_on_it_and_a_legacy_stack_on_the_shared_bridge(): void
    {
        $locator = new ContainerStackNetworkLocator;

        $commands = [];
        $this->assertSame('user-1-service-2-net', $locator->forContainer($this->ssh($commands, 'yes'), 'user-1-service-2'));
        $this->assertStringContainsString("docker network inspect 'user-1-service-2-net'", $commands[0]);

        $this->assertSame('talksasa-net', $locator->forContainer($this->ssh($commands, 'no'), 'user-1-service-2'));
        $this->assertSame('talksasa-net', $locator->forContainer($this->ssh($commands, 'yes'), 'bad name; rm -rf /'));
    }

    #[Test]
    public function build_helpers_are_resolved_from_the_host_path_they_mount(): void
    {
        $locator = new ContainerStackNetworkLocator;

        $commands = [];
        $this->assertSame(
            'user-1-service-2-net',
            $locator->forHostAppPath($this->ssh($commands, 'yes'), '/opt/talksasa/containers/user-1-service-2/app/frontend')
        );
        $this->assertCount(1, $commands);

        // A path outside the container base never reaches the node.
        $foreign = [];
        $this->assertSame(
            'talksasa-net',
            $locator->forHostAppPath($this->ssh($foreign, 'yes'), '/var/lib/talksasa/containers/user-1-service-2/app')
        );
        $this->assertSame([], $foreign);
    }

    /**
     * @param  list<string>  $commands
     */
    private function ssh(array &$commands, string $answer): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $answer): string {
            $commands[] = $command;

            return $answer;
        });

        return $ssh;
    }
}
