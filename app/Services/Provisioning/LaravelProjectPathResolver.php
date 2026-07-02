<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

/**
 * Resolves Laravel project and web document roots for non-standard layouts
 * (e.g. ViserLab-style apps with /app/core/artisan and /app/index.php).
 */
class LaravelProjectPathResolver
{
    /**
     * @return list<string> Relative path segments under /app (empty string = /app itself).
     */
    public function candidateRelativeRoots(): array
    {
        $configured = config('containers.laravel_init.project_root_candidates', ['', 'core', 'backend']);

        return array_values(array_unique(array_map(
            static fn (string $segment) => trim(str_replace('\\', '/', $segment), '/'),
            $configured
        )));
    }

    public function hasProject(SSHService $ssh, string $hostAppPath): bool
    {
        return $this->findRelativeRoot($ssh, $hostAppPath) !== null;
    }

    public function hasProjectForDeployment(SSHService $ssh, ContainerDeployment $deployment): bool
    {
        return $this->hasProject($ssh, $this->hostAppPath($deployment));
    }

    public function findRelativeRoot(SSHService $ssh, string $hostAppPath): ?string
    {
        foreach ($this->candidateRelativeRoots() as $relative) {
            $artisanPath = $relative === ''
                ? $hostAppPath.'/artisan'
                : $hostAppPath.'/'.$relative.'/artisan';

            if ($this->hostFileExists($ssh, $artisanPath)) {
                return $relative;
            }
        }

        return null;
    }

    public function containerProjectRoot(?string $relativeRoot): string
    {
        $relativeRoot = trim((string) $relativeRoot, '/');

        return $relativeRoot === '' ? '/app' : '/app/'.$relativeRoot;
    }

    public function hostProjectRoot(string $hostAppPath, ?string $relativeRoot): string
    {
        $relativeRoot = trim((string) $relativeRoot, '/');

        return $relativeRoot === '' ? $hostAppPath : $hostAppPath.'/'.$relativeRoot;
    }

    public function resolveDocumentRoot(SSHService $ssh, string $hostAppPath, ?string $relativeRoot = null): string
    {
        $relativeRoot ??= $this->findRelativeRoot($ssh, $hostAppPath);
        $projectHostPath = $this->hostProjectRoot($hostAppPath, $relativeRoot ?? '');
        $projectContainerPath = $this->containerProjectRoot($relativeRoot ?? '');

        if ($this->hostFileExists($ssh, $projectHostPath.'/public/index.php')) {
            return $projectContainerPath.'/public';
        }

        if ($this->hostFileExists($ssh, $hostAppPath.'/index.php')) {
            return '/app';
        }

        if ($this->hostFileExists($ssh, $projectHostPath.'/public')) {
            return $projectContainerPath.'/public';
        }

        return $projectContainerPath;
    }

    /**
     * @return array{relative_root: string, project_root: string, document_root: string}
     */
    public function resolveForDeployment(SSHService $ssh, ContainerDeployment $deployment): ?array
    {
        $hostAppPath = $this->hostAppPath($deployment);
        $relativeRoot = $this->findRelativeRoot($ssh, $hostAppPath);
        if ($relativeRoot === null) {
            return null;
        }

        return [
            'relative_root' => $relativeRoot,
            'project_root' => $this->containerProjectRoot($relativeRoot),
            'document_root' => $this->resolveDocumentRoot($ssh, $hostAppPath, $relativeRoot),
        ];
    }

    public function persistResolvedPaths(Service $service, SSHService $ssh, ContainerDeployment $deployment): ?array
    {
        $resolved = $this->resolveForDeployment($ssh, $deployment);
        if ($resolved === null) {
            return null;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['laravel_project_root'] = $resolved['relative_root'];
        $meta['laravel_document_root'] = $resolved['document_root'];
        $service->update(['service_meta' => $meta]);

        return $resolved;
    }

    public function documentRootFromServiceMeta(Service $service, string $default = '/app/public'): string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $documentRoot = trim((string) ($meta['laravel_document_root'] ?? ''));

        return $documentRoot !== '' ? $documentRoot : $default;
    }

    public function projectRootFromServiceMeta(Service $service, string $default = '/app'): string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $relativeRoot = trim((string) ($meta['laravel_project_root'] ?? ''), '/');

        return $this->containerProjectRoot($relativeRoot);
    }

    private function hostAppPath(ContainerDeployment $deployment): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
    }

    private function hostFileExists(SSHService $ssh, string $path): bool
    {
        try {
            $ssh->exec('sh -lc '.escapeshellarg('test -f '.escapeshellarg($path)), 15);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
