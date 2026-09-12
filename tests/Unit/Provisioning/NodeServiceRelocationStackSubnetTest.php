<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\NodeServiceRelocationService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A node-delete rescan rewrites what the row says from what the destination
 * actually runs. The stack subnet is part of that now, the same way the
 * published port already was.
 */
class NodeServiceRelocationStackSubnetTest extends TestCase
{
    #[Test]
    public function the_subnet_is_read_from_the_stack_network_and_only_a_real_cidr_is_trusted(): void
    {
        $method = new ReflectionMethod(NodeServiceRelocationService::class, 'readStackSubnet');
        $service = new NodeServiceRelocationService;

        $this->assertSame('10.210.3.0/24', $method->invoke($service, $this->ssh("10.210.3.0/24\n"), 'user-1-service-2'));
        $this->assertNull($method->invoke($service, $this->ssh(''), 'user-1-service-2'));
        $this->assertNull($method->invoke($service, $this->ssh('Error: No such network: user-1-service-2-net'), 'user-1-service-2'));
        $this->assertNull($method->invoke($service, $this->ssh('10.210.3.0/24'), 'bad name;'));
    }

    private function ssh(string $answer): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use ($answer): string {
            $this->assertStringContainsString("docker network inspect --format '{{(index .IPAM.Config 0).Subnet}}' 'user-1-service-2-net'", $command);

            return $answer;
        });

        return $ssh;
    }
}
