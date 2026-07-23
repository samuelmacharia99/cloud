<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDeploymentComposeConflictTest extends TestCase
{
    #[Test]
    public function it_detects_docker_container_name_conflicts(): void
    {
        $service = app(ContainerDeploymentService::class);

        $message = 'Error response from daemon: Conflict. The container name "/b0a1c30fec04_user-427-service-137-nodejs" is already in use by container "a7ea987621f93f7a6c86c067286a768ab2aac4abac3c1d113081cbcd15bb5570".';

        $this->assertTrue($service->isDockerContainerNameConflict($message));
        $this->assertFalse($service->isDockerContainerNameConflict('npm install failed'));
    }

    #[Test]
    public function it_extracts_conflicting_container_name_and_id(): void
    {
        $service = app(ContainerDeploymentService::class);

        $message = 'Conflict. The container name "/b0a1c30fec04_user-427-service-137-nodejs" is already in use by container "a7ea987621f93f7a6c86c067286a768ab2aac4abac3c1d113081cbcd15bb5570".';

        $this->assertSame([
            'b0a1c30fec04_user-427-service-137-nodejs',
            'a7ea987621f93f7a6c86c067286a768ab2aac4abac3c1d113081cbcd15bb5570',
        ], $service->conflictingDockerRefsFromError($message));
    }
}
