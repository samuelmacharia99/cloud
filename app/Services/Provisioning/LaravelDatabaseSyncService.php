<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class LaravelDatabaseSyncService
{
    public function __construct(
        private LaravelPostSyncService $postSync,
        private ContainerAppDirectoryService $appDirectory,
    ) {}

    public function syncIfInstalled(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        bool $runMigrations = false,
        bool $isRedeploy = false,
    ): ?string {
        if (($service->product?->containerTemplate?->slug ?? '') !== 'laravel') {
            return null;
        }

        if (! $this->appDirectory->hasLaravelProject($ssh, $deployment)) {
            return null;
        }

        $shouldMigrate = $runMigrations || ! $isRedeploy;

        return $this->postSync->run($service, $deployment, $ssh, new LaravelPostSyncOptions(
            refreshRuntime: false,
            configureEnvironment: true,
            runComposer: true,
            runMigrations: $shouldMigrate,
            finalizeApplication: true,
            normalizePermissions: true,
            waitForDatabase: $shouldMigrate,
            requireHttpHealth: (bool) config('containers.laravel_init.require_http_health', true),
        ));
    }
}
