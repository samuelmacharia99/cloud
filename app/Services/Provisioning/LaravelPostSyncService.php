<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class LaravelPostSyncService
{
    public function __construct(
        private ContainerAppDirectoryService $appDirectory,
        private LaravelAppInitializationService $initialization,
        private LaravelProjectPathResolver $pathResolver,
        private ContainerDeploymentService $deploymentService,
    ) {}

    public function run(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        LaravelPostSyncOptions $options
    ): string {
        if (($service->product?->containerTemplate?->slug ?? '') !== 'laravel') {
            return '';
        }

        if (! $this->appDirectory->hasLaravelProject($ssh, $deployment)) {
            return '';
        }

        $messages = [];
        $this->pathResolver->persistResolvedPaths($service, $ssh, $deployment);
        $projectRoot = $this->pathResolver->projectRootFromServiceMeta($service);

        if ($options->refreshRuntime) {
            $message = $this->deploymentService->refreshLaravelServeCompose($service, $deployment, $ssh);
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        if ($options->configureEnvironment) {
            $messages[] = $this->initialization->writeApplicationEnvironment($service, $deployment, $ssh);
        }

        if ($options->runComposer) {
            $this->initialization->runComposerInstall($ssh, $deployment, null, $service);
            $messages[] = 'Composer dependencies installed.';
        }

        if ($options->configureEnvironment) {
            $messages[] = $this->initialization->bootstrapApplicationEnvironment($service, $deployment, $ssh);
        }

        if ($options->runMigrations) {
            if ($options->waitForDatabase) {
                $this->waitForApplicationDatabase($service, $deployment, $ssh);
            }

            try {
                $this->initialization->runApplicationMigrations($service, $ssh, $deployment);
                $messages[] = 'Database migrations applied.';
            } catch (\Throwable $e) {
                $messages[] = 'Migrations could not run automatically: '.$e->getMessage();
            }
        } elseif (! $options->runMigrations) {
            $this->syncDatabaseCredentialsOnly($service, $deployment, $ssh);
        }

        if ($options->finalizeApplication) {
            $messages[] = $this->runFinalizeApplication($ssh, $deployment, $projectRoot);
        }

        if ($options->normalizePermissions) {
            $this->appDirectory->normalizeLaravelPermissions($ssh, $deployment, $projectRoot);
            $messages[] = 'Laravel filesystem permissions normalized.';
        }

        if ($options->requireHttpHealth) {
            $this->deploymentService->waitForLaravelHttpHealth($ssh, $deployment);
            $messages[] = 'Laravel HTTP health check passed.';
        }

        return implode(' ', array_filter($messages));
    }

    public function finalizeApplication(SSHService $ssh, ContainerDeployment $deployment, string $projectRoot): string
    {
        return $this->runFinalizeApplication($ssh, $deployment, $projectRoot);
    }

    private function runFinalizeApplication(SSHService $ssh, ContainerDeployment $deployment, string $projectRoot): string
    {
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);
        $root = escapeshellarg($projectRoot);
        $parts = [];

        try {
            $this->initialization->dockerExecPublic(
                $ssh,
                $deployment->container_name,
                'set -e; cd '.$root.'; php artisan storage:link --force --no-interaction 2>/dev/null || true',
                60
            );
            $parts[] = 'storage link ensured';
        } catch (\Throwable) {
            // Some apps use custom public layouts without the storage:link command.
        }

        try {
            $this->initialization->dockerExecPublic(
                $ssh,
                $deployment->container_name,
                'set -e; cd '.$root.'; php artisan config:cache --no-interaction',
                $timeout
            );
            $parts[] = 'config cached';
        } catch (\Throwable) {
            $parts[] = 'config cache skipped';
        }

        return 'Laravel finalize: '.implode(', ', $parts).'.';
    }

    private function waitForApplicationDatabase(Service $service, ContainerDeployment $deployment, SSHService $ssh): void
    {
        $databaseTemplate = $this->deploymentService->resolveDatabaseTemplateForService($service);
        if (! $databaseTemplate) {
            throw new \RuntimeException('Cannot run Laravel migrations: no database sidecar is configured for this service.');
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
    }

    private function syncDatabaseCredentialsOnly(Service $service, ContainerDeployment $deployment, SSHService $ssh): void
    {
        $databaseTemplate = $this->deploymentService->resolveDatabaseTemplateForService($service);
        if (! $databaseTemplate) {
            return;
        }

        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $envValues = is_array($deployment->env_values) ? $deployment->env_values : [];

        try {
            $this->deploymentService->waitForDatabaseSidecar($ssh, $containerPath, $databaseTemplate, $envValues, 60);
        } catch (\Throwable $e) {
            \Log::warning('Database credential sync skipped during Laravel post-sync: DB not ready', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
