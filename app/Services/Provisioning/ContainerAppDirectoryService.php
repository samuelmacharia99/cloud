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
        $artisanPath = escapeshellarg($this->hostAppPath($deployment).'/artisan');

        try {
            $ssh->exec('sh -lc '.escapeshellarg('test -f '.$artisanPath), 15);

            return true;
        } catch (\Throwable) {
            return false;
        }
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

    public function inContainerPermissionNormalizationScript(): string
    {
        $skipDependencyTrees = '\( -path /app/node_modules -o -path /app/vendor \) -prune -o';

        return 'if id www-data >/dev/null 2>&1; then chown -R www-data:www-data /app;'
            .' else chown -R 33:33 /app; fi;'
            .'find /app '.$skipDependencyTrees.' -type d -exec chmod 775 {} + 2>/dev/null;'
            .'find /app '.$skipDependencyTrees.' -type f -exec chmod 664 {} + 2>/dev/null;'
            .'chmod 775 /app/artisan 2>/dev/null || true';
    }

    public function normalizePermissions(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerName = escapeshellarg($deployment->container_name);
        $hostAppPath = escapeshellarg($this->hostAppPath($deployment));
        $ownership = $this->inContainerPermissionNormalizationScript();

        try {
            $ssh->exec('docker exec -u 0 '.$containerName.' sh -lc '.escapeshellarg($ownership), 60);
        } catch (\Throwable $e) {
            \Log::warning('Failed to normalize /app ownership inside container', [
                'container_name' => $deployment->container_name,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $wwwDataUidScript = 'id -u www-data 2>/dev/null || echo 33';
            $chownScript = "chown -R \\\${uid}:\\\${uid} {$hostAppPath}";
            $ssh->exec(
                'uid=$(docker exec -u 0 '.$containerName.' sh -lc '.escapeshellarg($wwwDataUidScript).'); '.$chownScript,
                60
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to normalize host /app mount ownership', [
                'host_app_path' => $this->hostAppPath($deployment),
                'error' => $e->getMessage(),
            ]);
        }
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
