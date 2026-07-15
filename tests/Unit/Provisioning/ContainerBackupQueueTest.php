<?php

namespace Tests\Unit\Provisioning;

use App\Jobs\CreateContainerBackupJob;
use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ContainerBackupQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_backup_creates_queued_row_and_dispatches_job(): void
    {
        Bus::fake();

        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        $service->load('containerDeployment.node');

        $backup = (new ContainerBackupService)->queueBackup($service, 'manual');

        $this->assertSame('pending', $backup->status);
        $this->assertSame($service->id, $backup->service_id);
        Bus::assertDispatched(CreateContainerBackupJob::class, fn (CreateContainerBackupJob $job) => $job->backupId === $backup->id);
    }

    public function test_queue_backup_blocks_when_another_backup_is_in_flight(): void
    {
        Bus::fake();

        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        $service->load('containerDeployment.node');

        ContainerBackup::factory()->create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already queued or running');

        (new ContainerBackupService)->queueBackup($service, 'manual');
    }
}
