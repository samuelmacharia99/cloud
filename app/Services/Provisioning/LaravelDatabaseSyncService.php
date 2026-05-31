<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class LaravelDatabaseSyncService
{
    public function __construct(
        private LaravelAppInitializationService $initialization,
        private ContainerAppDirectoryService $appDirectory,
        private ContainerDeploymentService $deploymentService,
    ) {}

    public function syncIfInstalled(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        bool $runMigrations = false
    ): ?string {
        if (($service->product?->containerTemplate?->slug ?? '') !== 'laravel') {
            return null;
        }

        if (! $this->appDirectory->hasLaravelProject($ssh, $deployment)) {
            return null;
        }

        $message = $this->initialization->configureApplicationEnvironment($service, $deployment, $ssh);

        if (! $runMigrations) {
            return $message;
        }

        $databaseTemplate = $this->deploymentService->resolveDatabaseTemplateForService($service);
        if (! $databaseTemplate) {
            throw new \RuntimeException('Cannot sync Laravel database: no database sidecar is configured for this service.');
        }

        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $envValues = is_array($deployment->env_values) ? $deployment->env_values : [];

        $this->deploymentService->waitForDatabaseSidecar($ssh, $containerPath, $databaseTemplate, $envValues);
        $this->deploymentService->waitForApplicationDatabaseAccess(
            $ssh,
            $deployment->container_name,
            $databaseTemplate,
            $envValues
        );

        try {
            $this->initialization->runApplicationMigrations($service, $ssh, $deployment);

            return $message.' Migrations applied.';
        } catch (\Throwable $e) {
            return $message.' Migrations could not run automatically: '.$e->getMessage();
        }
    }
}
