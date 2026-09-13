<?php

namespace Tests\Unit\Console;

use App\Console\Commands\AutoRestartContainersCommand;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\Provisioning\StackMemberResolver;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Auto-restart used to notice only a WordPress "-mysql" sidecar. Every
 * database the stack runs counts now, decided from a live inspect.
 */
class AutoRestartDatabaseMembersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_stopped_injected_db_sidecar_marks_the_stack_as_needing_a_start(): void
    {
        $deployment = $this->deployment(<<<'YAML'
services:
  user-1-service-9-laravel:
    image: talksasa/laravel-runtime:8.3
    container_name: user-1-service-9-laravel
  db:
    image: postgres:16
    container_name: user-1-service-9-laravel-db
YAML);

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->once()->with(Mockery::type(SSHService::class), 'user-1-service-9-laravel-db')
            ->andReturn(['missing' => false, 'running' => false, 'state' => 'exited']);

        $this->assertTrue($this->needsStart($inspector, $deployment));
        $this->assertSame(90, $this->waitSeconds($inspector, $deployment));
    }

    #[Test]
    public function a_wordpress_mysql_sidecar_is_still_covered_and_a_stack_without_a_database_waits_less(): void
    {
        $wordpress = $this->deployment(<<<'YAML'
services:
  user-1-service-9-wordpress:
    image: wordpress:6
    container_name: user-1-service-9-wordpress
  mysql:
    image: mysql:8
    container_name: user-1-service-9-wordpress-mysql
YAML);
        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->once()->with(Mockery::type(SSHService::class), 'user-1-service-9-wordpress-mysql')
            ->andReturn(['missing' => false, 'running' => true, 'state' => 'running']);

        $this->assertFalse($this->needsStart($inspector, $wordpress));

        $plain = $this->deployment("services:\n  user-1-service-9-nodejs:\n    image: node:20\n");
        $quiet = Mockery::mock(ContainerRuntimeInspector::class);
        $quiet->shouldNotReceive('inspect');

        $this->assertFalse($this->needsStart($quiet, $plain));
        $this->assertSame(30, $this->waitSeconds($quiet, $plain));
    }

    private function needsStart(ContainerRuntimeInspector $inspector, ContainerDeployment $deployment): bool
    {
        $command = new AutoRestartContainersCommand($inspector, new StackMemberResolver);
        $method = new ReflectionMethod(AutoRestartContainersCommand::class, 'embeddedDatabaseSidecarNeedsStart');

        return $method->invoke($command, Mockery::mock(SSHService::class), $deployment);
    }

    private function waitSeconds(ContainerRuntimeInspector $inspector, ContainerDeployment $deployment): int
    {
        $command = new AutoRestartContainersCommand($inspector, new StackMemberResolver);
        $method = new ReflectionMethod(AutoRestartContainersCommand::class, 'restartWaitSeconds');

        return $method->invoke($command, $deployment);
    }

    private function deployment(string $yaml): ContainerDeployment
    {
        $service = Service::factory()->create(['status' => 'active', 'provisioning_driver_key' => 'container']);

        return ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'docker_compose_content' => $yaml,
        ])->fresh('service');
    }
}
