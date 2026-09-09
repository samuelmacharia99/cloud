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
    ): array {
        if ($operationAlreadyLocked) {
            return $this->buildUnlocked($service, $deployment, $ssh, $forceRebuild);
        }

        return Cache::lock($this->lockName($service), $this->lockSeconds())
            ->block(10, fn () => $this->buildUnlocked($service, $deployment, $ssh, $forceRebuild));
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
                app(ContainerDeploymentService::class)->waitForNodeApplicationReadiness(
                    $ssh,
                    $deployment,
                    (int) config('containers.node_build.readiness_timeout_seconds', 120),
                );
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
    ): array {
        $service->loadMissing('product.containerTemplate');
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'nodejs') {
            throw new \DomainException('The Node build pipeline only supports Node.js services.');
        }

        $hostAppPath = $this->appDirectory->hostAppPath($deployment);
        $packageJsonPath = $hostAppPath.'/package.json';
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
            $messages = $this->stackCommands->buildNodeApplication(
                $service,
                $deployment,
                $ssh,
                $forceRebuild,
            );
            $runtime = $this->runtimeService->detectNodeRuntime(
                $ssh,
                $hostAppPath,
                (int) ($service->effectiveContainerTemplate()?->default_port ?? 3000),
                includeBootstrap: false,
            );
            $relativeRoot = $this->runtimeService->relativeDirUnderApp($runtime->containerWorkdir);
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
                'node_version' => $deployment->selected_version,
                'node_version_source' => $serviceMeta['node_version_source'] ?? 'auto',
                'node_engine' => $serviceMeta['node_detected_engine'] ?? null,
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
