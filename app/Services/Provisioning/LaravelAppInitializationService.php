<?php

namespace App\Services\Provisioning;

use App\Models\ContainerAppInitialization;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\DB;

class LaravelAppInitializationService
{
    private const COMPOSER_BIN = '/usr/local/bin/composer';

    public function __construct(
        private ContainerAppDirectoryService $appDirectory,
    ) {}

    public const STEP_DEFINITIONS = [
        'validate' => 'Validate container and application directory',
        'scaffold' => 'Download Laravel application skeleton',
        'dependencies' => 'Install Composer dependencies',
        'environment' => 'Configure environment file (.env)',
        'app_key' => 'Generate application encryption key',
        'migrations' => 'Run database migrations',
        'permissions' => 'Apply file permissions',
    ];

    public function requestInitialization(Service $service, User $user): ContainerAppInitialization
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');

        $this->assertCanInitialize($service);

        $deployment = $service->containerDeployment;
        if (! $deployment || $deployment->status !== 'running') {
            throw new \DomainException('Container must be running before initializing the application.');
        }

        if ($this->hasActiveInitialization($service)) {
            throw new \DomainException('An application initialization is already in progress.');
        }

        return DB::transaction(function () use ($service, $user, $deployment) {
            return ContainerAppInitialization::create([
                'service_id' => $service->id,
                'container_deployment_id' => $deployment->id,
                'user_id' => $user->id,
                'template_slug' => (string) $service->product->containerTemplate->slug,
                'status' => ContainerAppInitialization::STATUS_PENDING,
                'steps' => $this->buildInitialSteps(),
            ]);
        });
    }

    public function run(ContainerAppInitialization $initialization): void
    {
        $initialization->loadMissing('service.product.containerTemplate', 'service.containerDeployment.node');
        $service = $initialization->service;
        $deployment = $initialization->deployment ?? $service->containerDeployment;

        if (! $deployment || ! $deployment->node) {
            $this->fail($initialization, 'Container deployment is not available.');

            return;
        }

        $initialization->update([
            'status' => ContainerAppInitialization::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ]);
        $initialization->appendLog('Initialization started for service '.$service->id);

        $ssh = SSHService::forNode($deployment->node);
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);

        try {
            $this->runStep($initialization, $ssh, $deployment, 'validate', function () use ($ssh, $deployment, $initialization) {
                if ($deployment->status !== 'running') {
                    throw new \RuntimeException('Container is not running.');
                }

                $this->appDirectory->prepareForInitialization($ssh, $deployment);
                $this->assertComposerAvailable($ssh, $deployment);
                $initialization->appendLog('/app prepared with default placeholder state.');

                return 'Application directory is ready for Laravel scaffold.';
            });

            $this->runStep($initialization, $ssh, $deployment, 'scaffold', function () use ($ssh, $deployment, $timeout) {
                $constraint = (string) config('containers.laravel_init.composer_constraint', '^12.0');
                $package = 'laravel/laravel:'.str_replace("'", '', $constraint);
                $packageArg = escapeshellarg($package);
                $composer = $this->composerInvoke('');
                $script = implode('; ', [
                    'set -e',
                    'cd /app',
                    'rm -f index.html public/index.html .keep 2>/dev/null || true',
                    'rm -rf /tmp/talksasa-laravel-init',
                    trim($composer.' create-project '.$packageArg.' /tmp/talksasa-laravel-init --prefer-dist --no-interaction --no-progress'),
                    'cp -a /tmp/talksasa-laravel-init/. /app/',
                    'rm -rf /tmp/talksasa-laravel-init',
                    'test -f /app/artisan',
                ]);

                $output = $this->dockerExec($ssh, $deployment->container_name, $script, $timeout);

                return ['message' => 'Laravel skeleton installed into /app.', 'output' => $output];
            });

            $this->runStep($initialization, $ssh, $deployment, 'dependencies', function () use ($ssh, $deployment, $timeout) {
                $output = $this->dockerExec(
                    $ssh,
                    $deployment->container_name,
                    'set -e; cd /app; '.$this->composerInvoke('install --no-interaction --no-progress --optimize-autoloader'),
                    $timeout
                );

                return ['message' => 'Composer dependencies installed.', 'output' => $output];
            });

            $envValues = is_array($deployment->env_values) ? $deployment->env_values : [];

            $this->runStep($initialization, $ssh, $deployment, 'environment', function () use ($ssh, $deployment, $service, $envValues) {
                $hostAppPath = $this->appDirectory->hostAppPath($deployment);
                $envContent = $this->buildEnvFileContent($ssh, $deployment, $envValues);

                $ssh->upload($envContent, $hostAppPath.'/.env');
                $this->dockerExec(
                    $ssh,
                    $deployment->container_name,
                    'set -e; cd /app; test -f .env',
                    30
                );

                $serviceMeta = is_array($service->service_meta) ? $service->service_meta : [];
                $serviceMeta['laravel_env_configured_at'] = now()->toIso8601String();
                $service->update(['service_meta' => $serviceMeta]);

                return 'Environment file written with database credentials from your deployment.';
            });

            $this->runStep($initialization, $ssh, $deployment, 'app_key', function () use ($ssh, $deployment, $timeout) {
                $output = $this->dockerExec(
                    $ssh,
                    $deployment->container_name,
                    'set -e; cd /app; php artisan key:generate --force --no-interaction',
                    $timeout
                );

                return ['message' => 'Application key generated.', 'output' => $output];
            });

            $this->runStep($initialization, $ssh, $deployment, 'migrations', function () use ($ssh, $deployment, $timeout, $initialization) {
                try {
                    $output = $this->dockerExec(
                        $ssh,
                        $deployment->container_name,
                        'set -e; cd /app; php artisan migrate --force --no-interaction',
                        $timeout
                    );

                    return ['message' => 'Database migrations completed.', 'output' => $output];
                } catch (\Throwable $e) {
                    $initialization->updateStep(
                        'migrations',
                        'warning',
                        'Migrations could not run automatically. Configure the database tab and run `php artisan migrate` from the terminal.',
                        $e->getMessage()
                    );

                    return ['message' => 'Migrations skipped with warning.', 'output' => $e->getMessage(), 'skip_complete' => true];
                }
            }, allowWarningComplete: true);

            $this->runStep($initialization, $ssh, $deployment, 'permissions', function () use ($ssh, $deployment) {
                $this->appDirectory->normalizePermissions($ssh, $deployment);

                return 'File permissions normalized for www-data.';
            });

            $serviceMeta = is_array($service->service_meta) ? $service->service_meta : [];
            $serviceMeta['laravel_initialized_at'] = now()->toIso8601String();
            $service->update(['service_meta' => $serviceMeta]);

            $initialization->update([
                'status' => ContainerAppInitialization::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
            $initialization->appendLog('Initialization completed successfully.');
        } catch (\Throwable $e) {
            $this->fail($initialization, $e->getMessage());
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSetupChecklist(Service $service): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment');
        $deployment = $service->containerDeployment;
        $templateSlug = $service->product?->containerTemplate?->slug;
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $repoUrl = trim((string) ($meta['source_repo_url'] ?? ''));

        $appScaffolded = false;
        $envConfigured = false;
        $appKeySet = false;
        $migrationsOk = false;

        if ($deployment && $deployment->node && $deployment->status === 'running') {
            try {
                $ssh = SSHService::forNode($deployment->node);
                try {
                    $appScaffolded = $this->appDirectory->hasLaravelProject($ssh, $deployment);
                    $envConfigured = $this->remoteFileExists($ssh, $deployment, '/app/.env');
                    $appKeySet = $appScaffolded && $this->envValuePresent($ssh, $deployment, 'APP_KEY');
                    $migrationsOk = $appScaffolded && $this->migrationsTableExists($ssh, $deployment);
                } finally {
                    $ssh->disconnect();
                }
            } catch (\Throwable) {
                // Leave checklist in pending/unknown state when SSH is unavailable.
            }
        }

        $latestInit = $this->latestInitialization($service);

        return [
            [
                'key' => 'container_running',
                'label' => 'Container stack is running',
                'description' => 'Required before files or initialization can be managed.',
                'status' => ($deployment && $deployment->status === 'running') ? 'completed' : 'pending',
            ],
            [
                'key' => 'runtime_ready',
                'label' => 'PHP runtime image ready',
                'description' => 'Talksasa runtime image with Composer and PHP extensions.',
                'status' => ($deployment && $deployment->status === 'running') ? 'completed' : 'pending',
            ],
            [
                'key' => 'app_source',
                'label' => 'Application files present in /app',
                'description' => $repoUrl !== ''
                    ? 'Git repository configured at checkout.'
                    : 'Initialize Laravel or upload/clone your project into /app.',
                'status' => $appScaffolded ? 'completed' : ($repoUrl !== '' ? 'pending' : 'pending'),
            ],
            [
                'key' => 'env_configured',
                'label' => 'Environment file configured',
                'description' => '`.env` exists with database connection settings.',
                'status' => $envConfigured ? 'completed' : 'pending',
            ],
            [
                'key' => 'app_key',
                'label' => 'Application key generated',
                'description' => '`APP_KEY` is set for encryption and sessions.',
                'status' => $appKeySet ? 'completed' : 'pending',
            ],
            [
                'key' => 'migrations',
                'label' => 'Database migrations applied',
                'description' => 'Laravel migrations table exists in the provisioned database.',
                'status' => $migrationsOk ? 'completed' : 'pending',
            ],
            [
                'key' => 'initialization_job',
                'label' => 'One-click initialization completed',
                'description' => 'Optional setup wizard run from this page.',
                'status' => match (true) {
                    $latestInit?->status === ContainerAppInitialization::STATUS_COMPLETED => 'completed',
                    $latestInit?->isActive() => 'running',
                    $latestInit?->status === ContainerAppInitialization::STATUS_FAILED => 'failed',
                    default => 'pending',
                },
            ],
        ];
    }

    public function latestInitialization(Service $service): ?ContainerAppInitialization
    {
        return ContainerAppInitialization::where('service_id', $service->id)
            ->latest('id')
            ->first();
    }

    public function hasActiveInitialization(Service $service): bool
    {
        return ContainerAppInitialization::where('service_id', $service->id)
            ->whereIn('status', [
                ContainerAppInitialization::STATUS_PENDING,
                ContainerAppInitialization::STATUS_RUNNING,
            ])
            ->exists();
    }

    public function supportsTemplate(?string $slug): bool
    {
        return $slug === 'laravel';
    }

    public function syncApplicationDatabase(Service $service, ContainerDeployment $deployment, SSHService $ssh): string
    {
        $envValues = is_array($deployment->env_values) ? $deployment->env_values : [];
        $hostAppPath = $this->appDirectory->hostAppPath($deployment);
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);

        $envContent = $this->buildEnvFileContent($ssh, $deployment, $envValues);
        $ssh->upload($envContent, $hostAppPath.'/.env');
        $this->dockerExec(
            $ssh,
            $deployment->container_name,
            'set -e; cd /app; test -f .env',
            30
        );

        $serviceMeta = is_array($service->service_meta) ? $service->service_meta : [];
        $serviceMeta['laravel_env_configured_at'] = now()->toIso8601String();

        try {
            $this->dockerExec(
                $ssh,
                $deployment->container_name,
                'set -e; cd /app; php artisan migrate --force --no-interaction',
                $timeout
            );
            $serviceMeta['laravel_database_synced_at'] = now()->toIso8601String();
            $service->update(['service_meta' => $serviceMeta]);

            return 'Laravel .env updated from deployment credentials and migrations applied.';
        } catch (\Throwable $e) {
            $service->update(['service_meta' => $serviceMeta]);

            return 'Laravel .env updated from deployment credentials. Migrations could not run automatically: '.$e->getMessage();
        }
    }

    /**
     * @return array{ready: bool, has_laravel: bool, has_blocking_files: bool, can_clear: bool, blocking_paths?: array<int, string>}
     */
    public function getAppDirectoryStatus(Service $service): array
    {
        $service->loadMissing('containerDeployment.node');
        $deployment = $service->containerDeployment;

        $default = [
            'ready' => false,
            'has_laravel' => false,
            'has_blocking_files' => false,
            'can_clear' => false,
            'blocking_paths' => [],
        ];

        if (! $deployment || $deployment->status !== 'running' || ! $deployment->node) {
            return $default;
        }

        try {
            $ssh = SSHService::forNode($deployment->node);
            try {
                return $this->appDirectory->getDirectoryStatus($ssh, $deployment);
            } finally {
                $ssh->disconnect();
            }
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @return array{message: string}
     */
    public function clearApplicationDirectory(Service $service): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');
        $this->assertCanInitialize($service);

        $deployment = $service->containerDeployment;
        if (! $deployment || $deployment->status !== 'running') {
            throw new \DomainException('Container must be running before clearing /app.');
        }

        if ($this->hasActiveInitialization($service)) {
            throw new \DomainException('Wait for the current initialization to finish before clearing /app.');
        }

        $ssh = SSHService::forNode($deployment->node);

        try {
            if ($this->appDirectory->hasLaravelProject($ssh, $deployment)) {
                throw new \DomainException('A Laravel application is already installed in /app.');
            }

            $this->appDirectory->resetToPlaceholderState($ssh, $deployment);

            return ['message' => 'Application files cleared from /app. You can now initialize Laravel.'];
        } finally {
            $ssh->disconnect();
        }
    }

    private function assertCanInitialize(Service $service): void
    {
        if ($service->product?->type !== 'container_hosting') {
            throw new \DomainException('Service is not a container hosting service.');
        }

        if (! $this->supportsTemplate($service->product?->containerTemplate?->slug)) {
            throw new \DomainException('Application initialization is only available for Laravel containers.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInitialSteps(): array
    {
        $steps = [];
        foreach (self::STEP_DEFINITIONS as $key => $label) {
            $steps[] = [
                'key' => $key,
                'label' => $label,
                'status' => 'pending',
            ];
        }

        return $steps;
    }

    private function runStep(
        ContainerAppInitialization $initialization,
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $key,
        callable $callback,
        bool $allowWarningComplete = false
    ): void {
        $initialization->updateStep($key, 'running');
        $initialization->appendLog('Step started: '.(self::STEP_DEFINITIONS[$key] ?? $key));

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $initialization->updateStep($key, 'failed', $e->getMessage(), $e->getMessage());
            throw $e;
        }

        $message = is_array($result) ? ($result['message'] ?? 'Completed.') : (string) $result;
        $output = is_array($result) ? ($result['output'] ?? null) : null;
        $skipComplete = is_array($result) && ! empty($result['skip_complete']);

        if ($skipComplete && $allowWarningComplete) {
            $initialization->appendLog('Step warning: '.$message);

            return;
        }

        $initialization->updateStep($key, 'completed', $message, $output);
        $initialization->appendLog('Step completed: '.$message);
    }

    private function fail(ContainerAppInitialization $initialization, string $message): void
    {
        $initialization->update([
            'status' => ContainerAppInitialization::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
        $initialization->appendLog('Initialization failed: '.$message);
    }

    private function remoteFileExists(SSHService $ssh, ContainerDeployment $deployment, string $path): bool
    {
        try {
            $this->dockerExec(
                $ssh,
                $deployment->container_name,
                'test -f '.escapeshellarg($path),
                20
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function envValuePresent(SSHService $ssh, ContainerDeployment $deployment, string $key): bool
    {
        try {
            $script = 'set -e; cd /app; val=$(grep -E "^'.addslashes($key).'=" .env | head -n1 | cut -d= -f2- | tr -d "\r"); test -n "$val"';
            $this->dockerExec($ssh, $deployment->container_name, $script, 20);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function migrationsTableExists(SSHService $ssh, ContainerDeployment $deployment): bool
    {
        try {
            $this->dockerExec(
                $ssh,
                $deployment->container_name,
                'set -e; cd /app; php artisan migrate:status --no-interaction >/dev/null 2>&1',
                120
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $envValues
     */
    private function buildEnvFileContent(SSHService $ssh, ContainerDeployment $deployment, array $envValues): string
    {
        $example = '';
        try {
            $example = trim($this->dockerExec(
                $ssh,
                $deployment->container_name,
                'set -e; cd /app; cat .env.example',
                30
            ));
        } catch (\Throwable) {
            $example = file_get_contents(base_path('.env.example')) ?: '';
        }

        $replacements = [
            'APP_NAME' => 'Talksasa App',
            'APP_ENV' => $envValues['APP_ENV'] ?? 'production',
            'APP_DEBUG' => $envValues['APP_DEBUG'] ?? 'false',
            'APP_URL' => $deployment->getAccessUrl() ?? 'http://localhost',
            'DB_CONNECTION' => $envValues['DB_CONNECTION'] ?? 'mysql',
            'DB_HOST' => $envValues['DB_HOST'] ?? 'db',
            'DB_PORT' => $envValues['DB_PORT'] ?? '3306',
            'DB_DATABASE' => $envValues['DB_DATABASE'] ?? ($envValues['MYSQL_DATABASE'] ?? 'appdb'),
            'DB_USERNAME' => $envValues['DB_USERNAME'] ?? ($envValues['MYSQL_USER'] ?? 'appuser'),
            'DB_PASSWORD' => $envValues['DB_PASSWORD'] ?? ($envValues['MYSQL_PASSWORD'] ?? ''),
        ];

        $lines = preg_split("/\r\n|\n|\r/", $example) ?: [];
        $seen = [];
        $result = [];

        foreach ($lines as $line) {
            if (! str_contains($line, '=') || str_starts_with(ltrim($line), '#')) {
                $result[] = $line;

                continue;
            }

            [$key] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || ! array_key_exists($key, $replacements)) {
                $result[] = $line;

                continue;
            }

            $result[] = $key.'='.$this->quoteEnvValue($replacements[$key]);
            $seen[$key] = true;
        }

        foreach ($replacements as $key => $value) {
            if (! isset($seen[$key])) {
                $result[] = $key.'='.$this->quoteEnvValue($value);
            }
        }

        return implode("\n", $result)."\n";
    }

    private function quoteEnvValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/[\s#="\'\\\\]/', $value) === 1) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }

    private function dockerExec(
        SSHService $ssh,
        string $containerName,
        string $script,
        int $timeout,
        bool $asRoot = false
    ): string {
        $userFlag = $asRoot ? '-u 0' : '-u www-data';
        $script = $this->wrapContainerScript($script);

        return $ssh->exec(
            'docker exec '.$userFlag.' -w /app '.escapeshellarg($containerName).' sh -lc '.escapeshellarg($script),
            $timeout
        );
    }

    private function wrapContainerScript(string $script): string
    {
        if (str_contains($script, 'PATH="/usr/local/bin:/usr/bin:/bin"')) {
            return $script;
        }

        return 'export PATH="/usr/local/bin:/usr/bin:/bin"; '.$script;
    }

    private function composerInvoke(string $arguments): string
    {
        $arguments = trim($arguments);

        return $arguments === ''
            ? 'php '.self::COMPOSER_BIN
            : 'php '.self::COMPOSER_BIN.' '.$arguments;
    }

    private function assertComposerAvailable(SSHService $ssh, ContainerDeployment $deployment): void
    {
        try {
            $this->dockerExec(
                $ssh,
                $deployment->container_name,
                'test -f '.self::COMPOSER_BIN.' && php '.self::COMPOSER_BIN.' --version >/dev/null',
                20
            );
        } catch (\Throwable) {
            throw new \RuntimeException(
                'Composer is not available in this container. Redeploy the stack so the Talksasa PHP runtime image is used, then try again.'
            );
        }
    }
}
