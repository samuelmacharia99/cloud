<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDeploymentPhpHealTest extends TestCase
{
    #[Test]
    public function php_runtime_compose_is_healed_even_when_the_container_name_is_still_static_site(): void
    {
        $service = app(ContainerDeploymentService::class);
        $deployment = new ContainerDeployment([
            'container_name' => 'user-483-service-426-static-site',
            'docker_compose_content' => "services:\n  app:\n    image: talksasa/php-runtime:8.3-r8\n    command: talksasa-php-server 8000 /app\n",
        ]);

        $this->assertTrue($service->deploymentNeedsPhpHeal($deployment, 'static-site'));
        $this->assertTrue($service->deploymentNeedsPhpHeal($deployment, 'php'));
        $this->assertFalse($service->deploymentNeedsPhpHeal(new ContainerDeployment([
            'docker_compose_content' => "services:\n  app:\n    image: nginx:alpine\n",
        ]), 'static-site'));
    }
}
