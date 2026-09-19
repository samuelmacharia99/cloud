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
    public function a_wider_network_over_a_block_takes_that_block(): void
    {
        $node = Node::factory()->containerHost()->create();
        $allocator = new ContainerStackNetworkAllocator('10.210.0.0/16', 24);

        // Docker rejects a block that overlaps an existing network, not just one
        // that matches it. Comparing the text of two CIDRs called this free and
        // the daemon then refused it with "Pool overlaps with other one on this
        // address space", which failed the deploy.
        $this->assertSame('10.210.4.0/24', $allocator->allocate($node, null, ['10.210.0.0/22']));
    }

    #[Test]
    public function a_network_covering_the_whole_pool_is_reported_as_a_full_node(): void
    {
        $node = Node::factory()->containerHost()->create();
        $allocator = new ContainerStackNetworkAllocator('10.210.0.0/16', 24);

        // Every candidate lies inside the pool, so a network over all of it
        // leaves nowhere to go. That is a capacity problem, and saying so is
        // what gets it classified as one rather than as a broken config.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no capacity for another stack network');
        $allocator->allocate($node, null, ['10.210.0.0/16']);
    }

    #[Test]
    public function a_longer_prefix_inside_a_block_takes_the_whole_block(): void
    {
        $node = Node::factory()->containerHost()->create();
        $allocator = new ContainerStackNetworkAllocator('10.210.0.0/16', 24);

        // A /25 occupies half of 10.210.0.0/24; the block as a whole is unusable.
        $this->assertSame('10.210.1.0/24', $allocator->allocate($node, null, ['10.210.0.128/25']));
    }

    #[Test]
    public function networks_outside_the_pool_do_not_consume_blocks(): void
    {
        $node = Node::factory()->containerHost()->create();
        $allocator = new ContainerStackNetworkAllocator('10.210.0.0/16', 24);

        // The Docker default bridge and a private LAN overlap nothing we hand out.
        $this->assertSame(
            '10.210.0.0/24',
            $allocator->allocate($node, null, ['172.17.0.0/16', '192.168.1.0/24', 'fd00::/64'])
        );
    }

    #[Test]
    public function overlap_is_measured_by_range_not_by_text(): void
    {
        $allocator = new ContainerStackNetworkAllocator('10.210.0.0/16', 24);

        $this->assertTrue($allocator->overlaps('10.210.5.0/24', '10.210.5.0/24'));
        $this->assertTrue($allocator->overlaps('10.210.5.0/24', '10.210.0.0/16'));
        $this->assertTrue($allocator->overlaps('10.210.0.0/16', '10.210.5.0/24'));
        $this->assertTrue($allocator->overlaps('10.210.5.0/24', '10.210.5.128/25'));

        $this->assertFalse($allocator->overlaps('10.210.5.0/24', '10.210.6.0/24'));
        $this->assertFalse($allocator->overlaps('10.210.5.0/24', '172.17.0.0/16'));

        // Unreadable or IPv6 input cannot be shown to conflict with an IPv4 pool.
        $this->assertFalse($allocator->overlaps('10.210.5.0/24', 'fd00::/64'));
        $this->assertFalse($allocator->overlaps('10.210.5.0/24', 'not-a-cidr'));
        $this->assertNull(ContainerStackNetworkAllocator::rangeOf('10.210.5.0/33'));
    }

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
    public function blocks_the_node_reports_live_are_skipped_even_when_no_row_records_them(): void
    {
        $node = Node::factory()->containerHost()->create();
        $allocator = new ContainerStackNetworkAllocator('10.210.0.0/16', 24);
        $this->deployment($node, '10.210.0.0/24');

        $this->assertSame('10.210.3.0/24', $allocator->allocate($node, null, ['10.210.1.0/24', '10.210.2.0/24']));

        $row = $this->deployment($node, '10.210.7.0/24');
        $this->assertSame('10.210.1.0/24', $allocator->reallocate($row, ['10.210.7.0/24']));
        $this->assertSame('10.210.1.0/24', $row->fresh()->network_subnet);
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
