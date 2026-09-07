<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

/**
 * DirectAdmin PHP apps often ignore .env and keep localhost / the old schema name
 * in config.php. Doctor PDO can look healthy while GET / 500s.
 */
class PhpSidecarDatabaseRewriter
{
    /**
     * @param  array{host: string, database: string, username: string, password: string, port?: string}  $credentials
     * @param  list<string>  $oldDatabaseNames
     * @param  list<string>  $oldUsernames
     */
    public function rewritePhpSource(
        string $source,
        array $credentials,
        array $oldDatabaseNames = [],
        array $oldUsernames = [],
    ): string {
        $host = $this->phpSingleQuoted($credentials['host']);
        $database = $this->phpSingleQuoted($credentials['database']);
        $username = $this->phpSingleQuoted($credentials['username']);
        $password = $this->phpSingleQuoted($credentials['password']);
        $port = $this->phpSingleQuoted((string) ($credentials['port'] ?? '3306'));

        $replacements = [
            '/define\s*\(\s*([\'"])DB_HOST\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_HOST\', '.$host.')',
            '/define\s*\(\s*([\'"])DB_SERVER\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_SERVER\', '.$host.')',
            '/define\s*\(\s*([\'"])DB_NAME\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_NAME\', '.$database.')',
            '/define\s*\(\s*([\'"])DB_DATABASE\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_DATABASE\', '.$database.')',
            '/define\s*\(\s*([\'"])DB_USER\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_USER\', '.$username.')',
            '/define\s*\(\s*([\'"])DB_USERNAME\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_USERNAME\', '.$username.')',
            '/define\s*\(\s*([\'"])DB_PASSWORD\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_PASSWORD\', '.$password.')',
            '/define\s*\(\s*([\'"])DB_PASS\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_PASS\', '.$password.')',
            '/define\s*\(\s*([\'"])DB_PORT\1\s*,\s*([\'"])(?:\\\\.|(?!\2).)*\2\s*\)/i' => 'define(\'DB_PORT\', '.$port.')',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $updated = preg_replace($pattern, $replacement, $source);
            if (is_string($updated)) {
                $source = $updated;
            }
        }

        $source = $this->replaceMysqlHostLiterals($source, $credentials['host']);
        $source = preg_replace(
            '/mysql:host=(?:127\.0\.0\.1|localhost|db|mysql)(?:\:\d+)?/i',
            'mysql:host='.$credentials['host'],
            $source
        ) ?? $source;
        $source = preg_replace(
            '/mysql:unix_socket=[^;\'"\s]+/i',
            'mysql:host='.$credentials['host'],
            $source
        ) ?? $source;

        foreach ($this->uniqueNonEmpty($oldDatabaseNames) as $old) {
            if (strcasecmp($old, $credentials['database']) === 0) {
                continue;
            }
            $source = str_ireplace('dbname='.$old, 'dbname='.$credentials['database'], $source);
            $source = str_replace("'".$old."'", $database, $source);
            $source = str_replace('"'.$old.'"', $database, $source);
            $source = str_replace('`'.$old.'`', '`'.$credentials['database'].'`', $source);
        }

        foreach ($this->uniqueNonEmpty($oldUsernames) as $old) {
            if (strcasecmp($old, $credentials['username']) === 0 || strcasecmp($old, 'root') === 0) {
                continue;
            }
            $source = preg_replace(
                '/(mysqli?(?:_connect)?\s*\(\s*)([\'"])'.preg_quote($credentials['host'], '/').'\2\s*,\s*\2'.preg_quote($old, '/').'\2/i',
                '$1'.$host.', '.$username,
                $source
            ) ?? $source;
        }

        return $source;
    }

    /**
     * @param  array{host: string, database: string, username: string, password: string, port?: string}  $credentials
     */
    public function rewriteEnv(string $text, array $credentials): string
    {
        $text = (string) preg_replace('/^(DB_SOCKET|MYSQL_UNIX_SOCKET)=.*\n?/m', '', $text);

        $values = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $credentials['host'],
            'DB_PORT' => (string) ($credentials['port'] ?? '3306'),
            'DB_DATABASE' => $credentials['database'],
            'DB_USERNAME' => $credentials['username'],
            'DB_PASSWORD' => $credentials['password'],
            'MYSQL_HOST' => $credentials['host'],
            'MYSQL_DATABASE' => $credentials['database'],
            'MYSQL_USER' => $credentials['username'],
            'MYSQL_PASSWORD' => $credentials['password'],
        ];

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->encodeEnvValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match($pattern, $text) === 1) {
                $text = (string) preg_replace($pattern, $line, $text, 1);
            } else {
                $text = rtrim($text)."\n".$line."\n";
            }
        }

        return $text === '' || str_ends_with($text, "\n") ? $text : $text."\n";
    }

    /**
     * @param  array{host: string, database: string, username: string, password: string, port?: string}  $credentials
     * @param  list<string>  $oldDatabaseNames
     * @param  list<string>  $oldUsernames
     */
    public function applyOnHost(
        SSHService $ssh,
        string $hostAppPath,
        array $credentials,
        array $oldDatabaseNames = [],
        array $oldUsernames = [],
    ): int {
        $changed = 0;
        $envPath = rtrim($hostAppPath, '/').'/.env';
        try {
            $exists = trim($ssh->exec('test -f '.escapeshellarg($envPath).' && echo yes || echo no', 10));
            if ($exists === 'yes') {
                $original = $ssh->downloadFile($envPath);
                $updated = $this->rewriteEnv($original, $credentials);
                if ($updated !== $original) {
                    $ssh->upload($updated, $envPath);
                    $changed++;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $list = trim($ssh->exec(
                'find '.escapeshellarg($hostAppPath)
                .' -maxdepth 4 -type f \( -name \'*.php\' -o -name \'*.inc\' \)'
                .' ! -path \'*/vendor/*\' ! -path \'*/node_modules/*\' ! -path \'*/storage/*\''
                .' -size -1024k | head -n 80',
                30
            ));
        } catch (\Throwable) {
            return $changed;
        }

        foreach (preg_split('/\r\n|\r|\n/', $list) ?: [] as $path) {
            $path = trim($path);
            if ($path === '' || ! str_starts_with($path, rtrim($hostAppPath, '/'))) {
                continue;
            }
            try {
                $original = $ssh->downloadFile($path);
                $updated = $this->rewritePhpSource($original, $credentials, $oldDatabaseNames, $oldUsernames);
                if ($updated !== $original) {
                    $ssh->upload($updated, $path);
                    $changed++;
                }
            } catch (\Throwable) {
            }
        }

        return $changed;
    }

    public function applyForDeployment(SSHService $ssh, Service $service, ContainerDeployment $deployment): int
    {
        $deployments = app(ContainerDeploymentService::class);
        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $dbService = $deployments->resolveMysqlComposeServiceName($env);

        try {
            $live = app(DirectAdminToContainerMigrationService::class)
                ->readLiveMysqlSidecarEnv($ssh, $containerPath, $dbService);
            if (($live['MYSQL_PASSWORD'] ?? '') !== '') {
                $env['MYSQL_PASSWORD'] = $live['MYSQL_PASSWORD'];
                $env['DB_PASSWORD'] = $live['MYSQL_PASSWORD'];
            }
            if (($live['MYSQL_USER'] ?? '') !== '') {
                $env['MYSQL_USER'] = $live['MYSQL_USER'];
                $env['DB_USERNAME'] = $live['MYSQL_USER'];
            }
            if (($live['MYSQL_DATABASE'] ?? '') !== '') {
                $env['MYSQL_DATABASE'] = $live['MYSQL_DATABASE'];
                $env['DB_DATABASE'] = $live['MYSQL_DATABASE'];
            }
        } catch (\Throwable) {
        }

        $hostAppPath = $containerPath.'/app';
        try {
            $exists = trim($ssh->exec('test -f '.escapeshellarg($hostAppPath.'/.env').' && echo yes || echo no', 10));
            if ($exists === 'yes') {
                $parsed = $this->parseEnvAssignments($ssh->downloadFile($hostAppPath.'/.env'));
                foreach (['DB_PASSWORD', 'MYSQL_PASSWORD', 'DB_USERNAME', 'MYSQL_USER', 'DB_DATABASE', 'MYSQL_DATABASE'] as $key) {
                    if (($parsed[$key] ?? '') !== '') {
                        $env[$key] = $parsed[$key];
                    }
                }
            }
        } catch (\Throwable) {
        }

        $host = $deployments->sidecarDnsHost((string) $deployment->container_name);
        $credentials = [
            'host' => $host,
            'database' => (string) ($env['DB_DATABASE'] ?? $env['MYSQL_DATABASE'] ?? 'appdb'),
            'username' => (string) ($env['DB_USERNAME'] ?? $env['MYSQL_USER'] ?? 'appuser'),
            'password' => (string) ($env['DB_PASSWORD'] ?? $env['MYSQL_PASSWORD'] ?? ''),
            'port' => (string) ($env['DB_PORT'] ?? '3306'),
        ];
        if ($credentials['password'] === '' || $credentials['username'] === '' || strcasecmp($credentials['username'], 'root') === 0) {
            return 0;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $legacy = is_array($meta['da_legacy'] ?? null) ? $meta['da_legacy'] : [];
        $oldDatabases = array_values(array_filter(array_map(
            static fn ($row) => is_array($row) ? (string) ($row['name'] ?? '') : (string) $row,
            is_array($legacy['databases'] ?? null) ? $legacy['databases'] : []
        )));
        $oldUsers = array_values(array_filter([
            (string) ($legacy['username'] ?? ''),
        ]));

        return $this->applyOnHost($ssh, $hostAppPath, $credentials, $oldDatabases, $oldUsers);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueNonEmpty(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || isset($out[$value])) {
                continue;
            }
            $out[$value] = $value;
        }

        return array_values($out);
    }

    private function replaceMysqlHostLiterals(string $source, string $host): string
    {
        $quoted = $this->phpSingleQuoted($host);
        $patterns = [
            '/(new\s+mysqli\s*\(\s*)([\'"])(?:127\.0\.0\.1|localhost|db|mysql|(?:[^\'"]*mysql\.sock)[^\'"]*)\2/i',
            '/(mysqli_connect\s*\(\s*)([\'"])(?:127\.0\.0\.1|localhost|db|mysql|(?:[^\'"]*mysql\.sock)[^\'"]*)\2/i',
            '/(mysql_connect\s*\(\s*)([\'"])(?:127\.0\.0\.1|localhost|db|mysql|(?:[^\'"]*mysql\.sock)[^\'"]*)\2/i',
        ];
        foreach ($patterns as $pattern) {
            $updated = preg_replace($pattern, '$1'.$quoted, $source);
            if (is_string($updated)) {
                $source = $updated;
            }
        }

        return str_replace(
            [
                'localhost:/var/lib/mysql/mysql.sock',
                '/var/lib/mysql/mysql.sock',
            ],
            $host,
            $source
        );
    }

    private function phpSingleQuoted(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * @return array<string, string>
     */
    public function parseEnvAssignments(string $content): array
    {
        $env = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");
            if ($key !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    private function encodeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\$]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
