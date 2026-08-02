<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ContainerDatabaseAccessErrorClassificationTest extends TestCase
{
    #[Test]
    public function it_detects_missing_pdo_from_command_output_only(): void
    {
        $service = new ContainerDeploymentService;
        $method = new ReflectionMethod(ContainerDeploymentService::class, 'isMissingDatabaseDriverError');
        $method->setAccessible(true);

        $passwordFailureWithCommandBody = 'SSH command failed: docker exec php -r '
            .'\'if (!in_array("pgsql"...)) { fwrite(STDERR, "missing_pdo_pgsql"); exit(2);}\' '
            .'Error: Command exited with status 1 Output: SQLSTATE[08006] [7] password authentication failed for user "u193_s163"';

        $this->assertFalse($method->invoke($service, $passwordFailureWithCommandBody));

        $missingDriver = 'SSH command failed: docker exec php -r \'...\' '
            .'Error: Command exited with status 2 Output: missing_pdo_pgsql';

        $this->assertTrue($method->invoke($service, $missingDriver));
    }

    #[Test]
    public function it_detects_password_authentication_failures_from_output(): void
    {
        $service = new ContainerDeploymentService;
        $method = new ReflectionMethod(ContainerDeploymentService::class, 'isDatabasePasswordAuthenticationError');
        $method->setAccessible(true);

        $message = 'SSH command failed: docker exec ... Error: Command exited with status 1 '
            .'Output: SQLSTATE[08006] [7] connection to server at "db" (192.168.128.2), port 5432 failed: '
            .'FATAL: password authentication failed for user "u193_s163"';

        $this->assertTrue($method->invoke($service, $message));
    }
}
