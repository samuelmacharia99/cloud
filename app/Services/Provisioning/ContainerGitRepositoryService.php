<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerGitPull;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContainerGitRepositoryService
{
    public const STEP_DEFINITIONS = [
        'validate' => 'Validate repository and container',
        'prepare' => 'Prepare /app directory',
        'sync' => 'Clone or pull from Git',
        'environment' => 'Configure application environment',
        'composer' => 'Install Composer dependencies',
        'migrations' => 'Run database migrations',
        'frontend' => 'Build frontend on Node sidecar (Next/Vite)',
        'post_pull' => 'Run stack post-pull steps',
        'permissions' => 'Apply file permissions',
        'runtime' => 'Refresh application runtime',
        'health' => 'Verify application health',
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
        DB::transaction(function () use ($service, $repoUrl, $branch, $repoToken, $composerGithubToken, $removeRepoToken, $removeComposerAuth): void {
            Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ($this->hasActivePull($service)) {
                throw new \DomainException('Wait for the active Git pull to finish before changing repository settings.');
            }

            app(ContainerGitCredentialsService::class)->applyConnection(
                $service,
                $repoUrl,
                $branch,
                $repoToken,
                $composerGithubToken,
                $removeRepoToken,
                $removeComposerAuth,
            );
        });
    }

    public function requestPull(
        Service $service,
        User $user,
        bool $replaceExisting,
        bool $runComposer = true,
        bool $runMigrations = true,
        bool $forceRebuild = false
    ): ContainerGitPull {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');

        if (! $this->supportsService($service)) {
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

        return DB::transaction(function () use ($service, $user, $deployment, $replaceExisting, $runComposer, $runMigrations, $forceRebuild) {
            // Serialize creation on the service row so simultaneous manual/webhook
            // requests cannot both pass an unlocked "active pull" check.
            Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $this->expireStalePulls($service);

            if ($this->hasActivePull($service, reconcileStale: false)) {
                throw new \DomainException('A Git pull is already in progress.');
            }

            $settings = $this->repositorySettings($service);

            return ContainerGitPull::create([
                'service_id' => $service->id,
                'container_deployment_id' => $deployment->id,
                'user_id' => $user->id,
                'template_slug' => (string) ($this->templateSlugFor($service) ?? ''),
                'status' => ContainerGitPull::STATUS_PENDING,
                'options' => [
                    'replace_existing' => $replaceExisting,
                    'run_composer' => $runComposer,
                    'run_migrations' => $runMigrations,
                    'force_rebuild' => $forceRebuild,
                    'repository_url' => $settings['url'],
                    'repository_branch' => $settings['branch'],
                ],
                'steps' => $this->buildInitialSteps($service, $runComposer, $runMigrations),
            ]);
        });
    }

    public function runPull(ContainerGitPull $pull): void
    {
        $pull->loadMissing('service.product.containerTemplate', 'service.containerDeployment.node');
        $service = $pull->service;
        $deployment = $service->containerDeployment;

        if (! $deployment || ! $deployment->node) {
            $this->failPull($pull, 'Container deployment is not available.');

            return;
        }

        if ($pull->container_deployment_id !== null
            && (int) $pull->container_deployment_id !== (int) $deployment->id
        ) {
            $this->failPull($pull, 'The application was redeployed after this pull was queued. Start a new Git pull.');

            return;
        }

        $options = is_array($pull->options) ? $pull->options : [];
        $replaceExisting = (bool) ($options['replace_existing'] ?? false);
        $runComposer = (bool) ($options['run_composer'] ?? true);
        $runMigrations = (bool) ($options['run_migrations'] ?? true);
        $forceRebuild = (bool) ($options['force_rebuild'] ?? false);

        $pull->update([
            'status' => ContainerGitPull::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ]);
        $pull->appendLog('Git pull started for service '.$service->id);

        $ssh = SSHService::forNode($deployment->node);
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);

        $hostAppPath = null;
        $previousAppPath = null;

        try {
            $options = is_array($pull->options) ? $pull->options : [];
            $settings = [
                'url' => (string) ($options['repository_url'] ?? $this->repositorySettings($service)['url']),
                'branch' => (string) ($options['repository_branch'] ?? $this->repositorySettings($service)['branch']),
            ];
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

            $this->runPullStep($pull, 'sync', function () use ($ssh, $service, $hostAppPath, $settings, $freshClone, $pull, $deployment, &$previousAppPath) {
                $pull->appendLog(sprintf(
                    '%s branch %s from %s',
                    $freshClone ? 'Cloning' : 'Pulling',
                    $settings['branch'],
                    $this->maskRepositoryUrl($settings['url'])
                ));

                $sync = $this->syncToHost(
                    $ssh,
                    $service,
                    $hostAppPath,
                    $settings['url'],
                    $settings['branch'],
                    $deployment,
                    $pull->id,
                );
                $output = $sync['output'];
                $previousAppPath = $sync['previous_path'];
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

                $this->runPullStep($pull, 'frontend', function () use ($service, $deployment, $ssh, $forceRebuild) {
                    $hostAppPath = $this->appDirectory->hostAppPath($deployment);
                    // Shared volume already has the git sync. Build on the Node sidecar;
                    // Composer/migrations already ran on the PHP backend container.
                    $messages = $this->stackCommands->installLaravelFrontendDependencies(
                        $ssh,
                        $deployment,
                        $hostAppPath,
                        (int) config('containers.node_build.command_timeout_seconds', 900),
                        $forceRebuild
                    );

                    if ($this->stackCommands->hostHasNextFrontend($ssh, $hostAppPath)) {
                        $wasSidecar = $this->deploymentService->usesLaravelNextSidecarStack($deployment);
                        $this->deploymentService->refreshLaravelNextFrontendRuntime($ssh, $service, $deployment);
                        $messages[] = $wasSidecar
                            ? 'Frontend rebuilt on the Node sidecar; PHP backend left running. Domains stay on this service (edge → UI/API).'
                            : 'Provisioned backend (PHP) + frontend (Node) + edge sidecars. Domains stay on this service.';
                    }

                    return $messages !== []
                        ? implode(' ', $messages)
                        : 'No /app/frontend package.json; skipped frontend build.';
                });
            } elseif ($this->isLaravelService($service)) {
                $this->skipPullStep($pull, 'environment', 'No Laravel project detected in /app.');
                $this->skipPullStep($pull, 'composer', 'No Laravel project detected in /app.');
                $this->skipPullStep($pull, 'migrations', 'No Laravel project detected in /app.');
                $this->skipPullStep($pull, 'frontend', 'No Laravel project detected in /app.');
            } else {
                $this->runPullStep($pull, 'post_pull', function () use ($service, $deployment, $ssh, $pull, $forceRebuild) {
                    $messages = $this->stackCommands->runPostPullSteps($service, $deployment, $ssh, $forceRebuild);
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
            }

            $this->runPullStep($pull, 'health', function () use ($service, $deployment, $ssh) {
                $this->deploymentService->waitForContainerRunning($ssh, $deployment->container_name);
                if ($this->isLaravelService($service)) {
                    $this->deploymentService->waitForLaravelHttpHealth($ssh, $deployment);

                    return 'Container and Laravel HTTP health checks passed.';
                }

                return 'Application container is running.';
            });

            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['source_repo_synced_at'] = now()->toIso8601String();
            $service->update(['service_meta' => $meta]);

            $pull->update([
                'status' => ContainerGitPull::STATUS_COMPLETED,
                'commit' => $commit,
                'completed_at' => now(),
            ]);
            $pull->appendLog('Git pull completed successfully.');

            if ($previousAppPath !== null) {
                $this->deleteRemotePathQuietly($ssh, $previousAppPath);
            }
            $this->clearReplacementState($pull);
        } catch (\Throwable $e) {
            $pull->refresh();
            $replacementState = is_array($pull->options)
                ? ($pull->options['replacement_state'] ?? null)
                : null;
            if (is_array($replacementState)) {
                try {
                    $this->recoverInterruptedPull($pull);
                    $pull->appendLog('Application recovery completed after pull failure.');
                } catch (\Throwable $recoveryError) {
                    $pull->appendLog('Automatic application recovery failed: '.$recoveryError->getMessage());
                }
            }

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
        bool $runMigrations = true,
        bool $forceRebuild = false
    ): array {
        $service->loadMissing('user');

        if (! $service->user) {
            throw new \RuntimeException('Service owner is required to run a Git pull.');
        }

        $pull = $this->requestPull($service, $service->user, $replaceExisting, $runComposer, $runMigrations, $forceRebuild);
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

    public function hasActivePull(Service $service, bool $reconcileStale = true): bool
    {
        if ($reconcileStale) {
            $this->expireStalePulls($service);
        }

        return ContainerGitPull::where('service_id', $service->id)
            ->whereIn('status', [
                ContainerGitPull::STATUS_PENDING,
                ContainerGitPull::STATUS_RUNNING,
            ])
            ->exists();
    }

    public function expireStalePulls(Service $service): void
    {
        $pendingCutoff = now()->subMinutes((int) config('containers.git_pull.pending_timeout_minutes', 30));
        $runningCutoff = now()->subMinutes((int) config('containers.git_pull.running_timeout_minutes', 75));

        $stalePulls = ContainerGitPull::query()
            ->where('service_id', $service->id)
            ->where(function ($query) use ($pendingCutoff, $runningCutoff) {
                $query->where(function ($pending) use ($pendingCutoff) {
                    $pending->where('status', ContainerGitPull::STATUS_PENDING)
                        ->where('created_at', '<', $pendingCutoff);
                })->orWhere(function ($running) use ($runningCutoff) {
                    $running->where('status', ContainerGitPull::STATUS_RUNNING)
                        ->where('started_at', '<', $runningCutoff);
                });
            })
            ->get();

        foreach ($stalePulls as $pull) {
            $replacementState = is_array($pull->options)
                ? ($pull->options['replacement_state'] ?? null)
                : null;
            if (is_array($replacementState)) {
                try {
                    $this->recoverInterruptedPull($pull);
                    $pull->appendLog('Recovered application state from an interrupted stale pull.');
                } catch (\Throwable $e) {
                    $pull->appendLog('Stale pull recovery failed: '.$e->getMessage());
                }
            }

            $pull->update([
                'status' => ContainerGitPull::STATUS_FAILED,
                'error_message' => 'Git pull stopped updating and was marked failed. Restart it to try again.',
                'completed_at' => now(),
            ]);
        }
    }

    public function syncForDeploy(SSHService $ssh, Service $service, string $hostAppPath): void
    {
        $settings = $this->repositorySettings($service);
        $ssh->mkdirp($hostAppPath);

        // WordPress (and other non-Git stacks) must keep the bind mount empty/non-cloned
        // so the official image can copy core. Ignore forged or stale source_repo_* meta.
        if (! $this->supportsService($service) || $settings['url'] === '') {
            $service->loadMissing('product.containerTemplate');
            // Official wordpress image copies core into an empty volume; placeholders block that.
            if (($this->templateSlugFor($service) ?? '') !== 'wordpress') {
                $this->appDirectory->ensurePlaceholderState($ssh, $hostAppPath);
            }

            return;
        }

        $this->appDirectory->reclaimHostPathOwnershipForGit($ssh, $hostAppPath);
        $sync = $this->syncToHost(
            $ssh,
            $service,
            $hostAppPath,
            $settings['url'],
            $settings['branch'],
            $service->containerDeployment,
        );
        if ($sync['previous_path'] !== null) {
            $this->deleteRemotePathQuietly($ssh, $sync['previous_path']);
        }

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

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new \InvalidArgumentException('Repository URL must use the standard HTTPS port.');
        }

        if (isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Repository URL must not contain a fragment.');
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['token', 'access_token', 'private_token', 'auth'] as $sensitiveKey) {
            if (array_key_exists($sensitiveKey, $query)) {
                throw new \InvalidArgumentException('Put repository credentials in the token field, not in the URL.');
            }
        }

        $configuredHosts = app()->bound('config')
            ? (array) config('containers.git_pull.allowed_hosts', [])
            : [];
        $allowedHosts = array_filter(array_map('strtolower', $configuredHosts));
        if ($allowedHosts !== [] && ! in_array($host, $allowedHosts, true)) {
            throw new \InvalidArgumentException('Repository host is not in the allowed Git host list.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        ) {
            throw new \InvalidArgumentException('Private and reserved repository addresses are not allowed.');
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

        if (str_starts_with($branch, '-')
            || str_starts_with($branch, '.')
            || str_ends_with($branch, '.')
            || str_ends_with($branch, '/')
            || str_ends_with($branch, '.lock')
            || str_contains($branch, '..')
            || str_contains($branch, '//')
        ) {
            throw new \InvalidArgumentException('Branch name is not a valid Git branch.');
        }

        return $branch;
    }

    public function supportsTemplate(?string $slug): bool
    {
        return in_array($slug, ['laravel', 'php', 'nodejs', 'python', 'ruby'], true);
    }

    /**
     * Whether this service's effective stack (product or shared-plan meta) supports Git.
     */
    public function supportsService(Service $service): bool
    {
        return $this->supportsTemplate($this->templateSlugFor($service));
    }

    public function templateSlugFor(Service $service): ?string
    {
        return $service->effectiveContainerTemplate()?->slug;
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
            $steps[] = $this->makeStep('frontend');
        } else {
            $steps[] = $this->makeStep('post_pull');
        }

        $steps[] = $this->makeStep('permissions');

        if (! $this->isLaravelService($service)) {
            $steps[] = $this->makeStep('runtime');
        }

        $steps[] = $this->makeStep('health');

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
        $this->assertPullNotCancelled($pull);

        $pull->updateStep($key, 'running');
        $pull->appendLog('Step started: '.(self::STEP_DEFINITIONS[$key] ?? $key));

        try {
            $this->assertPullNotCancelled($pull);
            $result = $callback();
            $this->assertPullNotCancelled($pull);
        } catch (\Throwable $e) {
            $pull->refresh();
            if ($pull->status === ContainerGitPull::STATUS_CANCELLED) {
                $pull->updateStep($key, 'failed', 'Cancelled by user.');
                throw $e;
            }

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

    private function assertPullNotCancelled(ContainerGitPull $pull): void
    {
        $pull->refresh();

        if ($pull->status === ContainerGitPull::STATUS_CANCELLED) {
            throw new \RuntimeException('Git pull was cancelled.');
        }
    }

    private function skipPullStep(ContainerGitPull $pull, string $key, string $message): void
    {
        $pull->updateStep($key, 'skipped', $message);
        $pull->appendLog('Step skipped: '.$message);
    }

    private function failPull(ContainerGitPull $pull, string $message): void
    {
        $message = ContainerGitPull::truncateErrorMessage($message);
        $pull->refresh();
        if ($pull->status === ContainerGitPull::STATUS_CANCELLED) {
            return;
        }

        try {
            $pull->update([
                'status' => ContainerGitPull::STATUS_FAILED,
                'error_message' => $message,
                'completed_at' => now(),
            ]);
            $pull->appendLog('Git pull failed: '.$message);
        } catch (\Throwable $e) {
            try {
                $pull->forceFill([
                    'status' => ContainerGitPull::STATUS_FAILED,
                    'error_message' => 'Git pull failed. The error details were too large to store.',
                    'completed_at' => now(),
                ])->save();
            } catch (\Throwable) {
            }

            \Log::warning('Failed to persist git pull error message', [
                'pull_id' => $pull->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop a pending/running pull so the customer can start again.
     */
    public function cancelPull(ContainerGitPull $pull, string $reason = 'Cancelled by user.'): ContainerGitPull
    {
        $pull->refresh();

        if (! $pull->isActive()) {
            return $pull;
        }

        $steps = is_array($pull->steps) ? $pull->steps : [];
        foreach ($steps as &$step) {
            $status = $step['status'] ?? 'pending';
            if ($status === 'running') {
                $step['status'] = 'failed';
                $step['message'] = $reason;
                $step['completed_at'] = now()->toIso8601String();
            } elseif ($status === 'pending') {
                $step['status'] = 'skipped';
                $step['message'] = 'Skipped because the pull was cancelled.';
                $step['completed_at'] = now()->toIso8601String();
            }
        }
        unset($step);

        $pull->update([
            'status' => ContainerGitPull::STATUS_CANCELLED,
            'steps' => $steps,
            'error_message' => ContainerGitPull::truncateErrorMessage($reason),
            'completed_at' => now(),
        ]);
        $pull->appendLog('Git pull cancelled: '.$reason);

        return $pull->fresh();
    }

    /**
     * @return list<ContainerGitPull>
     */
    public function cancelActivePulls(Service $service, string $reason = 'Cancelled by user.'): array
    {
        $active = ContainerGitPull::query()
            ->where('service_id', $service->id)
            ->whereIn('status', [
                ContainerGitPull::STATUS_PENDING,
                ContainerGitPull::STATUS_RUNNING,
            ])
            ->orderBy('id')
            ->get();

        $cancelled = [];
        foreach ($active as $pull) {
            $cancelled[] = $this->cancelPull($pull, $reason);
        }

        return $cancelled;
    }

    /**
     * Cancel any in-flight pull, then queue a new one (optionally reusing the last options).
     */
    public function restartPull(
        Service $service,
        User $user,
        ?bool $replaceExisting = null,
        ?bool $runComposer = null,
        ?bool $runMigrations = null,
        ?bool $forceRebuild = null,
    ): ContainerGitPull {
        $latest = $this->latestPull($service);
        $options = is_array($latest?->options) ? $latest->options : [];

        $this->cancelActivePulls($service, 'Cancelled so a new Git pull can start.');

        return $this->requestPull(
            $service,
            $user,
            $replaceExisting ?? (bool) ($options['replace_existing'] ?? false),
            $runComposer ?? (bool) ($options['run_composer'] ?? true),
            $runMigrations ?? (bool) ($options['run_migrations'] ?? true),
            $forceRebuild ?? (bool) ($options['force_rebuild'] ?? false),
        );
    }

    private function maskRepositoryUrl(string $url): string
    {
        return app(ContainerGitCredentialsService::class)->maskRepositoryUrl($url);
    }

    private function rewriteGitCloneException(\Throwable $e, Service $service, string $cleanUrl): \RuntimeException
    {
        $raw = $e->getMessage();
        $lower = strtolower($raw);
        $masked = $this->maskRepositoryUrl($cleanUrl);

        if ($this->isGitAuthenticationFailure($lower)) {
            $hasToken = app(ContainerGitCredentialsService::class)->hasRepositoryToken($service);
            $reason = $hasToken
                ? 'GitHub rejected the saved access token. Update the token and confirm it can read this repository, then retry.'
                : 'This repository requires authentication, but no GitHub access token is saved. Add a personal access token with repo read access, then retry the pull.';

            return new \RuntimeException("Could not clone {$masked}: {$reason}", 0, $e);
        }

        if (str_contains($lower, 'repository not found')) {
            return new \RuntimeException(
                "Could not clone {$masked}: repository not found, or the saved token cannot see it.",
                0,
                $e
            );
        }

        if (str_contains($lower, 'could not find remote ref')
            || str_contains($lower, "couldn't find remote ref")
        ) {
            return new \RuntimeException(
                "Could not clone {$masked}: the selected branch does not exist on the remote.",
                0,
                $e
            );
        }

        return $e instanceof \RuntimeException
            ? $e
            : new \RuntimeException($raw, 0, $e);
    }

    private function isGitAuthenticationFailure(string $lowerMessage): bool
    {
        foreach ([
            'could not read username',
            'could not read password',
            'terminal prompts disabled',
            'authentication failed',
            'invalid username or password',
            'access denied',
            'http 401',
            'http 403',
            'returned error: 401',
            'returned error: 403',
        ] as $needle) {
            if (str_contains($lowerMessage, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{output: string, previous_path: ?string, activated: bool}
     */
    private function syncToHost(
        SSHService $ssh,
        Service $service,
        string $hostAppPath,
        string $repoUrl,
        string $branch,
        ?ContainerDeployment $deployment = null,
        ?int $pullId = null,
    ): array {
        $credentials = app(ContainerGitCredentialsService::class);
        [$cleanUrl] = $credentials->stripUrlCredentials($repoUrl);
        $pathArg = escapeshellarg($hostAppPath);
        $repoArg = escapeshellarg($cleanUrl);
        $branchArg = escapeshellarg($branch);
        $askPassPath = null;
        $authEnv = 'GIT_TERMINAL_PROMPT=0';

        if ($askPass = $credentials->gitAskPassScript($service)) {
            $askPassPath = '/tmp/talksasa-git-askpass-'.Str::uuid().'.sh';
            $ssh->upload($askPass, $askPassPath);
            $ssh->exec('chmod 0700 '.escapeshellarg($askPassPath), 10);
            $authEnv .= ' GIT_ASKPASS='.escapeshellarg($askPassPath);
        }

        try {
            $suffix = $pullId !== null ? (string) $pullId : Str::lower(Str::random(12));
            $stagePath = $hostAppPath.'.talksasa-stage-'.$suffix;
            $previousPath = $hostAppPath.'.talksasa-previous-'.$suffix;
            $stageArg = escapeshellarg($stagePath);

            $this->deleteRemotePath($ssh, $stagePath);
            $this->deleteRemotePath($ssh, $previousPath);

            // Every pull is prepared outside the live bind mount. This removes
            // stale untracked files and makes rollback possible for normal pulls too.
            try {
                $output = trim($ssh->exec(
                    'sh -lc '.escapeshellarg(
                        'set -e; '
                        ."{$authEnv} git clone --depth=1 --branch {$branchArg} {$repoArg} {$stageArg} 2>&1; "
                        ."cd {$stageArg}; git remote set-url origin {$repoArg}"
                    ),
                    180
                ));
            } catch (\Throwable $e) {
                throw $this->rewriteGitCloneException($e, $service, $cleanUrl);
            }

            $hadPreviousContent = trim($ssh->exec(
                "find {$pathArg} -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null",
                10
            )) !== '';
            if ($pullId !== null) {
                $this->recordReplacementState($pullId, [
                    'host_app_path' => $hostAppPath,
                    'stage_path' => $stagePath,
                    'previous_path' => $previousPath,
                    'had_previous_content' => $hadPreviousContent,
                    'activated' => false,
                ]);
            }

            if ($deployment !== null && $deployment->status === 'running') {
                $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
                $this->stackCommands->stopApplicationContainerForMaintenance(
                    $ssh,
                    $containerPath,
                    $deployment->container_name
                );
            }

            try {
                $this->activateStagedClone($ssh, $hostAppPath, $stagePath, $previousPath);
            } catch (\Throwable $e) {
                if ($deployment !== null) {
                    try {
                        $this->refreshApplicationRuntime($ssh, $service, $deployment);
                    } catch (\Throwable) {
                    }
                }

                throw $e;
            }
            if ($pullId !== null) {
                $this->recordReplacementState($pullId, [
                    'host_app_path' => $hostAppPath,
                    'stage_path' => $stagePath,
                    'previous_path' => $previousPath,
                    'had_previous_content' => $hadPreviousContent,
                    'activated' => true,
                ]);
            }
            if (! $hadPreviousContent) {
                $this->deleteRemotePathQuietly($ssh, $previousPath);
            }

            return [
                'output' => $output,
                'previous_path' => $hadPreviousContent ? $previousPath : null,
                'activated' => true,
            ];
        } finally {
            if ($askPassPath !== null) {
                try {
                    $ssh->exec('rm -f -- '.escapeshellarg($askPassPath), 10);
                } catch (\Throwable) {
                    // The helper is also removed by node tmp cleanup; never mask the Git result.
                }
            }
        }
    }

    private function activateStagedClone(
        SSHService $ssh,
        string $hostAppPath,
        string $stagePath,
        string $previousPath,
    ): void {
        $app = escapeshellarg($hostAppPath);
        $stage = escapeshellarg($stagePath);
        $previous = escapeshellarg($previousPath);
        $script = 'set -e; '
            ."mkdir -p {$app} {$previous}; "
            .$this->backupEnvFilesScript($app)
            ."cp -a --reflink=auto {$app}/. {$previous}/; "
            ."touch {$previous}/.talksasa-backup-ready; "
            ."find {$app} -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; "
            ."find {$stage} -mindepth 1 -maxdepth 1 -exec mv -t {$app} -- {} +; "
            ."rmdir {$stage}; "
            .$this->restoreEnvFilesFromPreviousScript($app, $previous)
            .$this->restorePersistentDataScript($app, $previous);

        try {
            $ssh->exec('sh -lc '.escapeshellarg($script), 180);
        } catch (\Throwable $e) {
            // If activation itself was interrupted, put the previous tree back now.
            try {
                $this->restorePreviousFiles($ssh, $hostAppPath, $previousPath);
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    private function restorePreviousApplication(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        string $hostAppPath,
        string $previousPath,
    ): void {
        $this->restorePreviousFiles($ssh, $hostAppPath, $previousPath);

        $this->refreshApplicationRuntime($ssh, $service, $deployment);
    }

    private function refreshApplicationRuntime(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
    ): void {
        if ($this->isLaravelService($service)) {
            $this->deploymentService->refreshLaravelServeCompose($service, $deployment, $ssh);

            return;
        }

        $this->deploymentService->refreshApplicationRuntimeCompose($service, $deployment, $ssh);
    }

    private function restorePreviousFiles(SSHService $ssh, string $hostAppPath, string $previousPath): void
    {
        $app = escapeshellarg($hostAppPath);
        $previous = escapeshellarg($previousPath);
        $script = 'set -e; '
            ."[ -f {$previous}/.talksasa-backup-ready ] || exit 0; "
            ."rm -f {$previous}/.talksasa-backup-ready; "
            ."mkdir -p {$app}; "
            ."find {$app} -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; "
            ."find {$previous} -mindepth 1 -maxdepth 1 -exec mv -t {$app} -- {} +; "
            ."rmdir {$previous}";

        $ssh->exec('sh -lc '.escapeshellarg($script), 180);
    }

    private function deleteRemotePath(SSHService $ssh, string $path): void
    {
        $ssh->exec('rm -rf -- '.escapeshellarg($path), 180);
    }

    private function deleteRemotePathQuietly(SSHService $ssh, string $path): void
    {
        try {
            $this->deleteRemotePath($ssh, $path);
        } catch (\Throwable) {
            // Cleanup is retried on the next staged pull; never fail a healthy release for it.
        }
    }

    /**
     * Persist enough non-secret state for the queue failed() hook to recover
     * after a hard timeout or worker termination.
     *
     * @param  array<string, mixed>  $state
     */
    private function recordReplacementState(int $pullId, array $state): void
    {
        $pull = ContainerGitPull::find($pullId);
        if (! $pull) {
            return;
        }

        $options = is_array($pull->options) ? $pull->options : [];
        $options['replacement_state'] = $state;
        $pull->update(['options' => $options]);
    }

    public function clearReplacementState(ContainerGitPull $pull): void
    {
        $pull->refresh();
        $options = is_array($pull->options) ? $pull->options : [];
        unset($options['replacement_state']);
        $pull->update(['options' => $options]);
    }

    public function recoverInterruptedPull(ContainerGitPull $pull): void
    {
        $pull->refresh();
        $pull->loadMissing('service.product.containerTemplate', 'service.containerDeployment.node');
        $service = $pull->service;
        $deployment = $service?->containerDeployment;
        $state = is_array($pull->options) ? ($pull->options['replacement_state'] ?? null) : null;

        if (! $service || ! $deployment?->node || ! is_array($state)) {
            return;
        }

        $hostAppPath = $state['host_app_path'] ?? null;
        $previousPath = $state['previous_path'] ?? null;
        if (! is_string($hostAppPath) || ! is_string($previousPath)) {
            return;
        }

        $ssh = SSHService::forNode($deployment->node);
        try {
            if (! empty($state['had_previous_content'])) {
                $this->restorePreviousApplication($ssh, $service, $deployment, $hostAppPath, $previousPath);
            } else {
                $this->refreshApplicationRuntime($ssh, $service, $deployment);
            }

            if (is_string($state['stage_path'] ?? null)) {
                $this->deleteRemotePathQuietly($ssh, $state['stage_path']);
            }
            $this->clearReplacementState($pull);
        } finally {
            $ssh->disconnect();
        }
    }

    private function backupEnvFilesScript(string $pathArg): string
    {
        return 'find '.$pathArg.' -maxdepth 4 -name .env -type f 2>/dev/null | while IFS= read -r f; do cp "$f" "$f.talksasa-backup"; done; ';
    }

    private function restoreEnvFilesFromPreviousScript(string $pathArg, string $previousArg): string
    {
        return 'find '.$previousArg.' -maxdepth 4 -name .env.talksasa-backup -type f 2>/dev/null '
            .'| while IFS= read -r f; do rel="${f#'.$previousArg.'/}"; target='.$pathArg.'/"${rel%.talksasa-backup}"; '
            .'mkdir -p "$(dirname "$target")"; cp "$f" "$target"; done; ';
    }

    private function restorePersistentDataScript(string $pathArg, string $previousArg): string
    {
        // Preserve conventional customer-upload/runtime directories while
        // intentionally rebuilding vendor, node_modules and compiled assets.
        return 'for rel in storage uploads public/uploads media data; do '
            .'source='.$previousArg.'/"$rel"; target='.$pathArg.'/"$rel"; '
            .'if [ -e "$source" ]; then rm -rf -- "$target"; mkdir -p "$(dirname "$target")"; '
            .'cp -a --reflink=auto "$source" "$target"; fi; done; ';
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
        return ($this->templateSlugFor($service) ?? '') === 'laravel';
    }

    private function pathResolver(): LaravelProjectPathResolver
    {
        return app(LaravelProjectPathResolver::class);
    }
}
