<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerStackNetworkAllocator;
use App\Services\Provisioning\ContainerStackNetworkReconciler;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerStackNetworkReconcilerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function live_networks_are_parsed_from_inspect_output(): void
    {
        $reconciler = new ContainerStackNetworkReconciler(new ContainerStackNetworkAllocator);
        $parsed = $reconciler->parseLiveNetworks(implode("\n", [
            'bridge|172.17.0.0/16 |3',
            'user-156-service-38-nodejs-net|10.210.5.0/24 |2',
            'talksasa-net|10.200.0.0/16 fd00::/64 |9',
            'garbage',
            '',
        ]));

        $this->assertCount(3, $parsed);
        $this->assertSame(['name' => 'user-156-service-38-nodejs-net', 'subnets' => ['10.210.5.0/24'], 'containers' => 2], $parsed[1]);
        $this->assertSame(['10.200.0.0/16', 'fd00::/64'], $parsed[2]['subnets']);
        $this->assertStringContainsString('docker network inspect', $reconciler->liveNetworksCommand());
        $this->assertSame('user-156-service-38-', $reconciler->servicePrefix('user-156-service-38-static-site'));
        $this->assertNull($reconciler->servicePrefix('talksasa-net'));
    }

    #[Test]
    public function a_renamed_stack_retires_its_previous_stack_and_network(): void
    {
        [$deployment] = $this->deployment('10.210.5.0/24');
        $ssh = Mockery::mock(SSHService::class);
        $removed = [];
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$removed) {
            if (str_contains($command, 'docker network rm')) {
                $removed[] = $command;

                return '';
            }

            return "bridge|172.17.0.0/16 |3\nuser-156-service-38-static-site-net|10.210.5.0/24 |0\n";
        });
        $tornDown = [];

        $result = (new ContainerStackNetworkReconciler(new ContainerStackNetworkAllocator))->reconcile(
            $ssh,
            $deployment,
            'user-156-service-38-static-site',
            'user-156-service-38-nodejs',
            function (string $path) use (&$tornDown) {
                $tornDown[] = $path;
            },
        );

        $this->assertSame(['/opt/talksasa/containers/user-156-service-38-nodejs'], $tornDown);
        $this->assertCount(1, $removed);
        $this->assertStringContainsString("'user-156-service-38-nodejs-net'", $removed[0]);
        $this->assertSame('10.210.5.0/24', $result['subnet']);
        $this->assertSame(['retired previous stack user-156-service-38-nodejs'], $result['actions']);
        $this->assertSame('10.210.5.0/24', $deployment->fresh()->network_subnet, 'the block stays with the row once the old stack is gone');
    }

    #[Test]
    public function a_stale_stack_of_the_same_service_on_the_block_is_retired_and_an_empty_network_removed(): void
    {
        [$deployment] = $this->deployment('10.210.5.0/24');
        $ssh = Mockery::mock(SSHService::class);
        $commands = [];
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands) {
            $commands[] = $command;
            if (str_contains($command, 'docker network inspect')) {
                return "user-156-service-38-nodejs-net|10.210.5.0/24 |2\nuser-156-service-38-old-net|10.210.5.0/24 |0\nuser-9-service-1-wordpress-net|10.210.6.0/24 |2\n";
            }

            return '';
        });
        $tornDown = [];

        $result = (new ContainerStackNetworkReconciler(new ContainerStackNetworkAllocator))->reconcile(
            $ssh,
            $deployment,
            'user-156-service-38-static-site',
            '',
            function (string $path) use (&$tornDown) {
                $tornDown[] = $path;
            },
        );

        $this->assertSame(['/opt/talksasa/containers/user-156-service-38-nodejs'], $tornDown);
        $this->assertCount(2, array_filter($commands, fn ($c) => str_contains($c, 'docker network rm')));
        $this->assertSame('10.210.5.0/24', $result['subnet']);
        $this->assertContains('retired stale stack user-156-service-38-nodejs holding 10.210.5.0/24', $result['actions']);
        $this->assertContains('removed empty network user-156-service-38-old-net on 10.210.5.0/24', $result['actions']);
    }

    #[Test]
    public function a_block_held_by_another_tenant_moves_this_stack_to_a_fresh_block(): void
    {
        [$deployment, $node] = $this->deployment('10.210.5.0/24');
        ContainerDeployment::factory()->create(['node_id' => $node->id, 'network_subnet' => '10.210.0.0/24']);
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) {
            if (str_contains($command, 'docker network inspect')) {
                // Another tenant sits on 10.210.5.0/24 and, unrecorded, on 10.210.1.0/24 too.
                return "user-9-service-1-wordpress-net|10.210.5.0/24 |2\nforgotten-net|10.210.1.0/24 |1\n";
            }

            return '';
        });
        $tornDown = [];

        $result = (new ContainerStackNetworkReconciler(new ContainerStackNetworkAllocator('10.210.0.0/16', 24)))->reconcile(
            $ssh,
            $deployment,
            'user-156-service-38-static-site',
            '',
            function (string $path) use (&$tornDown) {
                $tornDown[] = $path;
            },
        );

        $this->assertSame([], $tornDown, 'another tenant\'s stack is never touched');
        $this->assertSame('10.210.2.0/24', $result['subnet'], 'skips the database-known block and the two live blocks');
        $this->assertSame('10.210.2.0/24', $deployment->fresh()->network_subnet);
        $this->assertContains('moved this stack to 10.210.2.0/24', $result['actions']);
    }

    #[Test]
    public function nothing_happens_when_the_node_agrees_with_the_row(): void
    {
        [$deployment] = $this->deployment('10.210.5.0/24');
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->once()->andReturn("user-156-service-38-static-site-net|10.210.5.0/24 |2\n");

        $result = (new ContainerStackNetworkReconciler(new ContainerStackNetworkAllocator))->reconcile($ssh, $deployment, 'user-156-service-38-static-site', 'user-156-service-38-static-site', fn () => $this->fail('no teardown expected'));

        $this->assertSame(['subnet' => '10.210.5.0/24', 'actions' => []], $result);
    }

    /**
     * @return array{0: ContainerDeployment, 1: Node}
     */
    private function deployment(string $subnet): array
    {
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create(['node_id' => $node->id]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-156-service-38-static-site',
            'network_subnet' => $subnet,
        ]);

        return [$deployment, $node];
    }
}
