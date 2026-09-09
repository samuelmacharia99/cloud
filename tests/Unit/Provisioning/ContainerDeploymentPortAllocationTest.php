<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDeploymentPortAllocationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retry_reuses_the_same_host_port_instead_of_hopping_to_the_next_one(): void
    {
        $node = Node::factory()->containerHost()->create();
        ContainerDeployment::factory()->create([
            'node_id' => $node->id,
            'assigned_port' => 30000,
        ]);
        $ours = ContainerDeployment::factory()->create([
            'node_id' => $node->id,
            'assigned_port' => 30001,
        ]);

        $port = DB::transaction(fn () => app(ContainerDeploymentService::class)->assignPort(
            $node,
            30001,
            [(int) $ours->id],
        ));

        $this->assertSame(30001, $port);
    }

    #[Test]
    public function a_preferred_port_taken_by_another_stack_is_skipped(): void
    {
        $node = Node::factory()->containerHost()->create();
        ContainerDeployment::factory()->create([
            'node_id' => $node->id,
            'assigned_port' => 30001,
        ]);
        $ours = ContainerDeployment::factory()->create([
            'node_id' => $node->id,
            'assigned_port' => 30002,
        ]);

        $port = DB::transaction(fn () => app(ContainerDeploymentService::class)->assignPort(
            $node,
            30001,
            [(int) $ours->id],
        ));

        $this->assertSame(30000, $port);
    }

    #[Test]
    public function host_busy_ports_are_excluded_even_when_the_database_thinks_they_are_free(): void
    {
        $node = Node::factory()->containerHost()->create();
        $ours = ContainerDeployment::factory()->create([
            'node_id' => $node->id,
            'assigned_port' => 30001,
        ]);

        $port = DB::transaction(fn () => app(ContainerDeploymentService::class)->assignPort(
            $node,
            30001,
            [(int) $ours->id],
            [30000, 30001],
        ));

        $this->assertSame(30002, $port);
    }
}
