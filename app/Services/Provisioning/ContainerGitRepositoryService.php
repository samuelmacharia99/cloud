<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerGitRepositoryService
{
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

    public function connect(Service $service, string $repoUrl, string $branch): void
    {
        $repoUrl = $this->normalizeRepositoryUrl($repoUrl);
        $branch = $this->normalizeBranch($branch);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['source_repo_url'] = $repoUrl;
        $meta['source_repo_branch'] = $branch;
        $meta['source_repo_connected_at'] = now()->toIso8601String();

        $service->update(['service_meta' => $meta]);
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
        $settings = $this->repositorySettings($service);
        if ($settings['url'] === '') {
            throw new \InvalidArgumentException('Connect a Git repository before pulling code.');
        }

        $ssh = SSHService::forNode($deployment->node);
        try {
            $hostAppPath = $this->appDirectory->hostAppPath($deployment);
            $hasGit = $this->hasGitCheckout($ssh, $hostAppPath);

            if (! $hasGit && ! $replaceExisting) {
                throw new \InvalidArgumentException(
                    'No Git checkout exists in /app yet. Enable "Replace /app contents" to clone this repository for the first time.'
                );
            }

            $this->appDirectory->reclaimHostAppOwnershipForGit($ssh, $deployment);
            $this->syncToHost($ssh, $hostAppPath, $settings['url'], $settings['branch'], ! $hasGit);

            $commit = $this->readShortCommit($ssh, $hostAppPath);
            $messages = ['Repository synced to /app'.($commit ? " ({$commit})" : '').'.'];

            if ($this->isLaravelService($service) && $this->appDirectory->hasLaravelProject($ssh, $deployment)) {
                $messages[] = $this->laravelInitialization->configureApplicationEnvironment($service, $deployment, $ssh);
            }

            if ($this->isLaravelService($service)) {
                $messages = array_merge(
                    $messages,
                    $this->runLaravelPostPullSteps($service, $deployment, $ssh, $runComposer, $runMigrations)
                );
            } else {
                $messages = array_merge($messages, $this->stackCommands->runPostPullSteps($service, $deployment, $ssh));
            }

            $this->appDirectory->normalizePermissions($ssh, $deployment);

            if (! $this->isLaravelService($service)) {
                $runtimeMessage = $this->deploymentService->refreshApplicationRuntimeCompose($service, $deployment, $ssh);
                if ($runtimeMessage !== '') {
                    $messages[] = $runtimeMessage;
                }
            }

            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['source_repo_synced_at'] = now()->toIso8601String();
            $service->update(['service_meta' => $meta]);

            return [
                'message' => implode(' ', $messages),
                'commit' => $commit,
            ];
        } finally {
            $ssh->disconnect();
        }
    }

    public function syncForDeploy(SSHService $ssh, Service $service, string $hostAppPath): void
    {
        $settings = $this->repositorySettings($service);
        $ssh->mkdirp($hostAppPath);

        if ($settings['url'] === '') {
            $this->appDirectory->ensurePlaceholderState($ssh, $hostAppPath);

            return;
        }

        try {
            $hasGit = $this->hasGitCheckout($ssh, $hostAppPath);
            $this->appDirectory->reclaimHostPathOwnershipForGit($ssh, $hostAppPath);
            $this->syncToHost($ssh, $hostAppPath, $settings['url'], $settings['branch'], ! $hasGit);

            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['source_repo_synced_at'] = now()->toIso8601String();
            $service->update(['service_meta' => $meta]);
        } catch (\Throwable $e) {
            \Log::warning("Failed to sync application source for service {$service->id}", [
                'service_id' => $service->id,
                'branch' => $settings['branch'],
                'error' => $e->getMessage(),
            ]);
            $this->appDirectory->ensurePlaceholderState($ssh, $hostAppPath);
        }
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

    private function syncToHost(
        SSHService $ssh,
        string $hostAppPath,
        string $repoUrl,
        string $branch,
        bool $freshClone
    ): void {
        $pathArg = escapeshellarg($hostAppPath);
        $repoArg = escapeshellarg($repoUrl);
        $branchArg = escapeshellarg($branch);
        $git = $this->gitInvocation($hostAppPath);

        if ($freshClone) {
            $script = 'set -e; '
                ."rm -rf {$pathArg}; "
                ."{$git} clone --depth=1 --branch {$branchArg} {$repoArg} {$pathArg}";
        } else {
            $script = 'set -e; '
                ."cd {$pathArg}; "
                ."{$git} fetch --depth=1 origin {$branchArg}; "
                ."{$git} checkout -f {$branchArg}; "
                ."{$git} reset --hard FETCH_HEAD";
        }

        $ssh->exec('sh -lc '.escapeshellarg($script), 180);
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

    /**
     * @return list<string>
     */
    private function runLaravelPostPullSteps(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        bool $runComposer,
        bool $runMigrations
    ): array {
        $messages = [];
        $timeout = (int) config('containers.laravel_init.command_timeout_seconds', 600);

        if ($runComposer && $this->appDirectory->hasLaravelProject($ssh, $deployment)) {
            try {
                $this->laravelInitialization->runComposerInstall($ssh, $deployment, $timeout);
                $messages[] = 'Composer dependencies updated.';
            } catch (\Throwable $e) {
                $messages[] = 'Composer install failed: '.$e->getMessage();
            }
        }

        if ($runMigrations && $this->appDirectory->hasLaravelProject($ssh, $deployment)) {
            try {
                $this->laravelInitialization->runApplicationMigrations($service, $ssh, $deployment, $timeout);
                $messages[] = 'Database migrations applied.';
            } catch (\Throwable $e) {
                $messages[] = 'Migrations could not run: '.$e->getMessage();
            }
        }

        return $messages;
    }
}
