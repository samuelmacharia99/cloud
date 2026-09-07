<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

class PhpRuntime500Probe
{
    /**
     * @return array{fatal: ?string, uses_mysql_ext: bool, index_files: list<string>, lint: list<string>, paths_php: list<string>, index_require: ?string}
     */
    public function capture(SSHService $ssh, ContainerDeployment $deployment): array
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $empty = [
            'fatal' => null,
            'uses_mysql_ext' => false,
            'index_files' => [],
            'lint' => [],
            'paths_php' => [],
            'index_require' => null,
        ];

        try {
            $raw = trim($ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T '.escapeshellarg($deployment->container_name)
                .' php -d display_errors=0 -d log_errors=0 -r '.escapeshellarg($this->script()),
                25
            ));
        } catch (\Throwable $e) {
            $empty['fatal'] = mb_substr($e->getMessage(), 0, 280);

            return $empty;
        }

        if (preg_match('/TALKSASA_PHP500=(\{.*\})\s*$/s', $raw, $matches) !== 1) {
            if (trim($raw) !== '') {
                $empty['fatal'] = mb_substr(trim($raw), 0, 280);
            }

            return $empty;
        }

        $decoded = json_decode($matches[1], true);
        if (! is_array($decoded)) {
            return $empty;
        }

        $result = [
            'fatal' => isset($decoded['fatal']) && is_string($decoded['fatal']) && $decoded['fatal'] !== ''
                ? mb_substr($decoded['fatal'], 0, 400)
                : null,
            'uses_mysql_ext' => (bool) ($decoded['uses_mysql_ext'] ?? false),
            'index_files' => array_values(array_filter(
                is_array($decoded['index_files'] ?? null) ? $decoded['index_files'] : [],
                'is_string'
            )),
            'lint' => array_values(array_filter(
                is_array($decoded['lint'] ?? null) ? $decoded['lint'] : [],
                'is_string'
            )),
            'paths_php' => array_values(array_filter(
                is_array($decoded['paths_php'] ?? null) ? $decoded['paths_php'] : [],
                'is_string'
            )),
            'index_require' => isset($decoded['index_require']) && is_string($decoded['index_require'])
                ? mb_substr($decoded['index_require'], 0, 200)
                : null,
        ];

        if ($result['fatal'] === null) {
            $front = $this->preferredFrontController($result['index_files']);
            if ($front !== null) {
                $result['fatal'] = $this->captureFrontControllerFatal($ssh, $deployment, $front);
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $indexFiles
     */
    private function preferredFrontController(array $indexFiles): ?string
    {
        foreach (['/app/index.php', '/app/public/index.php', '/app/public_html/index.php'] as $path) {
            foreach ($indexFiles as $listed) {
                if (str_starts_with($listed, $path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function captureFrontControllerFatal(
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $front,
    ): ?string {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        try {
            $raw = trim($ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg(
                    'PREPEND=""; if [ -f /app/.talksasa-mysql-shim.php ]; then '
                    .'PREPEND="-d auto_prepend_file=/app/.talksasa-mysql-shim.php"; fi; '
                    .'timeout 8 php -d display_errors=1 -d error_reporting=32767 $PREPEND '
                    .escapeshellarg($front).' 2>&1 | tail -n 30 || true'
                ),
                20
            ));
        } catch (\Throwable) {
            return null;
        }

        if (preg_match('/(Fatal error:|Uncaught |Call to undefined function)[^\n]{0,240}/i', $raw, $matches) !== 1) {
            return null;
        }

        return trim($matches[0]);
    }

    public function summary(array $probe): string
    {
        $parts = [];
        if (($probe['fatal'] ?? null) !== null && $probe['fatal'] !== '') {
            $parts[] = $probe['fatal'];
        }
        if (($probe['uses_mysql_ext'] ?? false) === true) {
            $parts[] = 'The site still calls mysql_* (removed in PHP 8). Restart now preloads a mysqli shim.';
        }
        $indexRequire = trim((string) ($probe['index_require'] ?? ''));
        if ($indexRequire !== '') {
            $parts[] = 'Front controller: '.$indexRequire;
        }
        $paths = $probe['paths_php'] ?? [];
        if ($paths === [] && str_contains($indexRequire, 'Paths.php')) {
            $parts[] = 'Config/Paths.php was not found under /app — DirectAdmin often kept the CodeIgniter app/ folder next to public_html.';
        } elseif ($paths !== []) {
            $parts[] = 'Paths.php at '.implode(', ', array_slice($paths, 0, 3)).'.';
        }
        if (($probe['index_files'] ?? []) === []) {
            $parts[] = 'No index.php was found under /app or /app/public.';
        }
        foreach ($probe['lint'] ?? [] as $lint) {
            $parts[] = $lint;
        }

        return $parts === []
            ? 'PHP did not print a fatal. Check Logs for nginx FastCGI errors.'
            : implode(' ', $parts);
    }

    public function script(): string
    {
        return <<<'PHP'
$indexes = [];
foreach (['/app/index.php', '/app/public/index.php', '/app/public_html/index.php'] as $file) {
    if (is_file($file)) {
        $indexes[] = $file.' ('.filesize($file).' bytes)';
    }
}
$lint = [];
foreach (['/app/index.php', '/app/public/index.php'] as $file) {
    if (! is_file($file)) {
        continue;
    }
    $out = [];
    $code = 0;
    exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
    if ($code !== 0) {
        $lint[] = trim(implode(' ', $out));
    }
}
$uses = false;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/app', FilesystemIterator::SKIP_DOTS));
$count = 0;
foreach ($iterator as $file) {
    if ($count++ > 400) {
        break;
    }
    $path = $file->getPathname();
    if (! str_ends_with($path, '.php') || str_contains($path, '/vendor/') || str_contains($path, '.talksasa-mysql-shim.php')) {
        continue;
    }
    $src = @file_get_contents($path, false, null, 0, 200000);
    if (is_string($src) && preg_match('/\bmysql_(connect|pconnect|query|select_db)\s*\(/', $src)) {
        $uses = true;
        break;
    }
}
$pathsPhp = [];
exec('find /app -maxdepth 6 -type f \\( -path "*/Config/Paths.php" -o -path "*/config/Paths.php" \\) ! -path "*/vendor/*" 2>/dev/null', $pathsPhp);
$indexRequire = null;
if (is_file('/app/index.php')) {
    foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents('/app/index.php')) ?: [] as $line) {
        if (str_contains($line, 'Config/Paths.php')) {
            $indexRequire = trim($line);
            break;
        }
    }
}
echo 'TALKSASA_PHP500='.json_encode([
    'fatal' => null,
    'uses_mysql_ext' => $uses,
    'index_files' => $indexes,
    'lint' => $lint,
    'paths_php' => $pathsPhp,
    'index_require' => $indexRequire,
]);
PHP;
    }
}
