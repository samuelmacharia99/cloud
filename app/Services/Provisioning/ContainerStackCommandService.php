<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerStackCommandService
{
    public function __construct(
        private ?ContainerApplicationRuntimeService $runtimeService = null
    ) {
        $this->runtimeService ??= new ContainerApplicationRuntimeService;
    }

    public function resolveWorkDir(object $template): string
    {
        $volumePaths = $template->volume_paths ?? null;
        if (is_array($volumePaths) && isset($volumePaths['app_data'])) {
            return (string) $volumePaths['app_data'];
        }

        return match ($template->slug ?? null) {
            'strapi' => '/srv/app',
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

    /**
     * @return list<string>
     */
    public function runPostPullSteps(Service $service, ContainerDeployment $deployment, SSHService $ssh): array
    {
        $slug = $service->product?->containerTemplate?->slug ?? '';
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $containerName = $deployment->container_name;
        $hostAppPath = app(ContainerAppDirectoryService::class)->hostAppPath($deployment);
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);

        return match ($slug) {
            'nodejs' => $this->installNodeDependencies($ssh, $containerPath, $containerName, $hostAppPath, $timeout),
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
        int $timeout
    ): array {
        $packageJsonPath = $hostAppPath.'/package.json';
        if (! $this->hostFileExists($ssh, $packageJsonPath)) {
            return ['No package.json found; skipped npm install.'];
        }

        $packageJson = $this->readHostFile($ssh, $packageJsonPath);
        $requiresBuild = $this->runtimeService->packageJsonRequiresProductionBuild($packageJson);

        try {
            if ($requiresBuild) {
                $hasLockFile = $this->hostFileExists($ssh, $hostAppPath.'/package-lock.json');
                $installForBuild = $this->runtimeService->npmInstallForProductionBuildCommand($hasLockFile);
                $buildEnv = $this->runtimeService->nodeBuildEnvironmentOverrides();
                $this->runOneOffInContainer($ssh, $containerPath, $containerName, 'rm -rf node_modules', '/app', 120, $buildEnv);
                $this->runOneOffInContainer($ssh, $containerPath, $containerName, $installForBuild, '/app', $timeout, $buildEnv);
                $this->restoreNodeModuleBinPermissions($ssh, $containerPath, $containerName);
                $this->runOneOffInContainer($ssh, $containerPath, $containerName, 'npm run build', '/app', $timeout, $buildEnv);
                $this->runOneOffInContainer($ssh, $containerPath, $containerName, 'npm prune --omit=dev', '/app', $timeout, $buildEnv);
                $this->restoreNodeModuleBinPermissions($ssh, $containerPath, $containerName);

                return ['Node dependencies updated and production build completed.'];
            }

            $this->runOneOffInContainer($ssh, $containerPath, $containerName, 'npm install --omit=dev', '/app', $timeout);
            $this->restoreNodeModuleBinPermissions($ssh, $containerPath, $containerName);

            return ['Node dependencies updated.'];
        } catch (\Throwable $e) {
            return ['Node post-pull step failed: '.$e->getMessage()];
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
        string $containerName
    ): void {
        $this->runOneOffInContainer(
            $ssh,
            $containerPath,
            $containerName,
            'find node_modules/.bin -type f -exec chmod u+x {} +',
            '/app',
            60
        );
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

    public function runOneOffInContainer(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        string $command,
        string $workDir = '/app',
        int $timeout = 600,
        array $environment = []
    ): string {
        if (! $this->isSafeCommand($command)) {
            throw new \InvalidArgumentException('Unsafe container command rejected.');
        }

        $pathArg = escapeshellarg($containerPath);
        $serviceArg = escapeshellarg($containerName);
        $workDirArg = escapeshellarg($workDir);
        $commandArg = escapeshellarg($command);
        $envFlags = $this->composeRunEnvironmentFlags($environment);

        return trim($ssh->exec(
            "cd {$pathArg} && docker compose run --rm -T{$envFlags} -w {$workDirArg} {$serviceArg} sh -lc {$commandArg}",
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
