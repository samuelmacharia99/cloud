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
        $rewritten = 0;
        $candidates = $this->findPathsPhp($ssh, $root);
        $best = $this->preferPathsCandidate($candidates);

        if ($best !== null) {
            $index = $root.'/index.php';
            try {
                $hasIndex = trim($ssh->exec('test -f '.escapeshellarg($index).' && echo yes || echo no', 10));
                if ($hasIndex === 'yes') {
                    $original = $ssh->downloadFile($index);
                    $resolvedExists = in_array($root.'/Config/Paths.php', $candidates, true);
                    $shouldRewrite = $this->frontControllerHasRelativePathsRequire($original)
                        && ($this->needsFlattenedPathsRequire($original, true, $resolvedExists) || ! $resolvedExists);
                    if ($shouldRewrite) {
                        $updated = $this->rewriteFrontController($original, $this->relativeFromAppRoot($best, $root));
                        if ($updated !== $original) {
                            $ssh->upload($updated, $index);
                            $rewritten++;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $rewritten += $this->healSystemDirectoryOnHost($ssh, $root);
        if ($this->linkVendorSystemOnHost($ssh, $root)) {
            $rewritten++;
        }
        $this->ensureWritableOnHost($ssh, $root);

        return $rewritten;
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

    public function rewriteSystemDirectory(string $source, string $relativeFromPathsDir): string
    {
        $relativeFromPathsDir = ltrim(str_replace('\\', '/', $relativeFromPathsDir), '/');
        if ($relativeFromPathsDir === '' || ! preg_match('/\$systemDirectory\s*=/', $source)) {
            return $source;
        }
        if (str_contains($source, $relativeFromPathsDir)) {
            return $source;
        }

        $expr = "__DIR__ . '/".$this->escapePhpSingle($relativeFromPathsDir)."'";
        $updated = preg_replace(
            '/((?:public\s+)?(?:string\s+)?\$systemDirectory\s*=\s*)[^;]+;/',
            '$1'.$expr.';',
            $source,
            1
        );

        return is_string($updated) ? $updated : $source;
    }

    public function relativePathBetween(string $fromDir, string $toPath): string
    {
        $from = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $fromDir), '/')), 'strlen'));
        $to = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $toPath), '/')), 'strlen'));
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)).implode('/', $to);
    }

    /**
     * @return list<string>
     */
    public function vendorSystemRelatives(): array
    {
        return [
            'vendor/codeigniter4/framework/system',
            'vendor/codeigniter4/system',
        ];
    }

    public function buildLinkVendorSystemCommand(string $appRoot): string
    {
        $root = rtrim(str_replace('\\', '/', $appRoot), '/');
        $relatives = [];
        foreach ($this->vendorSystemRelatives() as $relative) {
            $relatives[] = escapeshellarg($relative);
        }

        return 'cd '.escapeshellarg($root).' || exit 1; '
            .'if [ -L system ] && [ ! -f system/Boot.php ] && [ ! -f system/CodeIgniter.php ]; then rm -f system; fi; '
            .'if [ -f system/Boot.php ] || [ -f system/CodeIgniter.php ]; then echo exists; exit 0; fi; '
            .'for rel in '.implode(' ', $relatives).'; do '
            .'  if [ -f "$rel/Boot.php" ] || [ -f "$rel/CodeIgniter.php" ]; then '
            .'    rm -rf system; '
            .'    ln -sfn "$rel" system; '
            .'    if [ -f system/Boot.php ] || [ -f system/CodeIgniter.php ]; then echo linked; exit 0; fi; '
            .'    rm -f system; '
            .'    cp -a "$rel" system; '
            .'    if [ -f system/Boot.php ] || [ -f system/CodeIgniter.php ]; then echo copied; exit 0; fi; '
            .'  fi; '
            .'done; echo missing';
    }

    public function linkVendorSystemOnHost(SSHService $ssh, string $hostAppPath): bool
    {
        try {
            $out = trim($ssh->exec($this->buildLinkVendorSystemCommand($hostAppPath), 20));
        } catch (\Throwable) {
            return false;
        }

        return in_array($out, ['linked', 'copied', 'exists'], true);
    }

    /**
     * Composer CodeIgniter keeps system/ under vendor; stock Paths.php still points at ../../system.
     */
    public function healSystemDirectoryOnHost(SSHService $ssh, string $hostAppPath): int
    {
        $root = rtrim($hostAppPath, '/');
        $vendorSystem = $root.'/vendor/codeigniter4/framework/system';
        try {
            $legacy = trim($ssh->exec(
                'test -f '.escapeshellarg($root.'/system/Boot.php')
                .' -o -f '.escapeshellarg($root.'/system/CodeIgniter.php')
                .' && echo yes || echo no',
                10
            ));
            $vendor = trim($ssh->exec(
                'test -f '.escapeshellarg($vendorSystem.'/Boot.php')
                .' -o -f '.escapeshellarg($vendorSystem.'/CodeIgniter.php')
                .' && echo yes || echo no',
                10
            ));
        } catch (\Throwable) {
            return 0;
        }
        if ($legacy === 'yes' || $vendor !== 'yes') {
            return 0;
        }

        $changed = 0;
        foreach ($this->findPathsPhp($ssh, $root) as $pathsPhp) {
            try {
                $original = $ssh->downloadFile($pathsPhp);
                $relative = $this->relativePathBetween(dirname($pathsPhp), $vendorSystem);
                $updated = $this->rewriteSystemDirectory($original, $relative);
                if ($updated !== $original) {
                    $ssh->upload($updated, $pathsPhp);
                    $changed++;
                }
            } catch (\Throwable) {
            }
        }

        return $changed;
    }

    public function ensureWritableOnHost(SSHService $ssh, string $hostAppPath): void
    {
        $writable = rtrim($hostAppPath, '/').'/writable';
        try {
            $ssh->exec(
                'mkdir -p '.escapeshellarg($writable.'/cache')
                .' '.escapeshellarg($writable.'/logs')
                .' '.escapeshellarg($writable.'/session')
                .' '.escapeshellarg($writable.'/uploads')
                .' && chmod -R ug+rwX '.escapeshellarg($writable),
                20
            );
        } catch (\Throwable) {
        }
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
