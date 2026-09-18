<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\User;
use App\Services\Provisioning\ContainerNodePortAuditService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminNodePortAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_separates_stacks_the_platform_knows_from_strays_and_host_processes(): void
    {
        $node = Node::factory()->containerHost()->create();
        ContainerDeployment::factory()->create([
            'node_id' => $node->id,
            'container_name' => 'user-1-service-2-php',
            'assigned_port' => 30001,
            'status' => 'running',
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command): string {
            if (str_contains($command, 'docker ps -a --filter label=com.docker.compose.project')) {
                return "user-1-service-2-php|app|user-1-service-2-php|running|Up 3 days|127.0.0.1:30001->80/tcp\n"
                    ."user-9-service-99-wordpress|app|user-9-service-99-wordpress|running|Up 20 days|127.0.0.1:30054->80/tcp\n";
            }

            // ss listing: the two containers plus a host process on 30090.
            return "LISTEN 0 4096 127.0.0.1:30001 0.0.0.0:*\n"
                ."LISTEN 0 4096 127.0.0.1:30054 0.0.0.0:*\n"
                ."LISTEN 0 4096 127.0.0.1:30090 0.0.0.0:*\n";
        });
        $ssh->shouldReceive('disconnect')->zeroOrMoreTimes();

        $report = app(ContainerNodePortAuditService::class)->audit($node, $ssh);

        $this->assertTrue($report['reachable']);
        $this->assertSame([30001, 30054, 30090], $report['listening_ports']);
        $this->assertSame(['user-1-service-2-php'], array_column($report['claimed'], 'project'));
        $this->assertSame(['user-9-service-99-wordpress'], array_column($report['orphans'], 'project'), 'a container with no deployment row is a stray');
        $this->assertSame([30054], $report['orphans'][0]['ports']);
        $this->assertSame([30090], $report['unexplained_ports'], 'a listener with nothing in Docker behind it');
    }

    public function test_an_unreachable_node_reports_instead_of_throwing(): void
    {
        $node = Node::factory()->containerHost()->create();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andThrow(new \RuntimeException('connection refused'));
        $ssh->shouldReceive('disconnect')->zeroOrMoreTimes();

        $report = app(ContainerNodePortAuditService::class)->audit($node, $ssh);

        $this->assertFalse($report['reachable']);
        $this->assertStringContainsString('connection refused', (string) $report['message']);
    }

    public function test_the_admin_page_offers_the_scan_for_container_hosts_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $container = Node::factory()->containerHost()->create();
        $directAdmin = Node::factory()->create(['type' => 'directadmin']);

        $this->actingAs($admin)
            ->get(route('admin.nodes.show', $container))
            ->assertOk()
            ->assertSee('Published ports')
            ->assertSee('nothing here is removed', false);

        $this->actingAs($admin)
            ->get(route('admin.nodes.show', $directAdmin))
            ->assertOk()
            ->assertDontSee('Published ports');
    }

    public function test_the_scan_route_returns_the_report_and_is_admin_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $node = Node::factory()->containerHost()->create();
        $this->mock(ContainerNodePortAuditService::class, function ($mock) {
            $mock->shouldReceive('audit')->once()->andReturn([
                'supported' => true,
                'reachable' => true,
                'message' => null,
                'listening_ports' => [30001],
                'claimed' => [],
                'orphans' => [['project' => 'stray', 'ports' => [30001], 'running' => true, 'containers' => 1, 'status' => 'Up 2 days']],
                'unexplained_ports' => [],
            ]);
        });

        $this->actingAs($admin)
            ->postJson(route('admin.nodes.port-audit', $node))
            ->assertOk()
            ->assertJsonPath('orphans.0.project', 'stray');

        $this->actingAs(User::factory()->customer()->create())
            ->postJson(route('admin.nodes.port-audit', $node))
            ->assertForbidden();
    }
}
