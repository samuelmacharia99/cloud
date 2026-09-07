<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

/**
 * DirectAdmin often puts CodeIgniter 4's public/index.php at /app/index.php
 * while Config/Paths.php lives under /app/app/ (or another subtree). The stock
 * require FCPATH.'../app/Config/Paths.php' then resolves to /app/Config/.
 */
class PhpCodeIgniterPathFixer
{
    public function looksLikeCodeIgniterFrontController(string $source): bool
    {
        return str_contains($source, 'Config/Paths.php')
            || str_contains($source, 'FCPATH')
            || str_contains($source, 'CodeIgniter');
    }

    public function needsFlattenedPathsRequire(string $source, bool $nestedAppPathsExist, bool $resolvedPathsExist): bool
    {
        return $nestedAppPathsExist
            && ! $resolvedPathsExist
            && $this->looksLikeCodeIgniterFrontController($source)
            && $this->frontControllerHasRelativePathsRequire($source);
    }

    public function frontControllerHasRelativePathsRequire(string $source): bool
    {
        return str_contains($source, '../app/Config/Paths.php')
            || preg_match('/(?:FCPATH|__DIR__)\s*\.\s*[\'"][^\'"]*\.\.\/[^\'"]*Config\/Paths\.php[\'"]/', $source) === 1;
    }

    public function rewriteFrontController(string $source, string $relativeFromFrontDir = 'app/Config/Paths.php'): string
    {
        $relativeFromFrontDir = ltrim(str_replace('\\', '/', $relativeFromFrontDir), '/');
        $expr = "__DIR__ . '/".$this->escapePhpSingle($relativeFromFrontDir)."'";

        $updated = preg_replace(
            '/((?:require|include)(?:_once)?)\s*\(?\s*(?:FCPATH|__DIR__)\s*\.\s*([\'"])[^\'"]*Config\/Paths\.php\2\s*\)?/',
            '$1 '.$expr,
            $source,
            1
        );
        if (is_string($updated) && $updated !== $source) {
            return $updated;
        }

        if (str_contains($source, '../app/Config/Paths.php')) {
            return str_replace('../app/Config/Paths.php', $relativeFromFrontDir, $source);
        }

        return $source;
    }

    /**
     * @param  list<string>  $paths
     */
    public function preferPathsCandidate(array $paths): ?string
    {
        $normalized = [];
        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path));
            if ($path !== '') {
                $normalized[] = $path;
            }
        }
        foreach ($normalized as $path) {
            if ($path === '/app/app/Config/Paths.php' || str_ends_with($path, '/app/Config/Paths.php')) {
                return $path;
            }
        }

        return $normalized[0] ?? null;
    }

    public function relativeFromAppRoot(string $path, string $appRoot = '/app'): string
    {
        $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $appRoot.'/')) {
            return substr($path, strlen($appRoot) + 1);
        }

        return ltrim($path, '/');
    }

    /**
     * @return int Files rewritten
     */
    public function applyOnHost(SSHService $ssh, string $hostAppPath): int
    {
        $root = rtrim($hostAppPath, '/');
        $candidates = $this->findPathsPhp($ssh, $root);
        $best = $this->preferPathsCandidate($candidates);
        if ($best === null) {
            return 0;
        }

        $index = $root.'/index.php';
        try {
            $hasIndex = trim($ssh->exec('test -f '.escapeshellarg($index).' && echo yes || echo no', 10));
            if ($hasIndex !== 'yes') {
                return 0;
            }
            $original = $ssh->downloadFile($index);
        } catch (\Throwable) {
            return 0;
        }

        $resolvedExists = in_array($root.'/Config/Paths.php', $candidates, true);
        if (! $this->needsFlattenedPathsRequire($original, true, $resolvedExists)
            && ! $this->frontControllerHasRelativePathsRequire($original)) {
            return 0;
        }
        if ($resolvedExists && ! $this->frontControllerHasRelativePathsRequire($original)) {
            return 0;
        }

        $updated = $this->rewriteFrontController($original, $this->relativeFromAppRoot($best, $root));
        if ($updated === $original) {
            return 0;
        }

        $ssh->upload($updated, $index);

        return 1;
    }

    /**
     * Rewrite the live container file (bind mount or named volume) so FPM sees it.
     *
     * @return int Files rewritten
     */
    public function applyInContainer(SSHService $ssh, ContainerDeployment $deployment): int
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $service = $deployment->container_name;

        try {
            $list = trim($ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T '.escapeshellarg($service)
                .' sh -lc '.escapeshellarg(
                    'find /app -maxdepth 6 -type f \( -path "*/Config/Paths.php" -o -path "*/config/Paths.php" \)'
                    .' ! -path "*/vendor/*" ! -path "*/node_modules/*" | head -n 8'
                ),
                25
            ));
            $original = $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T '.escapeshellarg($service)
                .' sh -lc '.escapeshellarg('test -f /app/index.php && cat /app/index.php'),
                20
            );
        } catch (\Throwable) {
            return 0;
        }

        $candidates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $list) ?: [])));
        $best = $this->preferPathsCandidate($candidates);
        if ($best === null || ! $this->looksLikeCodeIgniterFrontController($original)) {
            return 0;
        }
        if (! $this->frontControllerHasRelativePathsRequire($original)
            && in_array('/app/Config/Paths.php', $candidates, true)) {
            return 0;
        }

        $updated = $this->rewriteFrontController($original, $this->relativeFromAppRoot($best, '/app'));
        if ($updated === $original) {
            return 0;
        }

        $hostIndex = $containerPath.'/app/index.php';
        $ssh->upload($updated, $hostIndex);
        try {
            $ssh->exec(
                'docker cp '.escapeshellarg($hostIndex).' '
                .escapeshellarg($deployment->container_name.':/app/index.php'),
                15
            );
        } catch (\Throwable) {
        }

        return 1;
    }

    /**
     * @return list<string>
     */
    public function findPathsPhp(SSHService $ssh, string $searchRoot): array
    {
        try {
            $list = trim($ssh->exec(
                'find '.escapeshellarg($searchRoot)
                .' -maxdepth 6 -type f \( -path \'*/Config/Paths.php\' -o -path \'*/config/Paths.php\' \)'
                .' ! -path \'*/vendor/*\' ! -path \'*/node_modules/*\' | head -n 8',
                20
            ));
        } catch (\Throwable) {
            return [];
        }

        $paths = [];
        foreach (preg_split('/\r\n|\r|\n/', $list) ?: [] as $path) {
            $path = trim($path);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function escapePhpSingle(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
