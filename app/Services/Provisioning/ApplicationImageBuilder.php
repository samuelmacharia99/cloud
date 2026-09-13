<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Cache;

/**
 * Builds a catalog application's image on the node from a release tag.
 *
 * Some upstreams (Open Source POS) publish no stable image tag, only a moving
 * `master` and commit-suffixed builds. Pinning those would make every fresh
 * deploy a different build. The platform therefore keeps its own Dockerfile
 * under deploy/docker/apps/<slug>/ and builds `<registry>/<image>:<ref>` on the
 * node the first time that ref is deployed there, exactly as the Laravel and
 * PHP runtime images are built by RuntimeImageProvisioner.
 *
 * The selected version *is* the git ref, so the template's versions list is
 * the list of release tags the platform is prepared to build.
 */
class ApplicationImageBuilder
{
    public const BUILD_TIMEOUT_SECONDS = 1200;

    public const EVENT_STARTED = 'app_image_build_started';

    public const EVENT_SUCCEEDED = 'app_image_build_succeeded';

    public const EVENT_FAILED = 'app_image_build_failed';

    /**
     * @param  array<string, array{image?: string, repository?: string, default_ref?: string}>|null  $definitions  defaults to containers.app_images
     */
    public function __construct(
        private ?ContainerDeploymentEventRecorder $events = null,
        private ?array $definitions = null,
    ) {}

    public function usesApplicationImage(object $template): bool
    {
        return array_key_exists(strtolower((string) ($template->slug ?? '')), $this->definitions());
    }

    /**
     * @return array{image: string, name: string, ref: string, repository: string, slug: string}
     */
    public function resolveImageReference(object $template, ?string $selectedVersion = null): array
    {
        $slug = strtolower((string) ($template->slug ?? ''));
        $definition = $this->definitions()[$slug] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException("Template '{$slug}' has no application image definition.");
        }

        $ref = $this->normalizeRef($selectedVersion, (string) ($definition['default_ref'] ?? ''));
        $registry = trim((string) (app()->bound('config') ? config('containers.runtime_registry', 'talksasa') : 'talksasa'), '/');
        $name = trim((string) ($definition['image'] ?? $slug), '/');

        return [
            'image' => "{$registry}/{$name}:{$ref}",
            'name' => $name,
            'ref' => $ref,
            'repository' => (string) ($definition['repository'] ?? ''),
            'slug' => $slug,
        ];
    }

    /**
     * Make sure the image for this template and version exists on the node,
     * building it when it does not. Returns the image reference. A no-op that
     * returns the template's own image for templates that are not built here.
     */
    public function ensureImage(
        SSHService $ssh,
        object $template,
        ?string $selectedVersion,
        Service $service,
        ?ContainerDeployment $deployment = null,
    ): string {
        if (! $this->usesApplicationImage($template)) {
            return (string) ($template->docker_image ?? '');
        }

        $reference = $this->resolveImageReference($template, $selectedVersion);
        $image = $reference['image'];

        if ($this->imageExistsOnNode($ssh, $image)) {
            return $image;
        }

        if (! config('containers.runtime_build_on_deploy', true)) {
            throw new \RuntimeException(
                "Application image {$image} is not present on the container node. Build it manually or enable CONTAINER_RUNTIME_BUILD_ON_DEPLOY."
            );
        }

        // Two deploys of the same ref on one node must not race the build.
        $lock = Cache::lock($this->lockName($deployment?->node_id, $image), self::BUILD_TIMEOUT_SECONDS + 60);
        $lock->block(self::BUILD_TIMEOUT_SECONDS);

        try {
            if ($this->imageExistsOnNode($ssh, $image)) {
                return $image;
            }

            $this->record($service, $deployment, self::EVENT_STARTED, $reference);

            try {
                $this->buildImageOnNode($ssh, $reference);
                $this->record($service, $deployment, self::EVENT_SUCCEEDED, $reference);
            } catch (\Throwable $e) {
                $this->record($service, $deployment, self::EVENT_FAILED, [
                    ...$reference,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        } finally {
            $lock->release();
        }

        return $image;
    }

    /**
     * The docker build command the node runs. Public so tests and operators
     * can see exactly what is executed.
     *
     * @param  array{image: string, name: string, ref: string, repository: string, slug: string}  $reference
     */
    public function buildCommand(string $buildDir, array $reference): string
    {
        return sprintf(
            'cd %s && docker build --pull --build-arg OSPOS_REF=%s --build-arg OSPOS_REPOSITORY=%s -t %s .',
            escapeshellarg($buildDir),
            escapeshellarg($reference['ref']),
            escapeshellarg($reference['repository']),
            escapeshellarg($reference['image'])
        );
    }

    public function dockerfilePath(string $slug): string
    {
        return base_path("deploy/docker/apps/{$slug}/Dockerfile");
    }

    /**
     * @return array<string, array{image?: string, repository?: string, default_ref?: string}>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        // Compose rendering also runs under a bare container in unit tests.
        if (! app()->bound('config')) {
            return [];
        }

        $definitions = config('containers.app_images', []);

        return is_array($definitions) ? $definitions : [];
    }

    private function normalizeRef(?string $selectedVersion, string $defaultRef): string
    {
        $candidate = trim((string) $selectedVersion);
        if ($candidate === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $candidate) !== 1) {
            return $defaultRef;
        }

        return $candidate;
    }

    private function imageExistsOnNode(SSHService $ssh, string $image): bool
    {
        try {
            $output = $ssh->exec('docker image inspect '.escapeshellarg($image).' >/dev/null 2>&1 && echo yes || echo no', 30);

            return str_contains((string) $output, 'yes');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array{image: string, name: string, ref: string, repository: string, slug: string}  $reference
     */
    private function buildImageOnNode(SSHService $ssh, array $reference): void
    {
        $dockerfile = $this->dockerfilePath($reference['slug']);
        if (! is_file($dockerfile)) {
            throw new \RuntimeException("Application build assets missing for {$reference['slug']} ({$dockerfile})");
        }

        $buildRoot = rtrim((string) config('containers.runtime_build_path', '/opt/talksasa/runtime-builds'), '/');
        $buildDir = "{$buildRoot}/apps/{$reference['slug']}/{$reference['ref']}";

        $ssh->mkdirp($buildDir);
        $ssh->upload((string) file_get_contents($dockerfile), "{$buildDir}/Dockerfile");
        $ssh->exec($this->buildCommand($buildDir, $reference), self::BUILD_TIMEOUT_SECONDS);
    }

    private function lockName(?int $nodeId, string $image): string
    {
        return 'app-image-build:'.($nodeId ?? 'node').':'.$image;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Service $service, ?ContainerDeployment $deployment, string $event, array $payload): void
    {
        ($this->events ?? app(ContainerDeploymentEventRecorder::class))->record($service, $deployment, $event, $payload);
    }
}
