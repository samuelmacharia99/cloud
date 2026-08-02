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
        if ($cmd === '' || strlen($cmd) > 2000) {
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
            'laravel' => $this->installLaravelFrontendDependencies(
                $ssh,
                $deployment,
                $hostAppPath,
                $timeout,
                $forceRebuild
            ),
            'php' => array_values(array_filter(array_merge(
                $this->installPhpDependencies($ssh, $deployment, $hostAppPath, $service, $timeout),
                $this->installLaravelFrontendDependencies(
                    $ssh,
                    $deployment,
                    $hostAppPath,
                    $timeout,
                    $forceRebuild
                ),
            ))),
            'ruby' => $this->installRubyDependencies($ssh, $containerPath, $containerName, $hostAppPath, $timeout),
            'python' => $this->installPythonDependencies($ssh, $containerPath, $containerName, $hostAppPath, $timeout),
            default => [],
        };
    }

    /**
     * Install + build a Next/Vite app under /app/frontend (Laravel monorepo) when present.
     *
     * @return list<string>
     */
    public function installLaravelFrontendDependencies(
        SSHService $ssh,
        ContainerDeployment $deployment,
        ?string $hostAppPath,
        int $timeout = 900,
        bool $forceRebuild = false
    ): array {
        $relativeDir = $this->resolveLaravelFrontendRelativeDir($ssh, $hostAppPath);
        if ($relativeDir === null) {
            return [];
        }

        $containerDir = '/app/'.trim($relativeDir, '/');
        $init = app(LaravelAppInitializationService::class);
        $execTarget = $this->resolveFrontendExecContainer($ssh, $deployment);

        try {
            // Backend runtime image still hosts Node for first-time builds before the frontend sidecar exists.
            app(LaravelAppInitializationService::class)->ensureNodeRuntime($ssh, $deployment);
        } catch (\Throwable $e) {
            if ($execTarget === $deployment->container_name) {
                return ['Frontend skipped: Node.js is not available ('.$e->getMessage().').'];
            }
            // Frontend sidecar is a Node image — continue without Laravel Node bootstrap.
        }

        $messages = [];
        $npmPrefix = 'export HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm npm_config_cache=/tmp/.npm; '
            .'mkdir -p /tmp/.npm; '
            .'printf "cache=/tmp/.npm\\n" > '.escapeshellarg($containerDir.'/.npmrc').'; ';

        $installScript = 'set -e; '.$npmPrefix
            .'cd '.escapeshellarg($containerDir).'; '
            .'if [ -f package-lock.json ]; then '
            .'npm ci --legacy-peer-deps --cache /tmp/.npm --no-audit --no-fund '
            .'|| npm install --legacy-peer-deps --cache /tmp/.npm --no-audit --no-fund; '
            .'else npm install --legacy-peer-deps --cache /tmp/.npm --no-audit --no-fund; fi; '
            .'test -e node_modules/.bin/next -o -e node_modules/next/dist/bin/next -o -d node_modules';

        try {
            $init->dockerExecPublic($ssh, $execTarget, $installScript, max(300, $timeout), asRoot: false);
            $messages[] = 'Frontend dependencies installed in '.$containerDir
                .($execTarget !== $deployment->container_name ? ' (frontend sidecar).' : '.');
        } catch (\Throwable $e) {
            // Retry once as root in case www-data cannot write node_modules on the bind mount.
            try {
                $init->dockerExecPublic($ssh, $execTarget, $installScript, max(300, $timeout), asRoot: true);
                $chown = 'chown -R www-data:www-data '.escapeshellarg($containerDir.'/node_modules').' 2>/dev/null || true';
                $init->dockerExecPublic($ssh, $execTarget, $chown, 60, asRoot: true);
                $messages[] = 'Frontend dependencies installed in '.$containerDir.' (root fallback).';
            } catch (\Throwable $rootError) {
                return ['Frontend npm install failed: '.mb_substr($rootError->getMessage(), 0, 300)];
            }
        }

        $hasBuild = false;
        try {
            $pkg = trim($ssh->exec(
                'docker exec '.escapeshellarg($execTarget)
                .' sh -lc '.escapeshellarg('cat '.escapeshellarg($containerDir.'/package.json')),
                20
            ));
            $hasBuild = $this->runtimeService->packageJsonHasBuildScript($pkg)
                || $this->runtimeService->packageJsonRequiresProductionBuild($pkg);
        } catch (\Throwable) {
            $hasBuild = true;
        }

        $needsBuild = $forceRebuild || $hasBuild;
        if ($needsBuild) {
            // Next 16 defaults to Turbopack; apps with webpack config (PWA/Sentry) need --webpack.
            // Washflow-sized apps OOM at the default ~1.5G heap on small containers.
            $heapMb = 3072;
            try {
                $mem = (int) ($deployment->memory_limit_mb ?? 0);
                if ($mem > 0) {
                    // Sidecar gets ~40% of plan memory; size the heap from that share.
                    $sidecarShare = $this->deploymentHasNextSidecarStack($deployment) ? 0.40 : 0.65;
                    $heapMb = max(1536, min(4096, (int) floor($mem * $sidecarShare)));
                }
            } catch (\Throwable) {
            }

            $buildScript = 'set -e; '.$npmPrefix
                .'export NEXT_TELEMETRY_DISABLED=1 NODE_OPTIONS=--max-old-space-size='.$heapMb.'; '
                .'cd '.escapeshellarg($containerDir).'; '
                .'if [ -x node_modules/.bin/next ] || [ -f node_modules/next/dist/bin/next ]; then '
                .'npx next build --webpack; '
                .'else npm run build; fi; '
                // Standalone output needs static assets copied beside server.js.
                .'if [ -f .next/standalone/server.js ] || [ -f .next/standalone/frontend/server.js ]; then '
                .'STANDALONE_DIR=.next/standalone; '
                .'[ -f .next/standalone/frontend/server.js ] && STANDALONE_DIR=.next/standalone/frontend; '
                .'mkdir -p "$STANDALONE_DIR/.next"; '
                .'cp -a .next/static "$STANDALONE_DIR/.next/static" 2>/dev/null || true; '
                .'cp -a public "$STANDALONE_DIR/public" 2>/dev/null || true; '
                .'fi';

            try {
                $init->dockerExecPublic($ssh, $execTarget, $buildScript, max(600, $timeout), asRoot: false);
                $messages[] = 'Frontend build completed in '.$containerDir.'.';
            } catch (\Throwable $e) {
                try {
                    $init->dockerExecPublic($ssh, $execTarget, $buildScript, max(600, $timeout), asRoot: true);
                    $messages[] = 'Frontend build completed in '.$containerDir.' (root fallback).';
                } catch (\Throwable $rootError) {
                    $messages[] = 'Frontend build failed: '.mb_substr($rootError->getMessage(), 0, 300);
                }
            }
        }

        return $messages;
    }

    /**
     * Prefer the Next frontend sidecar when the stack is split; otherwise the app container.
     */
    public function resolveFrontendExecContainer(SSHService $ssh, ContainerDeployment $deployment): string
    {
        $frontendName = LaravelNextGatewayProxy::frontendContainerName($deployment->container_name);

        if ($this->deploymentHasNextSidecarStack($deployment)) {
            $running = trim($ssh->exec(
                'docker inspect -f "{{.State.Running}}" '.escapeshellarg($frontendName).' 2>/dev/null || echo false',
                15
            ));
            if ($running === 'true') {
                return $frontendName;
            }
        }

        return $deployment->container_name;
    }

    public function deploymentHasNextSidecarStack(ContainerDeployment $deployment): bool
    {
        $yaml = (string) ($deployment->docker_compose_content ?? '');

        return str_contains($yaml, "\n  frontend:\n")
            && str_contains($yaml, "\n  edge:\n")
            && str_contains($yaml, "\n  backend:\n");
    }

    /**
     * @return non-empty-string|null Relative path under /app (e.g. "frontend")
     */
    public function resolveLaravelFrontendRelativeDir(SSHService $ssh, ?string $hostAppPath): ?string
    {
        if ($hostAppPath === null || $hostAppPath === '') {
            return null;
        }

        foreach (['frontend', 'web', 'client'] as $dir) {
            $path = rtrim($hostAppPath, '/').'/'.$dir.'/package.json';
            if ($this->hostFileExists($ssh, $path)) {
                try {
                    $pkg = (string) $this->readHostFile($ssh, $path);
                } catch (\Throwable) {
                    continue;
                }
                if (str_contains($pkg, '"next"')
                    || str_contains($pkg, '"vite"')
                    || ($this->runtimeService->packageJsonHasBuildScript($pkg))) {
                    return $dir;
                }
            }
        }

        return null;
    }

    public function hostHasNextFrontend(SSHService $ssh, ?string $hostAppPath): bool
    {
        $dir = $this->resolveLaravelFrontendRelativeDir($ssh, $hostAppPath);
        if ($dir === null || $hostAppPath === null) {
            return false;
        }

        try {
            $pkg = (string) $this->readHostFile($ssh, rtrim($hostAppPath, '/').'/'.$dir.'/package.json');
        } catch (\Throwable) {
            return false;
        }

        return str_contains($pkg, '"next"');
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
        $this->assertCompatibleNodePackageManager($ssh, $hostAppPath);
        $requiresBuild = $this->runtimeService->packageJsonRequiresProductionBuild($packageJson)
            || ($forceRebuild && $this->runtimeService->packageJsonHasBuildScript($packageJson));
        $buildTimeout = (int) config('containers.node_build.command_timeout_seconds', 900);
        $dockerImage = $this->resolveNodeDockerImage($deployment);
        $publicBuildEnv = $this->runtimeService->collectNodeBuildEnvFromDeployment($deployment);

        try {
            if ($requiresBuild) {
                $buildEnv = array_merge(
                    $this->runtimeService->nodeBuildEnvironmentOverrides(),
                    $publicBuildEnv,
                );
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

                $this->installNodeDependenciesPreferringLockfile(
                    $ssh,
                    $hostAppPath,
                    preferDevDependencies: true,
                    runner: function (string $command) use ($ssh, $dockerImage, $hostAppPath, $timeout): void {
                        $this->runUnlimitedMemoryNodeCommand(
                            $ssh,
                            $dockerImage,
                            $hostAppPath,
                            $command,
                            '/app',
                            $timeout
                        );
                    },
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
                    $this->runtimeService->npmBuildShellCommand(null, true, $packageJson, $publicBuildEnv),
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

            $this->installNodeDependenciesPreferringLockfile(
                $ssh,
                $hostAppPath,
                preferDevDependencies: false,
                runner: function (string $command) use ($ssh, $containerPath, $containerName, $timeout): void {
                    $this->runOneOffInContainer($ssh, $containerPath, $containerName, $command, '/app', $timeout);
                },
            );
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
            throw new \RuntimeException('Ruby post-pull step failed: '.$e->getMessage(), 0, $e);
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
        $messages = [];

        if ($this->hostFileExists($ssh, $hostAppPath.'/requirements.txt')) {
            try {
                $this->runOneOffInContainer(
                    $ssh,
                    $containerPath,
                    $containerName,
                    'pip install --no-cache-dir -r requirements.txt',
                    '/app',
                    $timeout
                );

                $messages[] = 'Python dependencies installed from requirements.txt.';
            } catch (\Throwable $e) {
                throw new \RuntimeException('Python post-pull step failed: '.$e->getMessage(), 0, $e);
            }
        } elseif ($this->hostFileExists($ssh, $hostAppPath.'/pyproject.toml')) {
            throw new \RuntimeException(
                'Found pyproject.toml but no requirements.txt. Export dependencies with '
                .'`pip freeze > requirements.txt` (or `poetry export -f requirements.txt -o requirements.txt`) and commit that file so Git pulls can install packages.'
            );
        } elseif ($this->hostFileExists($ssh, $hostAppPath.'/Pipfile')) {
            throw new \RuntimeException(
                'Found Pipfile but no requirements.txt. Export with `pipenv requirements > requirements.txt` and commit that file so Git pulls can install packages.'
            );
        } else {
            $messages[] = 'No requirements.txt found; skipped pip install.';
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function installPhpDependencies(
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $hostAppPath,
        Service $service,
        int $timeout
    ): array {
        if (! $this->hostFileExists($ssh, $hostAppPath.'/composer.json')) {
            return ['No composer.json found; skipped Composer install.'];
        }

        try {
            app(LaravelAppInitializationService::class)->runComposerInstall($ssh, $deployment, $timeout, $service);

            return ['Composer dependencies updated.'];
        } catch (\Throwable $e) {
            throw new \RuntimeException('PHP post-pull step failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function assertCompatibleNodePackageManager(SSHService $ssh, string $hostAppPath): void
    {
        $hasNpmLock = $this->hostFileExists($ssh, $hostAppPath.'/package-lock.json');
        $hasYarnLock = $this->hostFileExists($ssh, $hostAppPath.'/yarn.lock');
        $hasPnpmLock = $this->hostFileExists($ssh, $hostAppPath.'/pnpm-lock.yaml');

        if ($hasNpmLock || (! $hasYarnLock && ! $hasPnpmLock)) {
            return;
        }

        if ($hasYarnLock && ! $hasPnpmLock) {
            throw new \RuntimeException(
                'This repository uses Yarn (yarn.lock) without package-lock.json. '
                .'Talksasa Git pulls install with npm. Run `npm install` locally, commit package-lock.json, and pull again.'
            );
        }

        throw new \RuntimeException(
            'This repository uses pnpm (pnpm-lock.yaml) without package-lock.json. '
            .'Talksasa Git pulls install with npm. Run `npm install` locally, commit package-lock.json, and pull again.'
        );
    }

    private function restoreNodeModuleBinPermissions(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        ?string $nodeDockerImage = null,
        ?string $hostAppPath = null,
    ): void {
        // chmod each path separately — Alpine find fails if any path is missing,
        // and isSafeCommand rejects "||" / redirects used to ignore that.
        $directories = [
            '/app/node_modules/.bin',
            '/app/node_modules/next/dist/bin',
        ];

        foreach ($directories as $directory) {
            $testCommand = 'test -d '.escapeshellarg($directory);
            $chmodCommand = 'find '.escapeshellarg($directory).' -type f -exec chmod u+x {} +';

            try {
                if ($nodeDockerImage !== null && $hostAppPath !== null) {
                    $this->runUnlimitedMemoryNodeCommand($ssh, $nodeDockerImage, $hostAppPath, $testCommand, '/app', 30);
                    $this->runUnlimitedMemoryNodeCommand($ssh, $nodeDockerImage, $hostAppPath, $chmodCommand, '/app', 60);
                } else {
                    $this->runOneOffInContainer($ssh, $containerPath, $containerName, $testCommand, '/app', 30);
                    $this->runOneOffInContainer($ssh, $containerPath, $containerName, $chmodCommand, '/app', 60);
                }
            } catch (\Throwable) {
                // Directory missing (e.g. non-Next apps) — skip.
            }
        }
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

        $integrityOk = $this->hostNodeModulesIntegrityOk($ssh, $hostAppPath, $packageJson);

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

        $this->installNodeDependenciesPreferringLockfile(
            $ssh,
            $hostAppPath,
            preferDevDependencies: true,
            runner: function (string $command) use ($ssh, $nodeDockerImage, $hostAppPath, $containerPath, $containerName, $timeout, $buildEnv): void {
                if ($nodeDockerImage !== null) {
                    $this->runUnlimitedMemoryNodeCommand($ssh, $nodeDockerImage, $hostAppPath, $command, '/app', $timeout);

                    return;
                }

                $this->runOneOffInContainer(
                    $ssh,
                    $containerPath,
                    $containerName,
                    $command,
                    '/app',
                    $timeout,
                    $buildEnv,
                    true
                );
            },
        );

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
        if ($this->hostNodeModulesIntegrityOk($ssh, $hostAppPath, $packageJson)) {
            return;
        }

        $missing = $this->missingNodeIntegrityMarkers($ssh, $hostAppPath, $packageJson);

        \Log::warning('Node dependency install is incomplete after npm ci/install; retrying with a clean npm install', [
            'missing' => $missing,
            'host_app_path' => $hostAppPath,
        ]);

        $this->removeHostNodeInstallArtifacts($ssh, $hostAppPath, []);

        $this->runUnlimitedMemoryNodeCommand(
            $ssh,
            $nodeDockerImage,
            $hostAppPath,
            $this->runtimeService->npmInstallShellCommand(true),
            '/app',
            $timeout
        );

        if (! $this->hostNodeModulesIntegrityOk($ssh, $hostAppPath, $packageJson)
            && $this->runtimeService->packageJsonUsesNext($packageJson)
        ) {
            \Log::warning('Next.js install is missing react peers; installing react and react-dom explicitly', [
                'missing' => $this->missingNodeIntegrityMarkers($ssh, $hostAppPath, $packageJson),
                'host_app_path' => $hostAppPath,
            ]);

            $this->runUnlimitedMemoryNodeCommand(
                $ssh,
                $nodeDockerImage,
                $hostAppPath,
                $this->runtimeService->npmInstallNextPeersShellCommand(),
                '/app',
                $timeout
            );
        }

        if (! $this->hostNodeModulesIntegrityOk($ssh, $hostAppPath, $packageJson)) {
            $stillMissing = $this->missingNodeIntegrityMarkers($ssh, $hostAppPath, $packageJson);
            $hint = $stillMissing !== [] ? implode(', ', $stillMissing) : 'framework packages';

            throw new \RuntimeException(
                'Node dependency install is incomplete (missing '.$hint.'). '
                .'Ensure react and react-dom are listed in package.json, refresh package-lock.json locally with npm install, commit it, and pull again.'
            );
        }
    }

    private function hostNodeModulesIntegrityOk(
        SSHService $ssh,
        string $hostAppPath,
        ?string $packageJson
    ): bool {
        return $this->missingNodeIntegrityMarkers($ssh, $hostAppPath, $packageJson) === [];
    }

    /**
     * @return list<string>
     */
    private function missingNodeIntegrityMarkers(
        SSHService $ssh,
        string $hostAppPath,
        ?string $packageJson
    ): array {
        $missing = [];
        foreach ($this->runtimeService->nodeIntegrityMarkerRelativePaths($packageJson) as $marker) {
            if (! $this->hostFileExists($ssh, $hostAppPath.'/'.$marker)) {
                $missing[] = $marker;
            }
        }

        return $missing;
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

    /**
     * Prefer npm ci when a lockfile exists; fall back to npm install when the lock is out of sync.
     *
     * @param  callable(string): void  $runner
     */
    private function installNodeDependenciesPreferringLockfile(
        SSHService $ssh,
        string $hostAppPath,
        bool $preferDevDependencies,
        callable $runner,
    ): void {
        $hasLock = $this->hostFileExists($ssh, $hostAppPath.'/package-lock.json');

        if ($preferDevDependencies) {
            $ciCommand = $this->runtimeService->npmCiShellCommand();
            $installCommand = $this->runtimeService->npmInstallShellCommand();
        } else {
            $ciCommand = $this->runtimeService->nodeCleanNpmCommand('ci --omit=dev --no-audit --no-fund', 'production');
            $installCommand = 'npm install --omit=dev';
        }

        if (! $hasLock) {
            $runner($installCommand);

            return;
        }

        try {
            $runner($ciCommand);
        } catch (\Throwable $e) {
            if (! $this->isNpmLockfileOutOfSyncError($e)) {
                throw $e;
            }

            try {
                \Log::warning('npm ci failed because package-lock.json is out of sync; falling back to npm install', [
                    'host_app_path' => $hostAppPath,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Unit tests may run without the Log facade bootstrap.
            }

            $runner($installCommand);
        }
    }

    public function isNpmLockfileOutOfSyncError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'are in sync')
            || str_contains($message, 'update your lock file')
            || str_contains($message, 'npm-shrinkwrap.json are in sync')
            || (str_contains($message, 'npm error code eusage') && str_contains($message, 'npm ci'))
            || (str_contains($message, 'eusage') && str_contains($message, 'npm ci') && str_contains($message, 'missing:'));
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
