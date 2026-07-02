<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\ContainerTemplate;
use App\Models\Service;
use App\Services\SSH\SSHService;

class RuntimeImageProvisioner
{
    public function usesRuntimeImage(ContainerTemplate $template): bool
    {
        return array_key_exists($template->slug ?? '', config('containers.runtime_templates', []));
    }

    /**
     * @return array{image: string, runtime: string, tag: string, php_version: string}
     */
    public function resolveImageReference(ContainerTemplate $template, ?string $selectedVersion = null): array
    {
        $runtimeConfig = config('containers.runtime_templates.'.$template->slug);
        $runtime = (string) ($runtimeConfig['runtime'] ?? $template->slug);
        $phpVersion = $this->normalizePhpTag($selectedVersion, (string) ($runtimeConfig['default_tag'] ?? '8.3'));
        $tag = $phpVersion;
        $revision = (int) config('containers.runtime_build_revision', 0);
        if ($revision > 0) {
            $tag .= '-r'.$revision;
        }

        $registry = trim((string) config('containers.runtime_registry', 'talksasa'), '/');
        $image = "{$registry}/{$runtime}-runtime:{$tag}";

        return [
            'image' => $image,
            'runtime' => $runtime,
            'tag' => $tag,
            'php_version' => $phpVersion,
        ];
    }

    public function ensureImage(
        SSHService $ssh,
        ContainerTemplate $template,
        ?string $selectedVersion,
        Service $service,
        ?ContainerDeployment $deployment = null
    ): string {
        if (! $this->usesRuntimeImage($template)) {
            return (string) $template->docker_image;
        }

        $reference = $this->resolveImageReference($template, $selectedVersion);
        $image = $reference['image'];

        if ($this->imageExistsOnNode($ssh, $image)) {
            return $image;
        }

        if (! config('containers.runtime_build_on_deploy', true)) {
            throw new \RuntimeException(
                "Runtime image {$image} is not present on the container node. Build it manually or enable CONTAINER_RUNTIME_BUILD_ON_DEPLOY."
            );
        }

        $this->recordEvent($service, $deployment, 'runtime_image_build_started', $reference);

        try {
            $this->buildImageOnNode($ssh, $reference);
            $this->recordEvent($service, $deployment, 'runtime_image_build_succeeded', $reference);
        } catch (\Throwable $e) {
            $this->recordEvent($service, $deployment, 'runtime_image_build_failed', [
                ...$reference,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $image;
    }

    private function imageExistsOnNode(SSHService $ssh, string $image): bool
    {
        try {
            $ssh->exec('docker image inspect '.escapeshellarg($image), 30);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array{image: string, runtime: string, tag: string, php_version: string}  $reference
     */
    private function buildImageOnNode(SSHService $ssh, array $reference): void
    {
        $buildRoot = rtrim((string) config('containers.runtime_build_path', '/opt/talksasa/runtime-builds'), '/');
        $buildDir = "{$buildRoot}/{$reference['runtime']}/{$reference['tag']}";
        $dockerfilePath = base_path("deploy/docker/runtimes/{$reference['runtime']}/Dockerfile");
        $entrypointPath = base_path('deploy/docker/runtimes/common/entrypoint.sh');

        if (! is_file($dockerfilePath) || ! is_file($entrypointPath)) {
            throw new \RuntimeException("Runtime build assets missing for {$reference['runtime']}");
        }

        $ssh->mkdirp($buildDir);
        $ssh->upload(file_get_contents($dockerfilePath), "{$buildDir}/Dockerfile");
        $ssh->upload(file_get_contents($entrypointPath), "{$buildDir}/entrypoint.sh");

        $buildCmd = sprintf(
            'cd %s && docker build --pull --build-arg PHP_VERSION=%s -t %s .',
            escapeshellarg($buildDir),
            escapeshellarg($reference['php_version']),
            escapeshellarg($reference['image'])
        );

        $ssh->exec($buildCmd, 900);
    }

    private function normalizePhpTag(?string $selectedVersion, string $defaultTag): string
    {
        $candidate = trim((string) $selectedVersion);
        if ($candidate === '') {
            return $defaultTag;
        }

        if (preg_match('/^(\d+\.\d+)/', $candidate, $matches) === 1) {
            return $matches[1];
        }

        return $defaultTag;
    }

    private function recordEvent(Service $service, ?ContainerDeployment $deployment, string $event, array $payload): void
    {
        try {
            ContainerDeploymentEvent::create([
                'service_id' => $service->id,
                'container_deployment_id' => $deployment?->id,
                'event' => $event,
                'payload' => $payload,
                'recorded_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning("Failed to record runtime image event '{$event}'", [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
