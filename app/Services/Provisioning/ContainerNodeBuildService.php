<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Cache;

class ContainerNodeBuildService
{
    public const MANIFEST_RELATIVE_PATH = '.talksasa/release.json';

    public function __construct(
        private readonly ContainerStackCommandService $stackCommands,
        private readonly ContainerApplicationRuntimeService $runtimeService,
        private readonly ContainerAppDirectoryService $appDirectory,
    ) {}

    /**
     * @return array{state: string, messages: list<string>, manifest: array<string, mixed>}
     */
    public function build(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        bool $forceRebuild = false,
        bool $operationAlreadyLocked = false,
        ?array $workloads = null,
    ): array {
        if ($operationAlreadyLocked) {
            return $this->buildUnlocked($service, $deployment, $ssh, $forceRebuild, $workloads);
        }

        return Cache::lock($this->lockName($service), $this->lockSeconds())
            ->block(10, fn () => $this->buildUnlocked($service, $deployment, $ssh, $forceRebuild, $workloads));
    }

    public function lockName(Service $service): string
    {
        return 'container-operation:service:'.$service->getKey();
    }

    /**
     * @return array{state: string, messages: list<string>, manifest: array<string, mixed>}
     */
    public function rebuildAndStart(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
    ): array {
        return Cache::lock($this->lockName($service), $this->lockSeconds())
            ->block(10, function () use ($service, $deployment, $ssh): array {
                $result = $this->buildUnlocked($service, $deployment, $ssh, true);
                app(ContainerDeploymentService::class)->refreshApplicationRuntimeCompose(
                    $service,
                    $deployment,
                    $ssh,
                );
                $deployments = app(ContainerDeploymentService::class);
                $timeout = (int) config('containers.node_build.readiness_timeout_seconds', 120);
                $service->refresh();
                if (data_get($service->service_meta, 'node_workloads.topology') === 'split_web_api') {
                    $deployments->waitForNodeSplitStackReadiness($ssh, $deployment, $timeout);
                } else {
                    $deployments->waitForNodeApplicationReadiness($ssh, $deployment, $timeout);
                }
                $this->persistState($service, 'healthy', $result['manifest']);
                $this->record($service, $deployment, 'node_release_ready', $result['manifest']);

                return $result;
            });
    }

    public function markHealthy(Service $service, ContainerDeployment $deployment): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $manifest = is_array($meta['node_release'] ?? null) ? $meta['node_release'] : [];
        $this->persistState($service, 'healthy', $manifest);
        $this->record($service, $deployment, 'node_release_ready', $manifest);
    }

    private function lockSeconds(): int
    {
        return max(300, (int) config('containers.node_build.operation_lock_seconds', 1800));
    }

    /**
     * @return array{state: string, messages: list<string>, manifest: array<string, mixed>}
     */
    private function buildUnlocked(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        bool $forceRebuild,
        ?array $workloads = null,
    ): array {
        $service->loadMissing('product.containerTemplate');
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'nodejs') {
            throw new \DomainException('The Node build pipeline only supports Node.js services.');
        }

        $hostAppPath = $this->appDirectory->hostAppPath($deployment);
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $topology = app(ContainerNodeWorkloadTopologyService::class)->resolve(
            $service,
            $ssh,
            $hostAppPath,
            is_string($meta['node_backend_root'] ?? null) ? $meta['node_backend_root'] : null,
            is_string($meta['node_frontend_root'] ?? null) ? $meta['node_frontend_root'] : null,
        );
        if (($topology['topology'] ?? 'single') === 'split_web_api') {
            return $this->buildSplitWorkloads(
                $service,
                $deployment,
                $ssh,
                $hostAppPath,
                $topology,
                $forceRebuild,
                $workloads,
            );
        }

        $pinnedRoot = is_string(data_get($topology, 'backend.root'))
            ? (string) data_get($topology, 'backend.root')
            : '';
        $packageJsonPath = $pinnedRoot !== ''
            ? $hostAppPath.'/'.$pinnedRoot.'/package.json'
            : $hostAppPath.'/package.json';
        if (trim($ssh->exec('test -f '.escapeshellarg($packageJsonPath).' && echo yes || echo no', 10)) !== 'yes') {
            return [
                'state' => 'placeholder',
                'messages' => ['No package.json found; placeholder runtime retained.'],
                'manifest' => [],
            ];
        }

        $this->record($service, $deployment, 'node_build_started', [
            'force_rebuild' => $forceRebuild,
        ]);
        $this->persistState($service, 'building');

        try {
            $runtime = $pinnedRoot !== ''
                ? $this->runtimeService->detectNodeRuntimeAt(
                    $ssh,
                    $hostAppPath,
                    $pinnedRoot,
                    (int) ($service->effectiveContainerTemplate()?->default_port ?? 3000),
                    includeBootstrap: false,
                )
                : $this->runtimeService->detectNodeRuntime(
                    $ssh,
                    $hostAppPath,
                    (int) ($service->effectiveContainerTemplate()?->default_port ?? 3000),
                    includeBootstrap: false,
                );
            $relativeRoot = $this->runtimeService->relativeDirUnderApp($runtime->containerWorkdir);
            $messages = $this->stackCommands->buildNodeApplication(
                $service,
                $deployment,
                $ssh,
                $forceRebuild,
                $relativeRoot,
            );
            $projectHostPath = $relativeRoot === '' ? $hostAppPath : $hostAppPath.'/'.$relativeRoot;
            $projectPackageJson = $this->remoteValue(
                $ssh,
                'head -c 65536 '.escapeshellarg($projectHostPath.'/package.json').' 2>/dev/null || true'
            );
            $workspacePackageJson = $relativeRoot !== ''
                ? $this->remoteValue(
                    $ssh,
                    'head -c 65536 '.escapeshellarg($hostAppPath.'/package.json').' 2>/dev/null || true'
                )
                : null;
            $requiresProductionArtifact = $this->runtimeService->packageJsonRequiresProductionBuild($projectPackageJson)
                || $this->runtimeService->packageJsonRequiresProductionBuild($workspacePackageJson);
            $artifactMissingCheck = null;
            $hasTypeScriptNextConfig = trim($ssh->exec(
                'test -f '.escapeshellarg($projectHostPath.'/next.config.ts').' && echo yes || echo no',
                10,
            )) === 'yes';
            if ($hasTypeScriptNextConfig) {
                $template = $service->effectiveContainerTemplate();
                $dockerImage = app(ContainerDeploymentService::class)->resolveTemplateDockerImage(
                    $template,
                    $deployment->selected_version,
                );
                try {
                    $this->stackCommands->runUnlimitedMemoryNodeCommand(
                        $ssh,
                        $dockerImage,
                        $hostAppPath,
                        'node -e \'require.resolve("typescript")\'',
                        $runtime->containerWorkdir,
                        30,
                    );
                } catch (\Throwable $e) {
                    throw new \RuntimeException(
                        'next.config.ts requires TypeScript at runtime, but TypeScript is not installed in the '
                        .'validated release. Add typescript to this app workspace devDependencies, update the '
                        .'repository lockfile, and rebuild.',
                        0,
                        $e,
                    );
                }
            }
            if ($requiresProductionArtifact) {
                $artifactMissingCheck = $this->runtimeService->packageJsonBuildArtifactMissingCheck(
                    $projectPackageJson,
                    $relativeRoot,
                );
                $ssh->exec(
                    'cd '.escapeshellarg($hostAppPath)
                        .' && if '.$artifactMissingCheck
                        .'; then echo '.escapeshellarg(
                            'Node production artifact validation failed for '
                            .($relativeRoot !== '' ? $relativeRoot : '/app').'.'
                        )
                        .' >&2; exit 78; fi',
                    30,
                );
            }
            $service->refresh();
            $serviceMeta = is_array($service->service_meta) ? $service->service_meta : [];
            $manifest = [
                'schema' => 1,
                'state' => 'built',
                'built_at' => now()->toIso8601String(),
                'source_revision' => $this->remoteValue(
                    $ssh,
                    'git -C '.escapeshellarg($hostAppPath).' rev-parse HEAD 2>/dev/null || true'
                ),
                'dependency_checksum' => $this->remoteValue(
                    $ssh,
                    'cd '.escapeshellarg($hostAppPath)
                        .' && { sha256sum package.json package-lock.json pnpm-lock.yaml yarn.lock 2>/dev/null || true; }'
                        ." | sha256sum | cut -d ' ' -f1"
                ),
                'image' => $this->remoteValue(
                    $ssh,
                    'cd '.escapeshellarg(ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name)
                        .' && { docker compose -f docker-compose.yml config --images 2>/dev/null | head -n 1 || true; }'
                ),
                'runtime_source' => $runtime->source,
                'runtime_label' => $runtime->label,
                'package_manager' => $this->runtimeService->resolveNodePackageManager(
                    $projectPackageJson,
                    $workspacePackageJson,
                ),
                'malformed_package_manager' => $this->runtimeService
                    ->malformedNodePackageManagerFromPackageJson($workspacePackageJson)
                    ?? $this->runtimeService->malformedNodePackageManagerFromPackageJson($projectPackageJson),
                'node_version' => $deployment->selected_version,
                'node_version_source' => $serviceMeta['node_version_source'] ?? 'auto',
                'node_engine' => $serviceMeta['node_detected_engine'] ?? null,
                'artifact_check' => $artifactMissingCheck,
                'artifact_root' => $relativeRoot,
                'typescript_config_validated' => $hasTypeScriptNextConfig,
                'working_directory' => $runtime->containerWorkdir,
                'start_command' => $runtime->command,
            ];

            $this->writeManifest($ssh, $hostAppPath, $manifest);
            $this->persistState($service, 'built', $manifest);
            $this->record($service, $deployment, 'node_build_succeeded', $manifest);

            return [
                'state' => 'built',
                'messages' => $messages,
                'manifest' => $manifest,
            ];
        } catch (\Throwable $e) {
            $this->persistState($service, 'build_failed', ['error' => $e->getMessage()]);
            $this->record($service, $deployment, 'node_build_failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $topology
     * @return array{state: string, messages: list<string>, manifest: array<string, mixed>}
     */
    private function buildSplitWorkloads(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        string $hostAppPath,
        array $topology,
        bool $forceRebuild,
        ?array $selectedWorkloads = null,
    ): array {
        $this->record($service, $deployment, 'node_split_build_started', [
            'force_rebuild' => $forceRebuild,
            'backend_root' => data_get($topology, 'backend.root'),
            'frontend_root' => data_get($topology, 'frontend.root'),
        ]);
        $this->persistState($service, 'building', ['topology' => 'split_web_api']);
        $activeRole = null;

        try {
            $rootPackageJson = $this->remoteValue(
                $ssh,
                'head -c 131072 '.escapeshellarg($hostAppPath.'/package.json').' 2>/dev/null || true',
            );
            $messages = [];
            $workloads = [];
            $nodeVersion = app(ContainerNodeVersionService::class);
            $selectedMajor = $nodeVersion->majorFromVersion($deployment->selected_version);
            $selectedWorkloads = $selectedWorkloads === null
                ? ['backend', 'frontend']
                : array_values(array_intersect(['backend', 'frontend'], $selectedWorkloads));

            foreach (['backend', 'frontend'] as $role) {
                $root = (string) data_get($topology, $role.'.root');
                $packageJson = $this->remoteValue(
                    $ssh,
                    'head -c 131072 '.escapeshellarg($hostAppPath.'/'.$root.'/package.json').' 2>/dev/null || true',
                );
                if ($packageJson === null) {
                    throw new \RuntimeException("The {$role} package.json disappeared from {$root} during build.");
                }
                $constraint = $nodeVersion->constraintFromPackageJson($packageJson);
                if ($constraint !== null && $selectedMajor !== null && ! $nodeVersion->majorSatisfies($selectedMajor, $constraint)) {
                    throw new \DomainException(
                        ucfirst($role)." requires Node {$constraint}, but the selected runtime is {$deployment->selected_version}."
                    );
                }

                if (in_array($role, $selectedWorkloads, true)) {
                    $activeRole = $role;
                    $this->record($service, $deployment, 'node_workload_build_started', [
                        'workload' => $role,
                        'root' => $root,
                        'force_rebuild' => $forceRebuild,
                    ]);
                    $roleMessages = $this->stackCommands->buildNodeApplication(
                        $service,
                        $deployment,
                        $ssh,
                        $forceRebuild,
                        $root,
                    );
                    array_push($messages, ...array_map(
                        fn (string $message): string => ucfirst($role).': '.$message,
                        $roleMessages,
                    ));
                    $this->record($service, $deployment, 'node_workload_build_succeeded', [
                        'workload' => $role,
                        'root' => $root,
                    ]);
                    $activeRole = null;
                }

                $runtime = $this->runtimeService->detectNodeRuntimeAt(
                    $ssh,
                    $hostAppPath,
                    $root,
                    (int) data_get($topology, $role.'.port'),
                    includeBootstrap: false,
                );
                $artifactCheck = null;
                $artifactPath = null;
                $artifactChecksum = null;
                if ($this->runtimeService->packageJsonRequiresProductionBuild($packageJson)) {
                    $artifactCheck = $this->runtimeService->packageJsonBuildArtifactMissingCheck($packageJson, $root);
                    $ssh->exec(
                        'cd '.escapeshellarg($hostAppPath).' && if '.$artifactCheck
                        .'; then echo '.escapeshellarg(ucfirst($role).' production artifact is missing after build.')
                        .' >&2; exit 78; fi',
                        30,
                    );
                    $artifactPath = $root.'/'.$this->runtimeService->packageJsonBuildOutputDir($packageJson);
                    $artifactChecksum = $this->remoteValue(
                        $ssh,
                        'cd '.escapeshellarg($hostAppPath).' && target='.escapeshellarg($artifactPath).'; '
                        .'if [ -f "$target" ]; then sha256sum "$target" | awk \'{print $1}\'; '
                        .'elif [ -d "$target" ]; then find "$target" -type f -print0 | sort -z '
                        .'| xargs -0 -r sha256sum | sha256sum | awk \'{print $1}\'; fi',
                    );
                }

                $workloads[$role] = [
                    'root' => $root,
                    'port' => (int) data_get($topology, $role.'.port'),
                    'runtime_source' => $runtime->source,
                    'runtime_label' => $runtime->label,
                    'working_directory' => $runtime->containerWorkdir,
                    'start_command' => $runtime->command,
                    'package_manager' => $this->runtimeService->resolveNodePackageManager($packageJson, $rootPackageJson),
                    'node_engine' => $constraint,
                    'artifact_check' => $artifactCheck,
                    'artifact_path' => $artifactPath,
                    'artifact_checksum' => $artifactChecksum,
                    'package_checksum' => hash('sha256', $packageJson),
                ];
            }

            $manifest = [
                'schema' => 2,
                'state' => 'built',
                'topology' => 'split_web_api',
                'built_at' => now()->toIso8601String(),
                'source_revision' => $this->remoteValue(
                    $ssh,
                    'git -C '.escapeshellarg($hostAppPath).' rev-parse HEAD 2>/dev/null || true',
                ),
                'node_version' => $deployment->selected_version,
                'built_workloads' => $selectedWorkloads,
                'frontend_env_checksum' => $this->frontendBuildEnvironmentChecksum($deployment),
                'workloads' => $workloads,
            ];
            $topology['backend'] = array_merge($topology['backend'], $workloads['backend']);
            $topology['frontend'] = array_merge($topology['frontend'], $workloads['frontend']);
            app(ContainerNodeWorkloadTopologyService::class)->persist($service, $topology);
            $this->writeManifest($ssh, $hostAppPath, $manifest);
            $this->persistState($service, 'built', $manifest);
            $this->record($service, $deployment, 'node_split_build_succeeded', $manifest);

            return ['state' => 'built', 'messages' => $messages, 'manifest' => $manifest];
        } catch (\Throwable $e) {
            $this->persistState($service, 'build_failed', [
                'topology' => 'split_web_api',
                'error' => $e->getMessage(),
            ]);
            $this->record($service, $deployment, 'node_split_build_failed', [
                'error' => $e->getMessage(),
                'workload' => $activeRole,
            ]);
            if ($activeRole !== null) {
                $this->record($service, $deployment, 'node_workload_build_failed', [
                    'workload' => $activeRole,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    public function frontendBuildEnvironmentChecksum(ContainerDeployment $deployment): string
    {
        $values = [];
        foreach (is_array($deployment->env_values) ? $deployment->env_values : [] as $key => $value) {
            if (is_string($key) && (str_starts_with($key, 'NEXT_PUBLIC_') || str_starts_with($key, 'VITE_'))) {
                $values[$key] = is_scalar($value) || $value === null ? (string) $value : '';
            }
        }
        ksort($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(SSHService $ssh, string $hostAppPath, array $manifest): void
    {
        $directory = $hostAppPath.'/.talksasa';
        $target = $hostAppPath.'/'.self::MANIFEST_RELATIVE_PATH;
        $temporary = $target.'.tmp-'.bin2hex(random_bytes(6));
        $ssh->mkdirp($directory);
        $ssh->upload(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n", $temporary);
        $ssh->exec('mv '.escapeshellarg($temporary).' '.escapeshellarg($target), 15);
    }

    private function remoteValue(SSHService $ssh, string $command): ?string
    {
        $value = trim($ssh->exec($command, 20));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function persistState(Service $service, string $state, array $details = []): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['node_release'] = array_merge($details, [
            'state' => $state,
            'updated_at' => now()->toIso8601String(),
        ]);
        $service->update(['service_meta' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        Service $service,
        ContainerDeployment $deployment,
        string $event,
        array $payload = [],
    ): void {
        ContainerDeploymentEvent::create([
            'service_id' => $service->id,
            'container_deployment_id' => $deployment->id,
            'event' => $event,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }
}
