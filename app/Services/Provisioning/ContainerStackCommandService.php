<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerStackCommandService
{
    private ?ContainerDeploymentService $resolvedDeploymentService = null;

    public function __construct(
        private ?ContainerApplicationRuntimeService $runtimeService = null,
    ) {
        $this->runtimeService ??= new ContainerApplicationRuntimeService;
    }

    private function deploymentService(): ContainerDeploymentService
    {
        return $this->resolvedDeploymentService ??= app(ContainerDeploymentService::class);
    }

    public function resolveWorkDir(object $template): string
    {
        $volumePaths = $template->volume_paths ?? null;
        if (is_array($volumePaths) && isset($volumePaths['app_data'])) {
            return (string) $volumePaths['app_data'];
        }

        if (is_array($volumePaths) && isset($volumePaths['wp_data'])) {
            return (string) $volumePaths['wp_data'];
        }

        return match ($template->slug ?? null) {
            'strapi' => '/srv/app',
            'wordpress' => '/var/www/html',
            default => '/app',
        };
    }

    public function isSafeCommand(string $command): bool
    {
        $cmd = trim($command);
        if ($cmd === '' || strlen($cmd) > 500) {
            return false;
        }

        if (preg_match('/[;&|`$<>\\\\]/', $cmd)) {
            return false;
        }

        return true;
    }

    public function isLongRunningCommand(string $command): bool
    {
        $cmd = strtolower(trim($command));
        $blocked = [
            'npm start',
            'npm run start',
            'yarn start',
            'pnpm start',
            'rails server',
            'rails s',
            'python manage.py runserver',
            'php artisan serve',
            'forever start',
            'pm2 start',
        ];

        foreach ($blocked as $pattern) {
            if ($cmd === $pattern || str_starts_with($cmd, $pattern.' ')) {
                return true;
            }
        }

        return false;
    }

    public function executeSetupCommands(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        object $template,
        int $timeout = 600
    ): void {
        $commands = $template->setup_commands ?? null;
        if (! is_array($commands) || $commands === []) {
            return;
        }

        $workDir = $this->resolveWorkDir($template);

        foreach ($commands as $command) {
            if (! is_string($command) || trim($command) === '') {
                continue;
            }

            if (! $this->isSafeCommand($command)) {
                \Log::warning('Skipped unsafe setup command', ['command' => $command]);

                continue;
            }

            if ($this->isLongRunningCommand($command)) {
                \Log::info('Skipped long-running setup command', ['command' => $command]);

                continue;
            }

            try {
                $this->execInContainer($ssh, $containerPath, $containerName, $command, $workDir, $timeout);
            } catch (\Throwable $e) {
                \Log::warning('Setup command failed', [
                    'command' => $command,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function stopApplicationContainerForMaintenance(
        SSHService $ssh,
        string $containerPath,
        string $containerName
    ): void {
        $this->stopApplicationServiceForMaintenance($ssh, $containerPath, $containerName);
    }

    /**
     * @return list<string>
     */
    public function runPostPullSteps(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        bool $forceRebuild = false
    ): array {
        $slug = $service->product?->containerTemplate?->slug ?? '';
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $containerName = $deployment->container_name;
        $hostAppPath = app(ContainerAppDirectoryService::class)->hostAppPath($deployment);
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);

        return match ($slug) {
            'nodejs' => $this->installNodeDependencies(
                $ssh,
                $containerPath,
                $containerName,
                $hostAppPath,
                $deployment,
                $timeout,
                $forceRebuild
            ),
            'ruby' => $this->installRubyDependencies($ssh, $containerPath, $containerName, $hostAppPath, $timeout),
            'python' => $this->installPythonDependencies($ssh, $containerPath, $containerName, $hostAppPath, $timeout),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function installNodeDependencies(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        string $hostAppPath,
        ContainerDeployment $deployment,
        int $timeout,
        bool $forceRebuild = false
    ): array {
        $packageJsonPath = $hostAppPath.'/package.json';
        if (! $this->hostFileExists($ssh, $packageJsonPath)) {
            return ['No package.json found; skipped npm install.'];
        }

        $packageJson = $this->readHostFile($ssh, $packageJsonPath);
        $requiresBuild = $this->runtimeService->packageJsonRequiresProductionBuild($packageJson)
            || ($forceRebuild && $this->runtimeService->packageJsonHasBuildScript($packageJson));
        $buildTimeout = (int) config('containers.node_build.command_timeout_seconds', 900);
        $dockerImage = $this->resolveNodeDockerImage($deployment);

        try {
            if ($requiresBuild) {
                $buildEnv = $this->runtimeService->nodeBuildEnvironmentOverrides();
                $this->prepareNodePostPullWorkspace(
                    $ssh,
                    $containerPath,
                    $containerName,
                    $hostAppPath,
                    $packageJson,
                    $buildEnv,
                    cleanBuildArtifacts: true,
                    nodeDockerImage: $dockerImage,
                );

                $installCommand = $this->resolveNodeInstallCommand($ssh, $hostAppPath);

                $this->runUnlimitedMemoryNodeCommand(
                    $ssh,
                    $dockerImage,
                    $hostAppPath,
                    $installCommand,
                    '/app',
                    $timeout
                );
                $this->ensureNodeDevDependenciesInstalled(
                    $ssh,
                    $dockerImage,
                    $containerPath,
                    $containerName,
                    $hostAppPath,
                    $packageJson,
                    $timeout,
                    $buildEnv
                );
                $this->ensureNodeModulesIntegrity($ssh, $dockerImage, $hostAppPath, $packageJson, $timeout);
                $this->restoreNodeModuleBinPermissions($ssh, $containerPath, $containerName, $dockerImage, $hostAppPath);
                $this->stopApplicationServiceForMaintenance($ssh, $containerPath, $containerName);

                if ($this->runtimeService->nodeBuildPrepareEnabled()) {
                    app(ContainerNodeBuildPrepService::class)->syncPrepareScriptToHost($ssh, $hostAppPath);
                    $this->runUnlimitedMemoryNodeCommand(
                        $ssh,
                        $dockerImage,
                        $hostAppPath,
                        $this->runtimeService->nodeBuildPrepareCommand(),
                        '/app',
                        120
                    );
                }

                $this->runUnlimitedMemoryNodeCommand(
                    $ssh,
                    $dockerImage,
                    $hostAppPath,
                    $this->runtimeService->npmBuildShellCommand(null, true),
                    '/app',
                    $buildTimeout
                );
                $this->runUnlimitedMemoryNodeCommand(
                    $ssh,
                    $dockerImage,
                    $hostAppPath,
                    $this->runtimeService->npmPruneShellCommand(),
                    '/app',
                    $timeout
                );
                $this->restoreNodeModuleBinPermissions($ssh, $containerPath, $containerName, $dockerImage, $hostAppPath);

                return [$forceRebuild
                    ? 'Node dependencies updated and production build completed (forced rebuild).'
                    : 'Node dependencies updated and production build completed.'];
            }

            $this->prepareNodePostPullWorkspace(
                $ssh,
                $containerPath,
                $containerName,
                $hostAppPath,
                $packageJson,
            );

            $installCommand = $this->hostFileExists($ssh, $hostAppPath.'/package-lock.json')
                ? $this->runtimeService->nodeCleanNpmCommand('ci --omit=dev --no-audit --no-fund', 'production')
                : 'npm install --omit=dev';

            $this->runOneOffInContainer($ssh, $containerPath, $containerName, $installCommand, '/app', $timeout);
            $this->restoreNodeModuleBinPermissions($ssh, $containerPath, $containerName);

            return ['Node dependencies updated.'];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Node post-pull step failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<string>
     */
    private function installRubyDependencies(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        string $hostAppPath,
        int $timeout
    ): array {
        if (! $this->hostFileExists($ssh, $hostAppPath.'/Gemfile')) {
            return ['No Gemfile found; skipped bundle install.'];
        }

        try {
            $this->runOneOffInContainer(
                $ssh,
                $containerPath,
                $containerName,
                'bundle install --without development test',
                '/app',
                $timeout
            );

            return ['Ruby gems installed.'];
        } catch (\Throwable $e) {
            return ['bundle install failed: '.$e->getMessage()];
        }
    }

    /**
     * @return list<string>
     */
    private function installPythonDependencies(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        string $hostAppPath,
        int $timeout
    ): array {
        if (! $this->hostFileExists($ssh, $hostAppPath.'/requirements.txt')) {
            return ['No requirements.txt found; skipped pip install.'];
        }

        try {
            $this->runOneOffInContainer(
                $ssh,
                $containerPath,
                $containerName,
                'pip install --no-cache-dir -r requirements.txt',
                '/app',
                $timeout
            );

            return ['Python dependencies installed.'];
        } catch (\Throwable $e) {
            return ['pip install failed: '.$e->getMessage()];
        }
    }

    private function restoreNodeModuleBinPermissions(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        ?string $nodeDockerImage = null,
        ?string $hostAppPath = null,
    ): void {
        $command = 'find node_modules/.bin -type f -exec chmod u+x {} +';

        if ($nodeDockerImage !== null && $hostAppPath !== null) {
            $this->runUnlimitedMemoryNodeCommand($ssh, $nodeDockerImage, $hostAppPath, $command, '/app', 60);

            return;
        }

        $this->runOneOffInContainer(
            $ssh,
            $containerPath,
            $containerName,
            $command,
            '/app',
            60
        );
    }

    /**
     * @param  array<string, string>  $buildEnv
     */
    private function ensureNodeDevDependenciesInstalled(
        SSHService $ssh,
        ?string $nodeDockerImage,
        string $containerPath,
        string $containerName,
        string $hostAppPath,
        ?string $packageJson,
        int $timeout,
        array $buildEnv
    ): void {
        if ($packageJson === null || trim($packageJson) === '') {
            return;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            return;
        }

        $devDependencies = $data['devDependencies'] ?? [];
        if (! is_array($devDependencies) || $devDependencies === []) {
            return;
        }

        $probePackage = array_key_exists('tailwindcss', $devDependencies)
            ? 'tailwindcss'
            : (string) array_key_first($devDependencies);

        if ($probePackage === '') {
            return;
        }

        $integrityMarker = $this->runtimeService->nodeIntegrityMarkerRelativePath($packageJson);
        $integrityOk = $integrityMarker === null
            || $this->hostFileExists($ssh, $hostAppPath.'/'.$integrityMarker);

        if ($integrityOk && $this->hostDirectoryExists($ssh, $hostAppPath.'/node_modules/'.$probePackage)) {
            return;
        }

        $this->prepareNodePostPullWorkspace(
            $ssh,
            $containerPath,
            $containerName,
            $hostAppPath,
            $packageJson,
            $buildEnv,
            cleanBuildArtifacts: false,
            nodeDockerImage: $nodeDockerImage,
        );

        $installCommand = $this->resolveNodeInstallCommand($ssh, $hostAppPath);
        if ($nodeDockerImage !== null) {
            $this->runUnlimitedMemoryNodeCommand($ssh, $nodeDockerImage, $hostAppPath, $installCommand, '/app', $timeout);
        } else {
            $this->runOneOffInContainer(
                $ssh,
                $containerPath,
                $containerName,
                $installCommand,
                '/app',
                $timeout,
                $buildEnv,
                true
            );
        }

        if ($this->hostDirectoryExists($ssh, $hostAppPath.'/node_modules/'.$probePackage)) {
            return;
        }

        $devInstallCommand = $this->runtimeService->npmInstallDevPackagesShellCommand($packageJson);
        if ($nodeDockerImage !== null) {
            $this->runUnlimitedMemoryNodeCommand($ssh, $nodeDockerImage, $hostAppPath, $devInstallCommand, '/app', $timeout);
        } else {
            $this->runOneOffInContainer(
                $ssh,
                $containerPath,
                $containerName,
                $devInstallCommand,
                '/app',
                $timeout,
                $buildEnv,
                true
            );
        }

        if (! $this->hostDirectoryExists($ssh, $hostAppPath.'/node_modules/'.$probePackage)) {
            throw new \RuntimeException(
                'Dev dependencies such as '.$probePackage.' were not installed after npm install retries. '
                .'Stop the application container, remove node_modules, and run npm install with dev dependencies included.'
            );
        }
    }

    private function ensureNodeModulesIntegrity(
        SSHService $ssh,
        string $nodeDockerImage,
        string $hostAppPath,
        ?string $packageJson,
        int $timeout
    ): void {
        $marker = $this->runtimeService->nodeIntegrityMarkerRelativePath($packageJson);
        if ($marker === null || $this->hostFileExists($ssh, $hostAppPath.'/'.$marker)) {
            return;
        }

        \Log::warning('Node dependency install is incomplete after npm ci/install; retrying with a clean npm install', [
            'marker' => $marker,
            'host_app_path' => $hostAppPath,
        ]);

        $this->removeHostNodeInstallArtifacts($ssh, $hostAppPath, []);

        $this->runUnlimitedMemoryNodeCommand(
            $ssh,
            $nodeDockerImage,
            $hostAppPath,
            $this->runtimeService->npmInstallShellCommand(),
            '/app',
            $timeout
        );

        if (! $this->hostFileExists($ssh, $hostAppPath.'/'.$marker)) {
            throw new \RuntimeException(
                'Node dependency install is incomplete (missing '.$marker.'). '
                .'Refresh package-lock.json locally with npm install, commit it, and pull again.'
            );
        }
    }

    private function stopApplicationServiceForMaintenance(
        SSHService $ssh,
        string $containerPath,
        string $containerName
    ): void {
        $pathArg = escapeshellarg($containerPath);

        try {
            $ssh->exec("cd {$pathArg} && docker compose stop", 180);
        } catch (\Throwable $e) {
            \Log::warning('Failed to stop application container before Node post-pull maintenance', [
                'container_name' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function prepareNodePostPullWorkspace(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        string $hostAppPath,
        ?string $packageJson,
        array $environment = [],
        bool $cleanBuildArtifacts = true,
        ?string $nodeDockerImage = null,
    ): void {
        $this->stopApplicationServiceForMaintenance($ssh, $containerPath, $containerName);

        $extraDirs = $cleanBuildArtifacts
            ? $this->runtimeService->nodeBuildArtifactDirs($packageJson)
            : [];

        $this->removeHostNodeInstallArtifacts($ssh, $hostAppPath, $extraDirs);

        $cacheCleanCommand = $this->runtimeService->npmCacheCleanShellCommand();
        if ($nodeDockerImage !== null) {
            $this->runUnlimitedMemoryNodeCommand($ssh, $nodeDockerImage, $hostAppPath, $cacheCleanCommand, '/app', 120);

            return;
        }

        $this->runOneOffInContainer(
            $ssh,
            $containerPath,
            $containerName,
            $cacheCleanCommand,
            '/app',
            120,
            $environment,
            true
        );
    }

    /**
     * @param  list<string>  $extraDirs
     */
    private function removeHostNodeInstallArtifacts(SSHService $ssh, string $hostAppPath, array $extraDirs = []): void
    {
        $base = rtrim($hostAppPath, '/');
        $allowedBase = rtrim(ContainerDeploymentService::CONTAINER_BASE_PATH, '/');

        if ($base === '' || ! str_starts_with($base, $allowedBase.'/')) {
            throw new \InvalidArgumentException('Invalid host app path for Node cleanup.');
        }

        $targets = ['node_modules'];
        foreach ($extraDirs as $dir) {
            $dir = trim((string) $dir, '/');
            if ($dir !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $dir)) {
                $targets[] = $dir;
            }
        }

        $rmPaths = implode(' ', array_map(
            static fn (string $target): string => escapeshellarg($base.'/'.$target),
            array_values(array_unique($targets))
        ));

        $ssh->exec('sh -lc '.escapeshellarg("rm -rf {$rmPaths}"), 300);
    }

    private function resolveNodeInstallCommand(SSHService $ssh, string $hostAppPath): string
    {
        return $this->hostFileExists($ssh, $hostAppPath.'/package-lock.json')
            ? $this->runtimeService->npmCiShellCommand()
            : $this->runtimeService->npmInstallShellCommand();
    }

    private function resolveNodeDockerImage(ContainerDeployment $deployment): string
    {
        $deployment->loadMissing('service.product.containerTemplate');
        $template = $deployment->service?->product?->containerTemplate;

        if ($template === null) {
            throw new \RuntimeException('Container template is missing for this deployment.');
        }

        return $this->deploymentService()->resolveTemplateDockerImage($template, $deployment->selected_version);
    }

    private function hostFileExists(SSHService $ssh, string $path): bool
    {
        $pathArg = escapeshellarg($path);

        try {
            return trim($ssh->exec("[ -f {$pathArg} ] && echo yes || echo no", 10)) === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    private function hostDirectoryExists(SSHService $ssh, string $path): bool
    {
        $pathArg = escapeshellarg($path);

        try {
            return trim($ssh->exec("[ -d {$pathArg} ] && echo yes || echo no", 10)) === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    private function readHostFile(SSHService $ssh, string $path): ?string
    {
        if (! $this->hostFileExists($ssh, $path)) {
            return null;
        }

        $pathArg = escapeshellarg($path);

        try {
            $output = trim($ssh->exec('head -c 65536 '.$pathArg, 15));

            return $output !== '' ? $output : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function runUnlimitedMemoryNodeCommand(
        SSHService $ssh,
        string $dockerImage,
        string $hostAppPath,
        string $command,
        string $workDir = '/app',
        int $timeout = 900
    ): string {
        if (! $this->isSafeCommand($command)) {
            throw new \InvalidArgumentException('Unsafe container command rejected.');
        }

        if (! $this->isSafeDockerImageReference($dockerImage)) {
            throw new \InvalidArgumentException('Unsafe Docker image reference rejected.');
        }

        $imageArg = escapeshellarg($dockerImage);
        $volumeArg = escapeshellarg(rtrim($hostAppPath, '/').':'.rtrim($workDir, '/'));
        $workDirArg = escapeshellarg($workDir);
        $commandArg = escapeshellarg($command);

        return trim($ssh->exec(
            "docker run --rm -v {$volumeArg} -w {$workDirArg} {$imageArg} sh -c {$commandArg}",
            $timeout
        ));
    }

    private function isSafeDockerImageReference(string $image): bool
    {
        $image = trim($image);

        return $image !== ''
            && strlen($image) <= 200
            && (bool) preg_match('/^[a-z0-9][a-z0-9._\/-]*(?::[A-Za-z0-9._-]+)?$/', $image);
    }

    public function runOneOffInContainer(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        string $command,
        string $workDir = '/app',
        int $timeout = 600,
        array $environment = [],
        bool $noDeps = false
    ): string {
        if (! $this->isSafeCommand($command)) {
            throw new \InvalidArgumentException('Unsafe container command rejected.');
        }

        $pathArg = escapeshellarg($containerPath);
        $serviceArg = escapeshellarg($containerName);
        $workDirArg = escapeshellarg($workDir);
        $commandArg = escapeshellarg($command);
        $envFlags = $this->composeRunEnvironmentFlags($environment);
        $noDepsFlag = $noDeps ? ' --no-deps' : '';

        return trim($ssh->exec(
            "cd {$pathArg} && docker compose run --rm -T{$noDepsFlag}{$envFlags} -w {$workDirArg} {$serviceArg} sh -c {$commandArg}",
            $timeout
        ));
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function composeRunEnvironmentFlags(array $environment): string
    {
        if ($environment === []) {
            return '';
        }

        $flags = '';
        foreach ($environment as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException('Invalid container environment key.');
            }

            $value = (string) $value;
            if ($value === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
                throw new \InvalidArgumentException('Invalid container environment value.');
            }

            $flags .= ' -e '.escapeshellarg($key.'='.$value);
        }

        return $flags;
    }

    public function execInContainer(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        string $command,
        string $workDir = '/app',
        int $timeout = 600
    ): string {
        if (! $this->isSafeCommand($command)) {
            throw new \InvalidArgumentException('Unsafe container command rejected.');
        }

        $pathArg = escapeshellarg($containerPath);
        $serviceArg = escapeshellarg($containerName);
        $workDirArg = escapeshellarg($workDir);
        $commandArg = escapeshellarg($command);

        return trim($ssh->exec(
            "cd {$pathArg} && docker compose exec -T -w {$workDirArg} {$serviceArg} sh -lc {$commandArg}",
            $timeout
        ));
    }
}
