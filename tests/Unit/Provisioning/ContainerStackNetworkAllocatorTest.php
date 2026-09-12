<?php

namespace Tests\Unit\Provisioning;

use App\Enums\ProvisionFailureClass;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerStackNetworkAllocator;
use App\Services\Provisioning\ProvisionFailureLedger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerStackNetworkAllocatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function blocks_are_handed_out_in_order_skipping_what_the_node_already_uses(): void
    {
        $node = Node::factory()->containerHost()->create();
        $other = Node::factory()->containerHost()->create();
        $allocator = new ContainerStackNetworkAllocator('10.210.0.0/16', 24);

        $this->assertSame(256, $allocator->capacity());
        $this->assertSame('10.210.0.0/24', $allocator->allocate($node));

        $this->deployment($node, '10.210.0.0/24');
        $this->deployment($node, '10.210.1.0/24');
        // A neighbour's allocation on another node is not this node's business.
        $this->deployment($other, '10.210.2.0/24');

        $this->assertSame('10.210.2.0/24', $allocator->allocate($node));
    }

    #[Test]
    public function a_deployment_keeps_its_subnet_and_its_own_row_is_not_a_conflict(): void
    {
        $node = Node::factory()->containerHost()->create();
        $allocator = new ContainerStackNetworkAllocator('10.210.0.0/16', 24);
        $deployment = $this->deployment($node, null);

        $this->assertSame('10.210.0.0/24', $allocator->ensureFor($deployment));
        $this->assertSame('10.210.0.0/24', $deployment->fresh()->network_subnet);
        $this->assertSame('10.210.0.0/24', $allocator->ensureFor($deployment->fresh()));

        // Re-allocating for the same deployment (a migration target) ignores the row it owns.
        $this->assertSame('10.210.0.0/24', $allocator->allocate($node, $deployment->id));
        $this->assertSame('10.210.1.0/24', $allocator->allocate($node));
    }

    #[Test]
    public function a_full_node_is_reported_as_a_capacity_problem(): void
    {
        $node = Node::factory()->containerHost()->create();
        $allocator = new ContainerStackNetworkAllocator('10.250.0.0/29', 30);

        $this->assertSame(2, $allocator->capacity());
        $this->deployment($node, '10.250.0.0/30');
        $this->deployment($node, '10.250.0.4/30');

        try {
            $allocator->allocate($node);
            $this->fail('Expected the pool to be exhausted.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('no capacity', $e->getMessage());
            $this->assertSame(ProvisionFailureClass::Capacity, app(ProvisionFailureLedger::class)->classify($e));
        }
    }

    #[Test]
    public function the_database_refuses_two_stacks_on_one_subnet_of_the_same_node(): void
    {
        $node = Node::factory()->containerHost()->create();
        $this->deployment($node, '10.210.5.0/24');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->deployment($node, '10.210.5.0/24');
    }

    #[Test]
    public function the_pool_definition_is_validated_up_front(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ContainerStackNetworkAllocator('10.210.0.0/16', 16);
    }

    private function deployment(Node $node, ?string $subnet): ContainerDeployment
    {
        return ContainerDeployment::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'node_id' => $node->id,
            'network_subnet' => $subnet,
        ]);
    }
}
