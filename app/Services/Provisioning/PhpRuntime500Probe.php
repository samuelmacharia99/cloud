<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

class PhpRuntime500Probe
{
    /**
     * @return array{
     *     fatal: ?string,
     *     uses_mysql_ext: bool,
     *     index_files: list<string>,
     *     lint: list<string>,
     *     paths_php: list<string>,
     *     index_require: ?string,
     *     ci_system: bool,
     *     ci_vendor_system: bool,
     *     ci_writable: bool,
     *     ci_db_host: ?string,
     *     ci_encryption_key: bool,
     *     ci_autoload: bool,
     *     ci_log: ?string,
     *     ci_db_name: ?string,
     *     ci_db_user: ?string,
     *     ci_pdo_error: ?string,
     *     ci_mysqli: bool,
     *     ci_db_driver: ?string,
     *     ci_http_body: ?string,
     *     ci_ospos: bool
     * }
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
            'ci_system' => false,
            'ci_vendor_system' => false,
            'ci_writable' => false,
            'ci_db_host' => null,
            'ci_encryption_key' => false,
            'ci_autoload' => false,
            'ci_log' => null,
            'ci_db_name' => null,
            'ci_db_user' => null,
            'ci_pdo_error' => null,
            'ci_mysqli' => false,
            'ci_db_driver' => null,
            'ci_http_body' => null,
            'ci_ospos' => false,
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
            'ci_system' => (bool) ($decoded['ci_system'] ?? false),
            'ci_vendor_system' => (bool) ($decoded['ci_vendor_system'] ?? false),
            'ci_writable' => (bool) ($decoded['ci_writable'] ?? false),
            'ci_db_host' => isset($decoded['ci_db_host']) && is_string($decoded['ci_db_host']) && $decoded['ci_db_host'] !== ''
                ? mb_substr($decoded['ci_db_host'], 0, 80)
                : null,
            'ci_encryption_key' => (bool) ($decoded['ci_encryption_key'] ?? false),
            'ci_autoload' => (bool) ($decoded['ci_autoload'] ?? false),
            'ci_log' => isset($decoded['ci_log']) && is_string($decoded['ci_log']) && $decoded['ci_log'] !== ''
                ? mb_substr($decoded['ci_log'], 0, 280)
                : null,
            'ci_db_name' => isset($decoded['ci_db_name']) && is_string($decoded['ci_db_name']) && $decoded['ci_db_name'] !== ''
                ? mb_substr($decoded['ci_db_name'], 0, 64)
                : null,
            'ci_db_user' => isset($decoded['ci_db_user']) && is_string($decoded['ci_db_user']) && $decoded['ci_db_user'] !== ''
                ? mb_substr($decoded['ci_db_user'], 0, 64)
                : null,
            'ci_pdo_error' => isset($decoded['ci_pdo_error']) && is_string($decoded['ci_pdo_error']) && $decoded['ci_pdo_error'] !== ''
                ? mb_substr($decoded['ci_pdo_error'], 0, 220)
                : null,
            'ci_mysqli' => (bool) ($decoded['ci_mysqli'] ?? false),
            'ci_db_driver' => isset($decoded['ci_db_driver']) && is_string($decoded['ci_db_driver']) && $decoded['ci_db_driver'] !== ''
                ? mb_substr($decoded['ci_db_driver'], 0, 32)
                : null,
            'ci_http_body' => isset($decoded['ci_http_body']) && is_string($decoded['ci_http_body']) && $decoded['ci_http_body'] !== ''
                ? mb_substr($decoded['ci_http_body'], 0, 200)
                : null,
            'ci_ospos' => (bool) ($decoded['ci_ospos'] ?? false),
        ];

        if ($result['fatal'] === null && is_string($result['ci_pdo_error'])) {
            $result['fatal'] = $result['ci_pdo_error'];
        }

        if ($result['fatal'] === null && is_string($result['ci_log'])) {
            $result['fatal'] = $result['ci_log'];
        }

        $httpException = is_string($result['ci_http_body'])
            ? $this->extractExceptionFromOutput($result['ci_http_body'])
            : null;
        if ($result['fatal'] === null && $httpException !== null && ! $this->isGenericServerError($httpException)) {
            $result['fatal'] = $httpException;
        }

        if ($result['fatal'] === null || $this->isGenericServerError((string) $result['fatal'])) {
            foreach ($this->preferredFrontControllers($result['index_files']) as $front) {
                $cliFatal = $this->captureFrontControllerFatal($ssh, $deployment, $front);
                if ($cliFatal !== null && ! $this->isGenericServerError($cliFatal)) {
                    $result['fatal'] = $cliFatal;
                    break;
                }
                if ($result['fatal'] === null && $cliFatal !== null) {
                    $result['fatal'] = $cliFatal;
                }
            }
        }

        return $result;
    }

    /**
     * nginx on this image defaults to /app/public; DA public_html lands at /app/index.php.
     *
     * @param  list<string>  $indexFiles
     * @return list<string>
     */
    private function preferredFrontControllers(array $indexFiles): array
    {
        $found = [];
        foreach (['/app/public/index.php', '/app/index.php', '/app/public_html/index.php'] as $path) {
            foreach ($indexFiles as $listed) {
                if (str_starts_with($listed, $path)) {
                    $found[] = $path;
                    break;
                }
            }
        }

        return $found;
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
                    .'export CI_ENVIRONMENT=development CI_DEBUG=true '
                    .'REQUEST_METHOD=GET REQUEST_URI=/ SCRIPT_NAME=/index.php '
                    .'HTTP_HOST=localhost SERVER_NAME=localhost SERVER_PORT=80; '
                    .'timeout 8 php -d display_errors=1 -d error_reporting=32767 $PREPEND '
                    .escapeshellarg($front).' 2>&1 | tail -n 80 || true'
                ),
                20
            ));
        } catch (\Throwable) {
            return null;
        }

        return $this->extractExceptionFromOutput($raw);
    }

    public function isGenericServerError(string $text): bool
    {
        return preg_match('/^\s*server error\.?\s*$/i', trim(strip_tags($text))) === 1;
    }

    public function extractExceptionFromOutput(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match(
            '/(Fatal error:|Uncaught |Call to undefined function|Failed opening required|DatabaseException|Class "[^"]+" not found|Unable to connect to the database|Access denied for user|The encryption key|intl extension|mysqli)[^\n<]{0,240}/i',
            $raw,
            $matches
        ) === 1) {
            $hit = trim(html_entity_decode(strip_tags($matches[0]), ENT_QUOTES));

            return $hit !== '' ? $hit : null;
        }

        if (preg_match('/class="(?:exception-message|message)"[^>]*>([^<]{3,240})/i', $raw, $matches) === 1) {
            $message = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES));
            if ($message !== '') {
                return $message;
            }
        }

        if (preg_match('/<title>([^<]{3,160})<\/title>/i', $raw, $matches) === 1) {
            $title = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES));
            if ($title !== '' && ! preg_match('/^(error|exception)$/i', $title)) {
                return $title;
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? '');
        if ($this->isGenericServerError($text)) {
            return 'Server Error';
        }

        return null;
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
        $ciHost = trim((string) ($probe['ci_db_host'] ?? ''));
        if ($ciHost !== '') {
            $parts[] = 'CodeIgniter DB hostname: '.$ciHost.'.';
        }
        $ciUser = trim((string) ($probe['ci_db_user'] ?? ''));
        $ciName = trim((string) ($probe['ci_db_name'] ?? ''));
        if ($ciUser !== '' || $ciName !== '') {
            $parts[] = 'CodeIgniter DB user/name: '.trim($ciUser.' / '.$ciName, ' /').'.';
        }
        if (trim((string) ($probe['ci_pdo_error'] ?? '')) !== '') {
            $parts[] = 'App PDO: '.$probe['ci_pdo_error'];
        }
        $driver = trim((string) ($probe['ci_db_driver'] ?? ''));
        if (($probe['ci_mysqli'] ?? true) !== true && ($driver === '' || str_contains(strtolower($driver), 'mysqli') || strcasecmp($driver, 'mysql') === 0)) {
            $parts[] = 'mysqli is not loaded (CodeIgniter’s default DBDriver). Doctor PDO can still succeed.';
        }
        $httpBody = trim((string) ($probe['ci_http_body'] ?? ''));
        if ($httpBody !== '') {
            $parts[] = 'HTTP body: '.$httpBody;
        }
        if (($probe['ci_system'] ?? false) !== true && ($probe['ci_vendor_system'] ?? false) !== true
            && $paths !== []) {
            $parts[] = 'system/ and vendor/codeigniter4 are missing — CodeIgniter cannot boot.';
        } elseif (($probe['ci_system'] ?? false) !== true && ($probe['ci_vendor_system'] ?? false) === true) {
            $parts[] = 'system/ is missing; vendor/codeigniter4 is present.';
        }
        if (($probe['ci_encryption_key'] ?? true) !== true && $paths !== []) {
            $parts[] = 'encryption.key is empty.';
        }
        if (($probe['ci_autoload'] ?? true) !== true && $paths !== []) {
            $parts[] = 'vendor/autoload.php is missing.';
        }
        if (($probe['ci_ospos'] ?? false) === true) {
            $parts[] = 'This tree is Open Source POS (app/Config/OSPOS.php).';
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
$ciHost = null;
$ciName = null;
$ciUser = null;
$ciPass = null;
foreach (['/app/.env', '/app/app/.env'] as $envFile) {
    if (! is_file($envFile)) {
        continue;
    }
    foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($envFile)) ?: [] as $line) {
        $line = trim($line);
        if (preg_match('/^(?:database\\.default\\.(?:hostname|host)|DB_HOST)[ \\t]*=[ \\t]*(.+)$/', $line, $match) === 1) {
            $ciHost = trim($match[1], " \t\"'");
        } elseif (preg_match('/^(?:database\\.default\\.database|DB_DATABASE)[ \\t]*=[ \\t]*(.+)$/', $line, $match) === 1) {
            $ciName = trim($match[1], " \t\"'");
        } elseif (preg_match('/^(?:database\\.default\\.username|DB_USERNAME)[ \\t]*=[ \\t]*(.+)$/', $line, $match) === 1) {
            $ciUser = trim($match[1], " \t\"'");
        } elseif (preg_match('/^(?:database\\.default\\.password|DB_PASSWORD)[ \\t]*=[ \\t]*(.*)$/', $line, $match) === 1) {
            $ciPass = trim($match[1], " \t\"'");
        }
    }
}
if (is_file('/app/app/Config/Database.php')) {
    $dbSrc = (string) file_get_contents('/app/app/Config/Database.php');
    if ($ciHost === null && preg_match('/[\'"]hostname[\'"]\\s*=>\\s*[\'"]([^\'"]+)/', $dbSrc, $match) === 1) {
        $ciHost = $match[1];
    }
    if ($ciName === null && preg_match('/[\'"]database[\'"]\\s*=>\\s*[\'"]([^\'"]+)/', $dbSrc, $match) === 1) {
        $ciName = $match[1];
    }
    if ($ciUser === null && preg_match('/[\'"]username[\'"]\\s*=>\\s*[\'"]([^\'"]+)/', $dbSrc, $match) === 1) {
        $ciUser = $match[1];
    }
}
$pdoError = null;
if (is_string($ciHost) && $ciHost !== '' && is_string($ciUser) && $ciUser !== '' && is_string($ciName) && $ciName !== '') {
    try {
        new PDO('mysql:host='.$ciHost.';dbname='.$ciName.';charset=utf8mb4', $ciUser, (string) $ciPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 4,
        ]);
    } catch (Throwable $e) {
        $pdoError = $e->getMessage();
    }
}
$encryptionSet = false;
$envText = '';
foreach (['/app/.env', '/app/app/.env'] as $envFile) {
    if (is_file($envFile)) {
        $envText .= "\n".(string) file_get_contents($envFile);
    }
}
if (preg_match('/^encryption\\.key[ \\t]*=[ \\t]*(.+)$/m', $envText, $match) === 1) {
    $encryptionSet = trim($match[1], " \t\"'") !== '' && strcasecmp(trim($match[1], " \t\"'"), 'hex2bin:') !== 0;
}
$driver = null;
if (isset($dbSrc) && is_string($dbSrc) && preg_match('/[\'"]DBDriver[\'"]\\s*=>\\s*[\'"]([^\'"]+)/', $dbSrc, $match) === 1) {
    $driver = $match[1];
}
foreach (preg_split('/\\r\\n|\\r|\\n/', $envText) ?: [] as $line) {
    if (preg_match('/^database\\.default\\.DBDriver[ \\t]*=[ \\t]*(.+)$/', trim($line), $match) === 1) {
        $driver = trim($match[1], " \t\"'");
    }
}
$httpBody = null;
$ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'header' => "Host: localhost\r\n"]]);
$origin = @file_get_contents('http://127.0.0.1:8080/', false, $ctx);
if (is_string($origin) && $origin !== '') {
    $httpBody = trim(preg_replace('/\\s+/', ' ', strip_tags($origin)) ?? '');
    $httpBody = $httpBody !== '' ? substr($httpBody, 0, 160) : substr(trim($origin), 0, 32);
}
$ciLog = null;
$logFiles = array_merge(
    glob('/app/writable/logs/log-*.log') ?: [],
    glob('/app/writable/logs/log-*.php') ?: [],
    glob('/app/app/writable/logs/log-*.log') ?: [],
    glob('/app/app/writable/logs/log-*.php') ?: []
);
rsort($logFiles);
if ($logFiles !== []) {
    $tail = (string) @file_get_contents($logFiles[0]);
    if (preg_match('/(CRITICAL|ERROR|ErrorException|ParseError|Unable to write|encryption key|Access denied|1045|Unable to connect|Class .* not found|mysqli|DatabaseException)[^\\n]{0,240}/i', $tail, $match) === 1) {
        $ciLog = trim($match[0]);
    }
}
echo 'TALKSASA_PHP500='.json_encode([
    'fatal' => $pdoError ?: $ciLog,
    'uses_mysql_ext' => $uses,
    'index_files' => $indexes,
    'lint' => $lint,
    'paths_php' => $pathsPhp,
    'index_require' => $indexRequire,
    'ci_system' => is_file('/app/system/Boot.php') || is_file('/app/system/CodeIgniter.php'),
    'ci_vendor_system' => is_file('/app/vendor/codeigniter4/framework/system/Boot.php')
        || is_file('/app/vendor/codeigniter4/framework/system/CodeIgniter.php'),
    'ci_writable' => is_dir('/app/writable'),
    'ci_db_host' => $ciHost,
    'ci_encryption_key' => $encryptionSet,
    'ci_autoload' => is_file('/app/vendor/autoload.php'),
    'ci_log' => $ciLog,
    'ci_db_name' => $ciName,
    'ci_db_user' => $ciUser,
    'ci_pdo_error' => $pdoError,
    'ci_mysqli' => extension_loaded('mysqli'),
    'ci_db_driver' => $driver,
    'ci_http_body' => $httpBody,
    'ci_ospos' => is_file('/app/app/Config/OSPOS.php'),
]);
PHP;
    }
}
