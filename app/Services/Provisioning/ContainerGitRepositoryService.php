<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerGitPull;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\DB;

class ContainerGitRepositoryService
{
    public const STEP_DEFINITIONS = [
        'validate' => 'Validate repository and container',
        'prepare' => 'Prepare /app directory',
        'sync' => 'Clone or pull from Git',
        'environment' => 'Configure application environment',
        'composer' => 'Install Composer dependencies',
        'migrations' => 'Run database migrations',
        'post_pull' => 'Run stack post-pull steps',
        'permissions' => 'Apply file permissions',
        'runtime' => 'Refresh application runtime',
    ];

    public function __construct(
        private ContainerAppDirectoryService $appDirectory,
        private ?LaravelAppInitializationService $laravelInitialization = null,
        private ?ContainerStackCommandService $stackCommands = null,
        private ?ContainerDeploymentService $deploymentService = null,
    ) {
        $this->laravelInitialization ??= app(LaravelAppInitializationService::class);
        $this->stackCommands ??= new ContainerStackCommandService;
        $this->deploymentService ??= app(ContainerDeploymentService::class);
    }

    /**
     * @return array{url: string, branch: string, synced_at: ?string, has_git: bool, commit: ?string}
     */
    public function getStatus(Service $service, ContainerDeployment $deployment, SSHService $ssh): array
    {
        $settings = $this->repositorySettings($service);
        $hostAppPath = $this->appDirectory->hostAppPath($deployment);
        $hasGit = $this->hasGitCheckout($ssh, $hostAppPath);

        return [
            'url' => $settings['url'],
            'branch' => $settings['branch'],
            'synced_at' => $settings['synced_at'],
            'has_git' => $hasGit,
            'commit' => $hasGit ? $this->readShortCommit($ssh, $hostAppPath) : null,
        ];
    }

    /**
     * @return array{url: string, branch: string, synced_at: ?string}
     */
    public function repositorySettings(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        return [
            'url' => trim((string) ($meta['source_repo_url'] ?? '')),
            'branch' => $this->normalizeBranch((string) ($meta['source_repo_branch'] ?? 'main')),
            'synced_at' => isset($meta['source_repo_synced_at']) ? (string) $meta['source_repo_synced_at'] : null,
        ];
    }

    public function connect(
        Service $service,
        string $repoUrl,
        string $branch,
        ?string $repoToken = null,
        ?string $composerGithubToken = null,
        bool $removeRepoToken = false,
        bool $removeComposerAuth = false,
    ): void {
        app(ContainerGitCredentialsService::class)->applyConnection(
            $service,
            $repoUrl,
            $branch,
            $repoToken,
            $composerGithubToken,
            $removeRepoToken,
            $removeComposerAuth,
        );
    }

    public function requestPull(
        Service $service,
        User $user,
        bool $replaceExisting,
        bool $runComposer = true,
        bool $runMigrations = true
    ): ContainerGitPull {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');

        if (! $this->supportsTemplate($service->product?->containerTemplate?->slug)) {
            throw new \InvalidArgumentException('Git repository pulls are not supported for this container type.');
        }

        $settings = $this->repositorySettings($service);
        if ($settings['url'] === '') {
            throw new \InvalidArgumentException('Connect a Git repository before pulling code.');
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || $deployment->status !== 'running') {
            throw new \InvalidArgumentException('Start the container before pulling code from Git.');
        }

        if ($this->hasActivePull($service)) {
            throw new \DomainException('A Git pull is already in progress.');
        }

        return DB::transaction(function () use ($service, $user, $deployment, $replaceExisting, $runComposer, $runMigrations) {
            return ContainerGitPull::create([
                'service_id' => $service->id,
                'container_deployment_id' => $deployment->id,
                'user_id' => $user->id,
                'template_slug' => (string) ($service->product?->containerTemplate?->slug ?? ''),
                'status' => ContainerGitPull::STATUS_PENDING,
                'options' => [
                    'replace_existing' => $replaceExisting,
                    'run_composer' => $runComposer,
                    'run_migrations' => $runMigrations,
                ],
                'steps' => $this->buildInitialSteps($service, $runComposer, $runMigrations),
            ]);
        });
    }

    public function runPull(ContainerGitPull $pull): void
    {
        $pull->loadMissing('service.product.containerTemplate', 'service.containerDeployment.node');
        $service = $pull->service;
        $deployment = $pull->deployment ?? $service->containerDeployment;

        if (! $deployment || ! $deployment->node) {
            $this->failPull($pull, 'Container deployment is not available.');

            return;
        }

        $options = is_array($pull->options) ? $pull->options : [];
        $replaceExisting = (bool) ($options['replace_existing'] ?? false);
        $runComposer = (bool) ($options['run_composer'] ?? true);
        $runMigrations = (bool) ($options['run_migrations'] ?? true);

        $pull->update([
            'status' => ContainerGitPull::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ]);
        $pull->appendLog('Git pull started for service '.$service->id);

        $ssh = SSHService::forNode($deployment->node);
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);

        try {
            $settings = $this->repositorySettings($service);
            $hostAppPath = $this->appDirectory->hostAppPath($deployment);

            $this->runPullStep($pull, 'validate', function () use ($settings, $deployment, $hostAppPath, $ssh, $replaceExisting) {
                if ($deployment->status !== 'running') {
                    throw new \RuntimeException('Container is not running.');
                }

                if ($settings['url'] === '') {
                    throw new \RuntimeException('Connect a Git repository before pulling code.');
                }

                $hasGit = $this->hasGitCheckout($ssh, $hostAppPath);
                if (! $hasGit && ! $replaceExisting) {
                    throw new \RuntimeException(
                        'No Git checkout exists in /app yet. Enable "Replace /app contents" to clone this repository for the first time.'
                    );
                }

                return [
                    'message' => $hasGit
                        ? 'Existing Git checkout found in /app.'
                        : 'Ready to clone repository into /app.',
                ];
            });

            $this->runPullStep($pull, 'prepare', function () use ($ssh, $deployment) {
                $this->appDirectory->reclaimHostAppOwnershipForGit($ssh, $deployment);

                return 'Application directory prepared for Git sync.';
            });

            $hasGit = $this->hasGitCheckout($ssh, $hostAppPath);
            $freshClone = ! $hasGit || $replaceExisting;

            $this->runPullStep($pull, 'sync', function () use ($ssh, $service, $hostAppPath, $settings, $freshClone, $pull, $deployment) {
                if ($freshClone) {
                    $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
                    app(ContainerStackCommandService::class)->stopApplicationContainerForMaintenance(
                        $ssh,
                        $containerPath,
                        $deployment->container_name
                    );
                    $pull->appendLog('Application container stopped before replacing /app contents.');
                }

                $pull->appendLog(sprintf(
                    '%s branch %s from %s',
                    $freshClone ? 'Cloning' : 'Pulling',
                    $settings['branch'],
                    $this->maskRepositoryUrl($settings['url'])
                ));

                $output = $this->syncToHost($ssh, $service, $hostAppPath, $settings['url'], $settings['branch'], $freshClone);
                if ($output !== '') {
                    $pull->appendLog($output);
                }

                $commit = $this->readShortCommit($ssh, $hostAppPath);

                return [
                    'message' => 'Repository synced to /app'.($commit ? " ({$commit})" : '').'.',
                    'output' => $output,
                    'commit' => $commit,
                ];
            });

            if ($this->isLaravelService($service)) {
                $this->pathResolver()->persistResolvedPaths($service, $ssh, $deployment);

                // Point PHP at the correct web root before composer/env steps (e.g. /app vs /app/public).
                $this->runPullStep($pull, 'runtime', function () use ($service, $deployment, $ssh) {
                    $message = $this->deploymentService->refreshLaravelServeCompose($service, $deployment, $ssh);

                    return $message !== '' ? $message : 'Laravel web root refreshed.';
                });
            }

            $commit = $this->readShortCommit($ssh, $hostAppPath);

            if ($this->isLaravelService($service) && $this->appDirectory->hasLaravelProject($ssh, $deployment)) {
                if ($runComposer) {
                    $this->runPullStep($pull, 'composer', function () use ($ssh, $deployment, $timeout, $service) {
                        $this->laravelInitialization->runComposerInstall($ssh, $deployment, $timeout, $service);

                        return 'Composer dependencies updated.';
                    });
                } else {
                    $this->skipPullStep($pull, 'composer', 'Skipped by request.');
                }

                $this->runPullStep($pull, 'environment', function () use ($service, $deployment, $ssh) {
                    $writeMessage = $this->laravelInitialization->writeApplicationEnvironment(
                        $service,
                        $deployment,
                        $ssh,
                        preserveExisting: true,
                    );

                    try {
                        $bootstrapMessage = $this->laravelInitialization->bootstrapApplicationEnvironment($service, $deployment, $ssh);

                        return trim($writeMessage.' '.$bootstrapMessage);
                    } catch (\RuntimeException $e) {
                        if (str_contains($e->getMessage(), 'Composer dependencies are not installed')) {
                            return $writeMessage.' Application key generation deferred until Composer dependencies are installed.';
                        }

                        throw $e;
                    }
                });

                if ($runMigrations) {
                    $this->runPullStep($pull, 'migrations', function () use ($service, $ssh, $deployment) {
                        $message = app(LaravelPostSyncService::class)->run($service, $deployment, $ssh, new LaravelPostSyncOptions(
                            refreshRuntime: false,
                            configureEnvironment: false,
                            runComposer: false,
                            runMigrations: true,
                            finalizeApplication: false,
                            normalizePermissions: false,
                            waitForDatabase: true,
                        ));

                        return $message !== '' ? $message : 'Database migrations applied.';
                    });
                } else {
                    $this->skipPullStep($pull, 'migrations', 'Skipped by request.');
                }
            } elseif ($this->isLaravelService($service)) {
                $this->skipPullStep($pull, 'environment', 'No Laravel project detected in /app.');
                $this->skipPullStep($pull, 'composer', 'No Laravel project detected in /app.');
                $this->skipPullStep($pull, 'migrations', 'No Laravel project detected in /app.');
            } else {
                $this->runPullStep($pull, 'post_pull', function () use ($service, $deployment, $ssh, $pull) {
                    $messages = $this->stackCommands->runPostPullSteps($service, $deployment, $ssh);
                    foreach ($messages as $message) {
                        $pull->appendLog($message);
                    }

                    return implode(' ', $messages) ?: 'Post-pull steps completed.';
                });
            }

            $this->runPullStep($pull, 'permissions', function () use ($ssh, $deployment, $service) {
                if ($this->isLaravelService($service) && $this->appDirectory->hasLaravelProject($ssh, $deployment)) {
                    $projectRoot = $this->pathResolver()->projectRootFromServiceMeta($service);
                    $postSync = app(LaravelPostSyncService::class);
                    $postSync->finalizeApplication($ssh, $deployment, $projectRoot);
                    $this->appDirectory->normalizeLaravelPermissions($ssh, $deployment, $projectRoot);

                    return 'Laravel permissions and runtime caches finalized.';
                }

                $this->appDirectory->normalizePermissions($ssh, $deployment);

                return 'File permissions normalized.';
            });

            if (! $this->isLaravelService($service)) {
                $this->runPullStep($pull, 'runtime', function () use ($service, $deployment, $ssh) {
                    $message = $this->deploymentService->refreshApplicationRuntimeCompose($service, $deployment, $ssh);

                    return $message !== '' ? $message : 'Application runtime refreshed.';
                });
            } else {
                $this->skipPullStep($pull, 'runtime', 'Laravel web root refreshed after Git sync.');
            }

            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['source_repo_synced_at'] = now()->toIso8601String();
            $service->update(['service_meta' => $meta]);

            $pull->update([
                'status' => ContainerGitPull::STATUS_COMPLETED,
                'commit' => $commit,
                'completed_at' => now(),
            ]);
            $pull->appendLog('Git pull completed successfully.');
        } catch (\Throwable $e) {
            $this->failPull($pull, $e->getMessage());
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{message: string, commit: ?string}
     */
    public function pull(
        Service $service,
        ContainerDeployment $deployment,
        bool $replaceExisting,
        bool $runComposer = true,
        bool $runMigrations = true
    ): array {
        $service->loadMissing('user');

        if (! $service->user) {
            throw new \RuntimeException('Service owner is required to run a Git pull.');
        }

        $pull = $this->requestPull($service, $service->user, $replaceExisting, $runComposer, $runMigrations);
        $this->runPull($pull);
        $pull->refresh();

        if ($pull->status === ContainerGitPull::STATUS_FAILED) {
            throw new \RuntimeException($pull->error_message ?? 'Git pull failed.');
        }

        return [
            'message' => 'Repository synced'.($pull->commit ? " ({$pull->commit})" : '').'.',
            'commit' => $pull->commit,
        ];
    }

    public function latestPull(Service $service): ?ContainerGitPull
    {
        return ContainerGitPull::where('service_id', $service->id)
            ->latest('id')
            ->first();
    }

    public function hasActivePull(Service $service): bool
    {
        return ContainerGitPull::where('service_id', $service->id)
            ->whereIn('status', [
                ContainerGitPull::STATUS_PENDING,
                ContainerGitPull::STATUS_RUNNING,
            ])
            ->exists();
    }

    public function syncForDeploy(SSHService $ssh, Service $service, string $hostAppPath): void
    {
        $settings = $this->repositorySettings($service);
        $ssh->mkdirp($hostAppPath);

        if ($settings['url'] === '') {
            $this->appDirectory->ensurePlaceholderState($ssh, $hostAppPath);

            return;
        }

        $hasGit = $this->hasGitCheckout($ssh, $hostAppPath);
        $this->appDirectory->reclaimHostPathOwnershipForGit($ssh, $hostAppPath);
        $this->syncToHost($ssh, $service, $hostAppPath, $settings['url'], $settings['branch'], ! $hasGit);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['source_repo_synced_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);
    }

    public function normalizeRepositoryUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('Repository URL is required.');
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Repository URL must be a valid HTTPS URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('Only HTTPS repository URLs are supported.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new \InvalidArgumentException('Repository host is not allowed.');
        }

        return $url;
    }

    public function normalizeBranch(string $branch): string
    {
        $branch = trim($branch);
        if ($branch === '') {
            return 'main';
        }

        if (! preg_match('/^[A-Za-z0-9._\\/-]+$/', $branch)) {
            throw new \InvalidArgumentException('Branch name contains invalid characters.');
        }

        return $branch;
    }

    public function supportsTemplate(?string $slug): bool
    {
        return in_array($slug, ['laravel', 'php', 'nodejs', 'python', 'ruby'], true);
    }

    public function gitInvocation(string $hostAppPath): string
    {
        return 'git -c safe.directory='.escapeshellarg($hostAppPath);
    }

    /**
     * @return list<array{key: string, label: string, status: string}>
     */
    private function buildInitialSteps(Service $service, bool $runComposer, bool $runMigrations): array
    {
        $steps = [
            $this->makeStep('validate'),
            $this->makeStep('prepare'),
            $this->makeStep('sync'),
        ];

        if ($this->isLaravelService($service)) {
            $steps[] = $this->makeStep('runtime');
            $steps[] = $this->makeStep('composer');
            $steps[] = $this->makeStep('environment');
            $steps[] = $this->makeStep('migrations');
        } else {
            $steps[] = $this->makeStep('post_pull');
        }

        $steps[] = $this->makeStep('permissions');

        if (! $this->isLaravelService($service)) {
            $steps[] = $this->makeStep('runtime');
        }

        return $steps;
    }

    /**
     * @return array{key: string, label: string, status: string}
     */
    private function makeStep(string $key): array
    {
        return [
            'key' => $key,
            'label' => self::STEP_DEFINITIONS[$key] ?? $key,
            'status' => 'pending',
        ];
    }

    private function runPullStep(ContainerGitPull $pull, string $key, callable $callback): void
    {
        $pull->updateStep($key, 'running');
        $pull->appendLog('Step started: '.(self::STEP_DEFINITIONS[$key] ?? $key));

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $pull->updateStep($key, 'failed', $e->getMessage(), $e->getMessage());
            throw $e;
        }

        $message = is_array($result) ? ($result['message'] ?? 'Completed.') : (string) $result;
        $output = is_array($result) ? ($result['output'] ?? null) : null;

        if (is_array($result) && ! empty($result['commit'])) {
            $pull->update(['commit' => $result['commit']]);
        }

        $pull->updateStep($key, 'completed', $message, $output);
        $pull->appendLog('Step completed: '.$message);
    }

    private function skipPullStep(ContainerGitPull $pull, string $key, string $message): void
    {
        $pull->updateStep($key, 'skipped', $message);
        $pull->appendLog('Step skipped: '.$message);
    }

    private function failPull(ContainerGitPull $pull, string $message): void
    {
        $pull->update([
            'status' => ContainerGitPull::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
        $pull->appendLog('Git pull failed: '.$message);
    }

    private function maskRepositoryUrl(string $url): string
    {
        return app(ContainerGitCredentialsService::class)->maskRepositoryUrl($url);
    }

    private function syncToHost(
        SSHService $ssh,
        Service $service,
        string $hostAppPath,
        string $repoUrl,
        string $branch,
        bool $freshClone
    ): string {
        $authenticatedUrl = app(ContainerGitCredentialsService::class)->authenticatedCloneUrl($service, $repoUrl);
        $pathArg = escapeshellarg($hostAppPath);
        $repoArg = escapeshellarg($authenticatedUrl);
        $branchArg = escapeshellarg($branch);
        $git = $this->gitInvocation($hostAppPath);

        if ($freshClone) {
            // Clear contents but keep the directory itself so a live container's
            // /app bind mount is not destroyed (rm -rf on the mount target breaks docker exec).
            $script = 'set -e; '
                ."mkdir -p {$pathArg}; "
                ."find {$pathArg} -mindepth 1 -maxdepth 1 -exec rm -rf {} +; "
                ."{$git} clone --depth=1 --branch {$branchArg} {$repoArg} {$pathArg} 2>&1";
        } else {
            $backupEnv = $this->backupEnvFilesScript($pathArg);
            $restoreEnv = $this->restoreEnvFilesScript($pathArg);
            $script = 'set -e; '
                ."cd {$pathArg}; "
                .$backupEnv
                ."{$git} fetch --depth=1 origin {$branchArg} 2>&1; "
                ."{$git} checkout -f {$branchArg} 2>&1; "
                ."{$git} reset --hard FETCH_HEAD 2>&1; "
                .$restoreEnv;
        }

        return trim($ssh->exec('sh -lc '.escapeshellarg($script), 180));
    }

    private function backupEnvFilesScript(string $pathArg): string
    {
        return 'find '.$pathArg.' -maxdepth 4 -name .env -type f 2>/dev/null | while IFS= read -r f; do cp "$f" "$f.talksasa-backup"; done; ';
    }

    private function restoreEnvFilesScript(string $pathArg): string
    {
        return 'find '.$pathArg.' -maxdepth 4 -name .env.talksasa-backup -type f 2>/dev/null | while IFS= read -r f; do mv "$f" "${f%.talksasa-backup}"; done; ';
    }

    private function hasGitCheckout(SSHService $ssh, string $hostAppPath): bool
    {
        $pathArg = escapeshellarg($hostAppPath.'/.git');

        return trim($ssh->exec("[ -d {$pathArg} ] && echo yes || echo no", 10)) === 'yes';
    }

    private function readShortCommit(SSHService $ssh, string $hostAppPath): ?string
    {
        $pathArg = escapeshellarg($hostAppPath);
        $git = $this->gitInvocation($hostAppPath);
        $output = trim($ssh->exec(
            "sh -lc 'cd {$pathArg} && {$git} rev-parse --short HEAD 2>/dev/null || true'",
            15
        ));

        return $output !== '' ? $output : null;
    }

    private function isLaravelService(Service $service): bool
    {
        return ($service->product?->containerTemplate?->slug ?? '') === 'laravel';
    }

    private function pathResolver(): LaravelProjectPathResolver
    {
        return app(LaravelProjectPathResolver::class);
    }
}
