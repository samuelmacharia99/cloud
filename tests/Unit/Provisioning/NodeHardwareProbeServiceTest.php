<?php

namespace Tests\Unit\Provisioning;

use App\Models\Node;
use App\Services\Provisioning\NodeHardwareProbeService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NodeHardwareProbeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_persists_detected_hardware_and_marks_the_node_online(): void
    {
        $node = Node::factory()->containerHost()->create([
            'cpu_cores' => 0,
            'ram_gb' => 0,
            'storage_gb' => 0,
            'status' => 'offline',
            'is_active' => false,
        ]);

        $result = app(NodeHardwareProbeService::class)->probeAndPersist($node, $this->sshReturningHealthyHost());

        $this->assertTrue($result['success']);
        $this->assertSame(8, $result['cpu_cores']);
        $this->assertSame(16, $result['ram_gb']);
        $this->assertSame(500, $result['storage_gb']);

        $node->refresh();
        $this->assertSame(8, $node->cpu_cores);
        $this->assertSame(16, $node->ram_gb);
        $this->assertSame(500, $node->storage_gb);
        $this->assertSame(4, $node->ram_used_gb);
        $this->assertSame(50, $node->storage_used_gb);
        $this->assertSame('online', $node->status);
        $this->assertTrue($node->is_active);
        $this->assertNotNull($node->last_heartbeat_at);
        $this->assertSame(1, $node->monitoring()->count());
    }

    public function test_probe_fails_without_changing_specs_when_ssh_throws(): void
    {
        $node = Node::factory()->containerHost()->create([
            'cpu_cores' => 4,
            'ram_gb' => 8,
            'storage_gb' => 100,
            'status' => 'offline',
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->once()->andThrow(new \RuntimeException('Connection refused'));

        $result = app(NodeHardwareProbeService::class)->probeAndPersist($node, $ssh);

        $this->assertFalse($result['success']);
        $this->assertSame('ssh', $result['reason']);
        $this->assertSame('Connection refused', $result['message']);

        $node->refresh();
        $this->assertSame(4, $node->cpu_cores);
        $this->assertSame(8, $node->ram_gb);
        $this->assertSame(100, $node->storage_gb);
        $this->assertSame('offline', $node->status);
        $this->assertSame(0, $node->monitoring()->count());
    }

    public function test_probe_rejects_unusable_cpu_or_ram_without_persisting(): void
    {
        $node = Node::factory()->containerHost()->create([
            'cpu_cores' => 2,
            'ram_gb' => 4,
            'storage_gb' => 80,
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command): string {
            return match (true) {
                str_contains($command, 'echo') => 'SSH connection OK',
                str_contains($command, 'uptime') => 'up 1 hour',
                str_contains($command, 'free -b') => 'Mem: 0 0 0',
                str_contains($command, 'df ') => '/dev/sda1 0 0 0 0% /',
                str_contains($command, 'cpuinfo') => '0',
                str_contains($command, 'loadavg') => '0.00 0.00 0.00',
                default => '',
            };
        });

        $result = app(NodeHardwareProbeService::class)->probeAndPersist($node, $ssh);

        $this->assertFalse($result['success']);
        $this->assertSame('parse', $result['reason']);

        $node->refresh();
        $this->assertSame(2, $node->cpu_cores);
        $this->assertSame(4, $node->ram_gb);
        $this->assertSame(80, $node->storage_gb);
    }

    public function test_probe_requires_ssh_password(): void
    {
        $node = Node::factory()->containerHost()->create([
            'ssh_password' => null,
            'cpu_cores' => 1,
        ]);

        $result = app(NodeHardwareProbeService::class)->probeAndPersist($node);

        $this->assertFalse($result['success']);
        $this->assertSame('credentials', $result['reason']);
        $this->assertSame(1, $node->fresh()->cpu_cores);
    }

    private function sshReturningHealthyHost(): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command): string {
            return match (true) {
                str_contains($command, 'echo') => 'SSH connection OK',
                str_contains($command, 'uptime') => 'up 2 days, 3 hours',
                str_contains($command, 'free -b') => 'Mem: 17179869184 4294967296 12884901888',
                str_contains($command, 'df ') => '/dev/sda1 536870912000 53687091200 483183820800 10% /opt/talksasa/containers',
                str_contains($command, 'cpuinfo') => '8',
                str_contains($command, 'loadavg') => '0.50 0.40 0.30',
                default => '',
            };
        });

        return $ssh;
    }
}
