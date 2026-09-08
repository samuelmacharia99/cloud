<?php

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\User;
use App\Services\Provisioning\NodeHardwareProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminContainerHostNodeCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_explains_that_specs_are_detected_on_save(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('admin.nodes.create', ['type' => 'container_host']))
            ->assertOk()
            ->assertSee('Leave these blank')
            ->assertSee('fill CPU, RAM, and disk from the host')
            ->assertSee('Auto-detected');
    }

    public function test_creating_a_container_host_autofills_specs_from_ssh(): void
    {
        $this->fakeSuccessfulProbe(8, 32, 500);

        $response = $this->actingAs($this->adminUser())->post(route('admin.nodes.store'), $this->validPayload());

        $node = Node::query()->where('hostname', 'app-01.example.com')->first();

        $this->assertNotNull($node);
        $response->assertRedirect(route('admin.nodes.show', $node));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('Detected 8 CPU cores, 32 GB RAM, 500 GB disk', session('success'));

        $this->assertSame('container_host', $node->type);
        $this->assertSame(8, $node->cpu_cores);
        $this->assertSame(32, $node->ram_gb);
        $this->assertSame(500, $node->storage_gb);
        $this->assertSame('online', $node->status);
        $this->assertSame('root-secret', $node->ssh_password);
    }

    public function test_manual_specs_are_kept_when_ssh_probe_fails(): void
    {
        $this->fakeFailedProbe('Connection refused');

        $response = $this->actingAs($this->adminUser())->post(route('admin.nodes.store'), $this->validPayload([
            'cpu_cores' => 4,
            'ram_gb' => 16,
            'storage_gb' => 200,
        ]));

        $node = Node::query()->where('hostname', 'app-01.example.com')->first();

        $this->assertNotNull($node);
        $response->assertRedirect(route('admin.nodes.show', $node));
        $response->assertSessionHas('warning');
        $this->assertStringContainsString('Connection refused', session('warning'));

        $this->assertSame(4, $node->cpu_cores);
        $this->assertSame(16, $node->ram_gb);
        $this->assertSame(200, $node->storage_gb);
        $this->assertSame('offline', $node->status);
    }

    public function test_container_host_create_requires_ssh_password(): void
    {
        $this->actingAs($this->adminUser())
            ->from(route('admin.nodes.create', ['type' => 'container_host']))
            ->post(route('admin.nodes.store'), $this->validPayload([
                'ssh_password' => '',
            ]))
            ->assertRedirect(route('admin.nodes.create', ['type' => 'container_host']))
            ->assertSessionHasErrors('ssh_password');

        $this->assertSame(0, Node::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'container_host',
            'name' => 'App Host 01',
            'hostname' => 'app-01.example.com',
            'ip_address' => '10.10.10.10',
            'ssh_port' => '22',
            'ssh_username' => 'root',
            'ssh_password' => 'root-secret',
            'region' => 'KE-NBO',
            'is_active' => '1',
        ], $overrides);
    }

    private function fakeSuccessfulProbe(int $cpuCores, int $ramGb, int $storageGb): void
    {
        $this->mock(NodeHardwareProbeService::class, function ($mock) use ($cpuCores, $ramGb, $storageGb) {
            $mock->shouldReceive('probeAndPersist')
                ->once()
                ->andReturnUsing(function (Node $node) use ($cpuCores, $ramGb, $storageGb) {
                    $node->update([
                        'cpu_cores' => $cpuCores,
                        'ram_gb' => $ramGb,
                        'storage_gb' => $storageGb,
                        'status' => 'online',
                        'is_active' => true,
                    ]);

                    return [
                        'success' => true,
                        'reason' => 'ok',
                        'message' => 'Hardware specs detected.',
                        'cpu_cores' => $cpuCores,
                        'ram_gb' => $ramGb,
                        'storage_gb' => $storageGb,
                        'ram_used_gb' => 4,
                        'storage_used_gb' => 40,
                        'cpu_used' => 6,
                        'uptime' => 'up 1 hour',
                        'load_average' => 0.5,
                    ];
                });
        });
    }

    private function fakeFailedProbe(string $message): void
    {
        $this->mock(NodeHardwareProbeService::class, function ($mock) use ($message) {
            $mock->shouldReceive('probeAndPersist')
                ->once()
                ->andReturn([
                    'success' => false,
                    'reason' => 'ssh',
                    'message' => $message,
                    'cpu_cores' => 0,
                    'ram_gb' => 0,
                    'storage_gb' => 0,
                    'ram_used_gb' => 0,
                    'storage_used_gb' => 0,
                    'cpu_used' => 0,
                    'uptime' => '',
                    'load_average' => 0.0,
                ]);
        });
    }

    private function adminUser(): User
    {
        return User::factory()->admin()->create();
    }
}
