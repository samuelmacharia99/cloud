<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;

class ContainerNodeBuildPrepService
{
    public const PREPARE_SCRIPT_RELATIVE_PATH = '.talksasa/prepare-build.cjs';

    public function prepareBuildShellPrefix(): string
    {
        return 'node '.self::PREPARE_SCRIPT_RELATIVE_PATH.' && ';
    }

    public function syncPrepareScriptToHost(SSHService $ssh, string $hostAppPath): void
    {
        $base = rtrim($hostAppPath, '/');
        $allowedBase = rtrim(ContainerDeploymentService::CONTAINER_BASE_PATH, '/');

        if ($base === '' || ! str_starts_with($base, $allowedBase.'/')) {
            throw new \InvalidArgumentException('Invalid host app path for Node build preparation.');
        }

        $script = file_get_contents(resource_path('container-templates/nodejs/prepare-build.cjs'));

        if ($script === false || trim($script) === '') {
            throw new \RuntimeException('Node build preparation script is missing.');
        }

        $ssh->mkdirp($base.'/.talksasa');
        $ssh->upload($script, $base.'/'.self::PREPARE_SCRIPT_RELATIVE_PATH);
    }

    public function packageJsonRequiresProductionBuild(?string $packageJson): bool
    {
        return app(ContainerApplicationRuntimeService::class)->packageJsonRequiresProductionBuild($packageJson);
    }
}
