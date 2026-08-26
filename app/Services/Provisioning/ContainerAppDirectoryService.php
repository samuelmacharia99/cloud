<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

/**
 * Single source of truth for the /app mount lifecycle:
 * deploy placeholders, reset before init, and blocking-path detection.
 */
class ContainerAppDirectoryService
{
    public const PROTECTED_ROOT_ENTRY = '.talksasa';

    public const PLACEHOLDER_PATHS = [
        '.keep',
        'index.html',
        'public',
        'public/index.html',
    ];

    /**
     * @return array<int, string>
     */
    public function placeholderRelativePaths(): array
    {
        $configured = self::PLACEHOLDER_PATHS;

        if (function_exists('app')) {
            try {
                $configured = config('containers.laravel_init.placeholder_paths', self::PLACEHOLDER_PATHS);
            } catch (\Throwable) {
                $configured = self::PLACEHOLDER_PATHS;
            }
        }

        $paths = array_values(array_unique(array_merge($configured, ['public'])));

        return $paths;
    }

    public function hostAppPath(ContainerDeployment $deployment): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
    }

    public function isAllowedRelativePath(string $relativePath): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '' || $relativePath === self::PROTECTED_ROOT_ENTRY) {
            return true;
        }

        if (str_starts_with($relativePath, self::PROTECTED_ROOT_ENTRY.'/')) {
            return true;
        }

        return in_array($relativePath, $this->placeholderRelativePaths(), true);
    }

    /**
     * @return array<int, string>
     */
    public function listRelativePaths(SSHService $ssh, string $hostAppPath): array
    {
        $pathArg = escapeshellarg($hostAppPath);
        $script = 'if [ ! -d '.$pathArg.' ]; then exit 0; fi; '
            .'cd '.$pathArg.' && find . -mindepth 1 \( -type f -o -type d \) -print | sed "s|^\\./||" | sort';

        $output = trim($ssh->exec('sh -lc '.escapeshellarg($script), 30));
        if ($output === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    /**
     * @return array<int, string>
     */
    public function listBlockingRelativePaths(SSHService $ssh, ContainerDeployment $deployment): array
    {
        return array_values(array_filter(
            $this->listRelativePaths($ssh, $this->hostAppPath($deployment)),
            fn (string $path) => ! $this->isAllowedRelativePath($path)
        ));
    }

    public function hasLaravelProject(SSHService $ssh, ContainerDeployment $deployment): bool
    {
        return app(LaravelProjectPathResolver::class)->hasProjectForDeployment($ssh, $deployment);
    }

    public function isInitializeReady(SSHService $ssh, ContainerDeployment $deployment): bool
    {
        if ($this->hasLaravelProject($ssh, $deployment)) {
            return false;
        }

        return $this->listBlockingRelativePaths($ssh, $deployment) === [];
    }

    /**
     * @return array{ready: bool, has_laravel: bool, has_blocking_files: bool, can_clear: bool, blocking_paths: array<int, string>}
     */
    public function getDirectoryStatus(SSHService $ssh, ContainerDeployment $deployment): array
    {
        $hasLaravel = $this->hasLaravelProject($ssh, $deployment);
        $blockingPaths = $hasLaravel ? [] : $this->listBlockingRelativePaths($ssh, $deployment);

        return [
            'ready' => ! $hasLaravel && $blockingPaths === [],
            'has_laravel' => $hasLaravel,
            'has_blocking_files' => ! $hasLaravel && $blockingPaths !== [],
            'can_clear' => ! $hasLaravel,
            'blocking_paths' => $blockingPaths,
        ];
    }

    public function resetToPlaceholderState(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $hostAppPath = $this->hostAppPath($deployment);
        $pathArg = escapeshellarg($hostAppPath);
        $protectedArg = escapeshellarg(self::PROTECTED_ROOT_ENTRY);

        $script = 'set -e; '
            ."mkdir -p {$pathArg}; "
            ."cd {$pathArg}; "
            .'find . -mindepth 1 -maxdepth 1 ! -name '.$protectedArg.' -exec rm -rf {} +';

        $ssh->exec('sh -lc '.escapeshellarg($script), 60);
        $this->ensurePlaceholderState($ssh, $hostAppPath);
        $this->normalizePermissions($ssh, $deployment);

        if (! $this->isInitializeReady($ssh, $deployment)) {
            $blocking = $this->listBlockingRelativePaths($ssh, $deployment);

            throw new \RuntimeException(
                'Could not reset /app to the default placeholder state. Remaining paths: '
                .implode(', ', $blocking)
            );
        }
    }

    public function prepareForInitialization(SSHService $ssh, ContainerDeployment $deployment): void
    {
        if ($this->hasLaravelProject($ssh, $deployment)) {
            throw new \RuntimeException('A Laravel application already exists in /app (artisan file detected).');
        }

        if ($this->isInitializeReady($ssh, $deployment)) {
            $this->ensurePlaceholderState($ssh, $this->hostAppPath($deployment));

            return;
        }

        $this->resetToPlaceholderState($ssh, $deployment);
    }

    public function ensurePlaceholderState(SSHService $ssh, string $hostAppPath): void
    {
        $encodedHtml = base64_encode($this->placeholderHtml());
        $pathArg = escapeshellarg($hostAppPath);
        $keepArg = escapeshellarg($hostAppPath.'/.keep');
        $indexArg = escapeshellarg($hostAppPath.'/index.html');
        $publicDirArg = escapeshellarg($hostAppPath.'/public');
        $publicIndexArg = escapeshellarg($hostAppPath.'/public/index.html');

        $script = 'set -e; '
            ."mkdir -p {$pathArg}; "
            ."touch {$keepArg}; "
            ."mkdir -p {$publicDirArg}; "
            ."if [ ! -s {$indexArg} ]; then "
            .'printf %s '.escapeshellarg($encodedHtml)." | base64 -d > {$indexArg}; "
            .'fi; '
            ."if [ ! -s {$publicIndexArg} ]; then "
            .'printf %s '.escapeshellarg($encodedHtml)." | base64 -d > {$publicIndexArg}; "
            .'fi';

        $ssh->exec('sh -lc '.escapeshellarg($script), 30);
    }

    public function reclaimHostAppOwnershipForGit(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $this->reclaimHostPathOwnershipForGit($ssh, $this->hostAppPath($deployment));
    }

    public function reclaimHostPathOwnershipForGit(SSHService $ssh, string $hostAppPath): void
    {
        $hostAppPathArg = escapeshellarg($hostAppPath);

        try {
            $ssh->exec(
                'sh -lc '.escapeshellarg('chown -R $(id -u):$(id -g) '.$hostAppPathArg),
                60
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to reclaim host /app ownership before Git sync', [
                'host_app_path' => $hostAppPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function nodeModulesBinPermissionRestoreScript(string $appRoot = '/app'): string
    {
        $binDir = rtrim($appRoot, '/').'/node_modules/.bin';
        $nextBin = rtrim($appRoot, '/').'/node_modules/next/dist/bin';

        return 'find '.escapeshellarg($binDir).' '.escapeshellarg($nextBin).' -type f -exec chmod u+x {} + 2>/dev/null || true';
    }

    public function inContainerPermissionNormalizationScript(string $appRoot = '/app'): string
    {
        $root = rtrim($appRoot, '/') ?: '/app';
        $skipDependencyTrees = '\( -path '.$root.'/node_modules -o -path '.$root.'/vendor \) -prune -o';

        return 'if id www-data >/dev/null 2>&1; then chown -R www-data:www-data '.$root.';'
            .' else chown -R 33:33 '.$root.'; fi;'
            .'find '.$root.' '.$skipDependencyTrees.' -type d -exec chmod 775 {} + 2>/dev/null;'
            .'find '.$root.' '.$skipDependencyTrees.' -type f -exec chmod 664 {} + 2>/dev/null;'
            .'find '.$root.' -name artisan -type f -exec chmod 775 {} + 2>/dev/null || true; '
            .$this->nodeModulesBinPermissionRestoreScript($root === '/app' ? '/app' : $root);
    }

    /**
     * Directories Laravel requires at runtime. realpath(storage/framework/views)
     * returns false when the folder is missing, which becomes
     * "Please provide a valid cache path."
     *
     * @return list<string>
     */
    public function laravelFrameworkRelativeDirectories(): array
    {
        return [
            'storage/app/public',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ];
    }

    /**
     * Probe as www-data: missing dirs vs permission denied.
     */
    public function laravelWritableLayoutProbeScript(string $projectRoot = '/app'): string
    {
        $root = escapeshellarg(rtrim($projectRoot, '/'));

        return 'root='.$root.'; '
            .'if [ -f /app/backend/artisan ]; then root=/app/backend; fi; '
            .'missing=""; denied=""; '
            .'for d in storage storage/logs storage/framework/cache/data storage/framework/views bootstrap/cache; do '
            .'p="$root/$d"; '
            .'if [ ! -d "$p" ]; then missing="$missing $p"; continue; fi; '
            .'if ! touch "$p/.talksasa-w" 2>/dev/null; then denied="$denied $p"; else rm -f "$p/.talksasa-w"; fi; '
            .'done; '
            .'if [ -n "$missing$denied" ]; then echo "fail missing:${missing} denied:${denied}"; exit 0; fi; '
            .'echo ok';
    }

    public function laravelWritableLayoutScript(string $projectRoot = '/app'): string
    {
        $root = rtrim($projectRoot, '/');
        $dirs = array_map(
            fn (string $relative) => escapeshellarg($root.'/'.$relative),
            $this->laravelFrameworkRelativeDirectories()
        );

        return 'if id www-data >/dev/null 2>&1; then owner=www-data:www-data; else owner=33:33; fi; '
            .'mkdir -p '.implode(' ', $dirs).'; '
            .'if [ -f '.escapeshellarg($root.'/.env').' ]; then '
            .'sed -i -E '.escapeshellarg('/^VIEW_COMPILED_PATH=(|""|\'\')[[:space:]]*$/d').' '.escapeshellarg($root.'/.env').'; '
            .'fi; '
            .'chown -R $owner '.escapeshellarg($root.'/storage').' '.escapeshellarg($root.'/bootstrap/cache').' 2>/dev/null || true; '
            .'chmod -R ug+rwx '.escapeshellarg($root.'/storage').' '.escapeshellarg($root.'/bootstrap/cache').' 2>/dev/null || true; '
            .'chmod 775 '.escapeshellarg($root.'/artisan').' 2>/dev/null || true; '
            // DirectAdmin operators type `cd logs`; Laravel keeps logs under storage/logs.
            .'if [ ! -e '.escapeshellarg($root.'/logs').' ] || [ -L '.escapeshellarg($root.'/logs').' ]; then '
            .'ln -sfn storage/logs '.escapeshellarg($root.'/logs').'; '
            .'fi';
    }

    public function ensureLaravelWritableLayoutOnHost(SSHService $ssh, string $hostAppPath): void
    {
        $root = rtrim($hostAppPath, '/');
        $dirs = array_map(
            fn (string $relative) => escapeshellarg($root.'/'.$relative),
            $this->laravelFrameworkRelativeDirectories()
        );
        $ssh->exec('mkdir -p '.implode(' ', $dirs), 15);

        $this->purgeLaravelConfigCacheOnHost($ssh, $root);

        $envPath = $root.'/.env';
        try {
            $exists = trim($ssh->exec('test -f '.escapeshellarg($envPath).' && echo yes || echo no', 10));
            if ($exists === 'yes') {
                $env = $ssh->downloadFile($envPath);
                $cleaned = preg_replace('/^VIEW_COMPILED_PATH=(?:""|\'\')?\s*$/m', '', $env) ?? $env;
                if ($cleaned !== $env) {
                    $ssh->upload($cleaned, $envPath);
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * `config:cache` bakes SESSION_DRIVER=database into bootstrap/cache/config.php.
     * Recreating PHP-FPM with cookie in compose does not help until this file is gone.
     */
    public function purgeLaravelConfigCacheOnHost(SSHService $ssh, string $hostAppPath): void
    {
        $root = rtrim($hostAppPath, '/');
        $files = [
            $root.'/bootstrap/cache/config.php',
            $root.'/bootstrap/cache/config.php.bak',
            $root.'/backend/bootstrap/cache/config.php',
            $root.'/backend/bootstrap/cache/config.php.bak',
        ];
        $args = implode(' ', array_map('escapeshellarg', $files));
        try {
            $ssh->exec('rm -f '.$args, 10);
        } catch (\Throwable) {
        }
    }

    public function normalizeLaravelPermissions(SSHService $ssh, ContainerDeployment $deployment, string $projectRoot = '/app'): void
    {
        $this->normalizePermissions($ssh, $deployment);

        $containerName = escapeshellarg($deployment->container_name);
        $script = $this->laravelWritableLayoutScript($projectRoot);

        try {
            $ssh->exec('docker exec -u 0 -w / '.$containerName.' sh -lc '.escapeshellarg($script), 60);
        } catch (\Throwable $e) {
            \Log::warning('Failed to normalize Laravel writable paths', [
                'container_name' => $deployment->container_name,
                'project_root' => $projectRoot,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * DirectAdmin Spatie media lives in storage/app/public/media (or public_html/storage).
     * Without public/storage, nginx hands /storage/media/*.jpg to Laravel → HTML 404.
     */
    public function publicStorageLinkScript(string $hostAppPath): string
    {
        $app = escapeshellarg($hostAppPath);

        return 'APP='.$app.'; '
            .'PUBLIC="$APP/public"; HTML="$APP/public_html"; DISK="$APP/storage/app/public"; '
            .'if [ ! -d "$PUBLIC" ] && [ -d "$HTML" ]; then PUBLIC="$HTML"; fi; '
            .'mkdir -p "$DISK" "$PUBLIC"; '
            .'if [ -d "$PUBLIC/storage" ] && [ ! -L "$PUBLIC/storage" ]; then '
            .'  if find "$PUBLIC/storage" -type f 2>/dev/null | grep -q .; then echo kept-public-storage-dir; exit 0; fi; '
            .'  rm -rf "$PUBLIC/storage"; '
            .'fi; '
            .'if [ -d "$HTML/storage" ] && [ "$PUBLIC/storage" != "$HTML/storage" ] '
            .'&& find "$HTML/storage" -type f 2>/dev/null | grep -q .; then '
            .'  ln -sfn "$HTML/storage" "$PUBLIC/storage"; echo linked-public-html-storage; exit 0; '
            .'fi; '
            .'ln -sfn ../storage/app/public "$PUBLIC/storage"; echo linked-storage-app-public';
    }

    public function ensurePublicStorageLink(SSHService $ssh, string $hostAppPath): string
    {
        return trim((string) $ssh->exec('sh -lc '.escapeshellarg($this->publicStorageLinkScript($hostAppPath)), 30));
    }

    public function prepareProjectForComposer(SSHService $ssh, ContainerDeployment $deployment, string $projectRoot = '/app'): void
    {
        $this->normalizePermissions($ssh, $deployment);

        $containerName = escapeshellarg($deployment->container_name);
        $script = $this->composerInstallPreparationScript($projectRoot);

        try {
            $ssh->exec('docker exec -u 0 -w / '.$containerName.' sh -lc '.escapeshellarg($script), 60);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Could not prepare project directory for Composer: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    public function composerInstallPreparationScript(string $projectRoot = '/app'): string
    {
        $root = rtrim($projectRoot, '/');

        return 'root='.escapeshellarg($root).'; '
            .'if id www-data >/dev/null 2>&1; then owner=www-data:www-data; else owner=33:33; fi; '
            .'chown -R $owner /app; '
            .'chown -R $owner $root; '
            .'mkdir -p $root/vendor $root/storage $root/bootstrap/cache; '
            .'chown -R $owner $root/vendor $root/storage $root/bootstrap/cache; '
            .'chmod -R ug+rwX $root';
    }

    public function normalizePermissions(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerName = escapeshellarg($deployment->container_name);
        $hostAppPath = escapeshellarg($this->hostAppPath($deployment));
        $appRoot = $this->inContainerAppRoot($deployment);
        $ownership = $this->inContainerPermissionNormalizationScript($appRoot);

        try {
            $ssh->exec('docker exec -u 0 -w / '.$containerName.' sh -lc '.escapeshellarg($ownership), 60);
        } catch (\Throwable $e) {
            \Log::warning('Failed to normalize app ownership inside container', [
                'container_name' => $deployment->container_name,
                'app_root' => $appRoot,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $wwwDataUidScript = 'id -u www-data 2>/dev/null || echo 33';
            $chownScript = "chown -R \\\${uid}:\\\${uid} {$hostAppPath}";
            $ssh->exec(
                'uid=$(docker exec -u 0 -w / '.$containerName.' sh -lc '.escapeshellarg($wwwDataUidScript).'); '.$chownScript,
                60
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to normalize host app mount ownership', [
                'host_app_path' => $this->hostAppPath($deployment),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function inContainerAppRoot(ContainerDeployment $deployment): string
    {
        $deployment->loadMissing('service');
        $slug = $deployment->service?->effectiveContainerTemplate()?->slug
            ?? $deployment->service?->product?->containerTemplate?->slug;

        return $slug === 'wordpress' ? '/var/www/html' : '/app';
    }

    private function placeholderHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Welcome to Talksasa Cloud</title>
  <style>
    :root { color-scheme: dark; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      font-family: Arial, sans-serif;
      background: #0f172a;
      color: #e2e8f0;
      text-align: center;
      padding: 24px;
    }
    .card {
      max-width: 680px;
      padding: 32px;
      border-radius: 16px;
      background: rgba(15, 23, 42, 0.85);
      border: 1px solid rgba(148, 163, 184, 0.35);
    }
    h1 { margin: 0 0 10px; font-size: 2rem; }
    p { margin: 0; color: #cbd5e1; font-size: 1.05rem; }
  </style>
</head>
<body>
  <main class="card">
    <h1>Welcome to Talksasa Cloud</h1>
    <p>Your digital infrastructure partner.</p>
  </main>
</body>
</html>
HTML;
    }
}
