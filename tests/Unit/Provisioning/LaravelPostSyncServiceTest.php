<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Service;
use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\LaravelAppInitializationService;
use App\Services\Provisioning\LaravelPostSyncOptions;
use App\Services\Provisioning\LaravelPostSyncService;
use App\Services\Provisioning\LaravelProjectPathResolver;
use App\Services\SSH\SSHService;
use Mockery;
use Tests\TestCase;

class LaravelPostSyncServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_migration_failure_fails_the_post_sync_instead_of_reporting_success(): void
    {
        $service = new Service;
        $deployment = new ContainerDeployment;
        $template = new ContainerTemplate(['slug' => 'laravel']);
        $ssh = Mockery::mock(SSHService::class);
        $appDirectory = Mockery::mock(ContainerAppDirectoryService::class);
        $initialization = Mockery::mock(LaravelAppInitializationService::class);
        $pathResolver = Mockery::mock(LaravelProjectPathResolver::class);
        $deploymentService = Mockery::mock(ContainerDeploymentService::class);

        $deploymentService->shouldReceive('resolveContainerTemplate')->once()->with($service)->andReturn($template);
        $appDirectory->shouldReceive('hasLaravelProject')->once()->andReturn(true);
        $pathResolver->shouldReceive('persistResolvedPaths')->once();
        $pathResolver->shouldReceive('projectRootFromServiceMeta')->once()->andReturn('/app');
        $initialization->shouldReceive('runApplicationMigrations')
            ->once()
            ->andThrow(new \RuntimeException('migration failed'));

        $postSync = new LaravelPostSyncService(
            $appDirectory,
            $initialization,
            $pathResolver,
            $deploymentService,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration failed');

        $postSync->run($service, $deployment, $ssh, new LaravelPostSyncOptions(
            configureEnvironment: false,
            runComposer: false,
            runMigrations: true,
            finalizeApplication: false,
            normalizePermissions: false,
            waitForDatabase: false,
        ));
    }
}
