<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerFileOperationProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerFileOperationProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_bound_to_the_service_that_started_them(): void
    {
        $service = Service::factory()->create();
        $other = Service::factory()->create();
        $deployment = ContainerDeployment::factory()->create(['service_id' => $service->id]);
        $progress = app(ContainerFileOperationProgress::class);

        $state = $progress->start($service, $deployment, ContainerFileOperationProgress::TYPE_EXTRACT, ['archive' => '/a.zip']);

        $this->assertSame('queued', $state['status']);
        $this->assertSame(1, $state['percent']);
        $this->assertNotNull($progress->find($state['token'], $service));
        $this->assertNull($progress->find($state['token'], $other));
        $this->assertNull($progress->find('00000000-0000-0000-0000-000000000000', $service));
    }

    public function test_lifecycle_updates_percent_label_result_and_error(): void
    {
        $service = Service::factory()->create();
        $deployment = ContainerDeployment::factory()->create(['service_id' => $service->id]);
        $progress = app(ContainerFileOperationProgress::class);
        $token = $progress->start($service, $deployment, ContainerFileOperationProgress::TYPE_ARCHIVE)['token'];

        $progress->update($token, 150, 'Packing');
        $state = $progress->find($token);
        $this->assertSame('running', $state['status']);
        $this->assertSame(99, $state['percent']);
        $this->assertTrue($progress->isActive($state));

        $progress->complete($token, 'Ready', ['remote_path' => '/tmp/x.zip', 'name' => 'x.zip']);
        $state = $progress->find($token);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(100, $state['percent']);
        $this->assertSame('x.zip', $state['result']['name']);
        $this->assertFalse($progress->isActive($state));

        $progress->markDownloaded($token);
        $this->assertSame('downloaded', $progress->find($token)['status']);
        $this->assertNull($progress->find($token)['result']);

        $progress->fail($token, 'boom');
        $state = $progress->find($token);
        $this->assertSame('failed', $state['status']);
        $this->assertSame('boom', $state['error']);
    }
}
