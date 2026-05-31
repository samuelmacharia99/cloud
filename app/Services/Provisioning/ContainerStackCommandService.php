<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerStackCommandService
{
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
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);

        return match ($slug) {
            'nodejs' => $this->installNodeDependencies($ssh, $containerPath, $containerName, $timeout),
            'ruby' => $this->installRubyDependencies($ssh, $containerPath, $containerName, $timeout),
            'python' => $this->installPythonDependencies($ssh, $containerPath, $containerName, $timeout),
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
        int $timeout
    ): array {
        if (! $this->containerFileExists($ssh, $containerPath, $containerName, 'package.json')) {
            return ['No package.json found; skipped npm install.'];
        }

        try {
            $this->execInContainer($ssh, $containerPath, $containerName, 'npm install --omit=dev', '/app', $timeout);

            return ['Node dependencies updated.'];
        } catch (\Throwable $e) {
            return ['npm install failed: '.$e->getMessage()];
        }
    }

    /**
     * @return list<string>
     */
    private function installRubyDependencies(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        int $timeout
    ): array {
        if (! $this->containerFileExists($ssh, $containerPath, $containerName, 'Gemfile')) {
            return ['No Gemfile found; skipped bundle install.'];
        }

        try {
            $this->execInContainer(
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
        int $timeout
    ): array {
        if (! $this->containerFileExists($ssh, $containerPath, $containerName, 'requirements.txt')) {
            return ['No requirements.txt found; skipped pip install.'];
        }

        try {
            $this->execInContainer(
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

    private function containerFileExists(
        SSHService $ssh,
        string $containerPath,
        string $containerName,
        string $relativePath
    ): bool {
        $fileArg = escapeshellarg('/app/'.$relativePath);

        try {
            $output = $this->execInContainer(
                $ssh,
                $containerPath,
                $containerName,
                "[ -f {$fileArg} ] && echo yes || echo no",
                '/app',
                30
            );

            return trim($output) === 'yes';
        } catch (\Throwable) {
            return false;
        }
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
