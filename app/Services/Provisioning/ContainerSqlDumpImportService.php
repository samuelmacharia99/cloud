<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Yaml\Yaml;

/**
 * Load an operator-uploaded .sql dump into this service's sidecar database.
 * DirectAdmin / mysqldump files often name their own schema; those statements
 * are stripped so tables land in the provisioned database (e.g. s426_db).
 */
class ContainerSqlDumpImportService
{
    public function __construct(
        private DirectAdminToContainerMigrationService $migrator,
    ) {}

    public function iniToBytes(?string $value): int
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === '0') {
            return 0;
        }

        $unit = $value[-1] ?? '';
        $number = (float) $value;
        $multiplier = match ($unit) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };

        return (int) round($number * ($unit !== '' && ! is_numeric($unit) ? $multiplier : 1));
    }

    public function phpUploadLimitBytes(): int
    {
        $upload = $this->iniToBytes((string) ini_get('upload_max_filesize'));
        $post = $this->iniToBytes((string) ini_get('post_max_size'));
        $limits = array_values(array_filter([$upload, $post], fn (int $bytes) => $bytes > 0));

        return $limits === [] ? 0 : min($limits);
    }

    public function phpUploadLimitLabel(): string
    {
        $bytes = $this->phpUploadLimitBytes();
        if ($bytes <= 0) {
            return 'unknown';
        }

        return (string) max(1, (int) floor($bytes / (1024 * 1024))).' MB';
    }

    public function describePhpUploadFailure(?UploadedFile $file): ?string
    {
        if (! $file || $file->isValid()) {
            return null;
        }

        $phpLimit = $this->phpUploadLimitLabel();

        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The SQL dump is larger than PHP allows on this panel ('
                .$phpLimit.'). The Database tab now uploads in small chunks — retry Import SQL. '
                .'If this persists, raise upload_max_filesize and post_max_size on the billing app.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Retry Import SQL.',
            UPLOAD_ERR_NO_FILE => 'Choose a .sql file to import.',
            UPLOAD_ERR_NO_TMP_DIR => 'The panel has no PHP upload temp directory.',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default => 'The file failed to upload (PHP error '.$file->getError().').',
        };
    }

    /**
     * @return array{complete: bool, received: int, total: int, path?: string}
     */
    public function storeChunk(int $serviceId, string $uploadId, int $index, int $total, UploadedFile $file): array
    {
        if ($total < 1 || $index < 0 || $index >= $total) {
            throw new \InvalidArgumentException('Invalid SQL upload chunk.');
        }

        $dir = $this->chunkDirectory($serviceId, $uploadId);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create a temporary directory for the SQL upload.');
        }

        $file->move($dir, $index.'.part');
        if (! is_file($dir.'/'.$index.'.part')) {
            throw new \RuntimeException('Could not store SQL upload chunk '.$index.'.');
        }

        $received = 0;
        for ($i = 0; $i < $total; $i++) {
            if (is_file($dir.'/'.$i.'.part')) {
                $received++;
            }
        }

        if ($received < $total) {
            return ['complete' => false, 'received' => $received, 'total' => $total];
        }

        $assembled = $dir.'/assembled.sql';
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not assemble the SQL dump.');
        }
        try {
            for ($i = 0; $i < $total; $i++) {
                $in = fopen($dir.'/'.$i.'.part', 'rb');
                if ($in === false) {
                    throw new \RuntimeException('Missing SQL upload chunk '.$i.'.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        return [
            'complete' => true,
            'received' => $received,
            'total' => $total,
            'path' => $assembled,
        ];
    }

    public function forgetUpload(int $serviceId, string $uploadId): void
    {
        $dir = $this->chunkDirectory($serviceId, $uploadId);
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($dir);
    }

    private function chunkDirectory(int $serviceId, string $uploadId): string
    {
        return storage_path('app/db-import-chunks/'.$serviceId.'/'.$uploadId);
    }

    public function sanitizeDumpForSidecar(string $sql): string
    {
        // Only strip standalone statements. Greedy \bGRANT\b[^;]*; ate INSERT rows
        // that mentioned GRANT and left a line starting with `\`, which mysql
        // reports as: Unknown command '\\'.
        $stripped = preg_replace(
            [
                '/^\s*CREATE\s+DATABASE\b[^;]*;/ims',
                '/^\s*DROP\s+DATABASE\b[^;]*;/im',
                '/^\s*DROP\s+SCHEMA\b[^;]*;/im',
                '/^\s*CREATE\s+USER\b[^;]*;/im',
                '/^\s*ALTER\s+USER\b[^;]*;/im',
                '/^\s*GRANT\b[^;]*;/im',
                '/^\s*REVOKE\b[^;]*;/im',
                '/^\s*USE\s+[`\'"]?[A-Za-z0-9_\-]+[`\'"]?\s*;/im',
                '/\sDEFINER=(?:`[^`]+`|\'[^\']+\')@(?:`[^`]+`|\'[^\']+\')/i',
            ],
            '',
            $sql
        );

        return is_string($stripped) ? $stripped : $sql;
    }

    public function assertSafeSqlImport(string $sql): void
    {
        if (preg_match('/^\s*(CREATE|DROP)\s+DATABASE\b/im', $sql)
            || preg_match('/^\s*DROP\s+SCHEMA\b/im', $sql)) {
            throw new \InvalidArgumentException(
                'SQL file still contains a standalone CREATE/DROP DATABASE statement after cleanup. Remove it and try again.'
            );
        }
    }

    /**
     * @return list<string>
     */
    public function splitSqlStatements(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;
        $state = 'code';
        $delimiter = ';';

        while ($i < $length) {
            $ch = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'code') {
                if ($this->startsDelimiterKeyword($sql, $i)) {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    $i += 9;
                    while ($i < $length && ctype_space($sql[$i])) {
                        $i++;
                    }
                    $new = '';
                    while ($i < $length && $sql[$i] !== "\n") {
                        $new .= $sql[$i];
                        $i++;
                    }
                    $delimiter = trim($new) !== '' ? trim($new) : ';';
                    if ($i < $length && $sql[$i] === "\n") {
                        $i++;
                    }

                    continue;
                }

                if ($ch === '#' || ($ch === '-' && $next === '-')) {
                    $state = 'linecomment';
                    $current .= $ch;
                    $i++;

                    continue;
                }

                if ($ch === '/' && $next === '*') {
                    $state = 'blockcomment';
                    $current .= $ch.$next;
                    $i += 2;

                    continue;
                }

                if ($ch === "'") {
                    $state = 'single';
                    $current .= $ch;
                    $i++;

                    continue;
                }

                if ($ch === '"') {
                    $state = 'double';
                    $current .= $ch;
                    $i++;

                    continue;
                }

                if ($ch === '`') {
                    $state = 'backtick';
                    $current .= $ch;
                    $i++;

                    continue;
                }

                $delimLen = strlen($delimiter);
                if ($delimLen > 0 && substr($sql, $i, $delimLen) === $delimiter) {
                    $trimmed = trim($current);
                    if ($trimmed !== '' && ! preg_match('/^DELIMITER\b/i', $trimmed)) {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    $i += $delimLen;

                    continue;
                }

                $current .= $ch;
                $i++;

                continue;
            }

            if ($state === 'single') {
                if ($ch === '\\') {
                    $current .= $ch.$next;
                    $i += $next === '' ? 1 : 2;

                    continue;
                }
                if ($ch === "'" && $next === "'") {
                    $current .= "''";
                    $i += 2;

                    continue;
                }
                if ($ch === "'") {
                    $state = 'code';
                }
                $current .= $ch;
                $i++;

                continue;
            }

            if ($state === 'double') {
                if ($ch === '\\') {
                    $current .= $ch.$next;
                    $i += $next === '' ? 1 : 2;

                    continue;
                }
                if ($ch === '"' && $next === '"') {
                    $current .= '""';
                    $i += 2;

                    continue;
                }
                if ($ch === '"') {
                    $state = 'code';
                }
                $current .= $ch;
                $i++;

                continue;
            }

            if ($state === 'backtick') {
                if ($ch === '`' && $next === '`') {
                    $current .= '``';
                    $i += 2;

                    continue;
                }
                if ($ch === '`') {
                    $state = 'code';
                }
                $current .= $ch;
                $i++;

                continue;
            }

            if ($state === 'linecomment') {
                $current .= $ch;
                if ($ch === "\n") {
                    $state = 'code';
                }
                $i++;

                continue;
            }

            $current .= $ch;
            if ($ch === '*' && $next === '/') {
                $current .= $next;
                $i += 2;
                $state = 'code';

                continue;
            }
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '' && ! preg_match('/^DELIMITER\b/i', $trimmed)) {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private function startsDelimiterKeyword(string $sql, int $i): bool
    {
        if (strncasecmp(substr($sql, $i, 9), 'DELIMITER', 9) !== 0) {
            return false;
        }

        if ($i > 0 && ! ctype_space($sql[$i - 1]) && $sql[$i - 1] !== "\n") {
            return false;
        }

        $after = $sql[$i + 9] ?? ' ';

        return $after === '' || ctype_space($after);
    }

    /**
     * @param  array{type: string, service?: string, database?: string, username?: string, password?: string}  $databaseContext
     */
    public function importIntoSidecar(
        SSHService $ssh,
        ContainerDeployment $deployment,
        array $databaseContext,
        string $sql,
    ): string {
        $sql = $this->sanitizeDumpForSidecar($sql);
        $this->assertSafeSqlImport($sql);

        if (trim($sql) === '') {
            throw new \InvalidArgumentException('SQL file is empty after removing database-level statements.');
        }

        $containerPath = '/opt/talksasa/containers/'.$deployment->container_name;
        $dbType = (string) ($databaseContext['type'] ?? '');

        if (in_array($dbType, ['mysql', 'mariadb'], true)) {
            return $this->importMysql($ssh, $deployment, $databaseContext, $containerPath, $sql);
        }

        if ($dbType === 'postgresql') {
            $importDir = $containerPath.'/.db-imports';
            $ssh->mkdirp($importDir);
            $localDump = tempnam(sys_get_temp_dir(), 'ts-sql-import-');
            if ($localDump === false) {
                throw new \RuntimeException('Could not create a temporary file for the SQL dump.');
            }
            $localDump .= '.sql';
            file_put_contents($localDump, $sql);
            $remotePath = $importDir.'/import_'.time().'_'.bin2hex(random_bytes(4)).'.sql';
            try {
                $ssh->uploadFromLocal($localDump, $remotePath);

                return $this->importPostgres($ssh, $deployment, $databaseContext, $containerPath, $remotePath);
            } finally {
                @unlink($localDump);
                try {
                    $ssh->exec('rm -f '.escapeshellarg($remotePath), 10);
                } catch (\Throwable) {
                }
            }
        }

        throw new \RuntimeException('SQL import is not supported for this database type');
    }

    /**
     * @param  array<string, mixed>  $databaseContext
     */
    private function importMysql(
        SSHService $ssh,
        ContainerDeployment $deployment,
        array $databaseContext,
        string $containerPath,
        string $sql,
    ): string {
        $dbService = (string) ($databaseContext['service'] ?? 'db');
        $database = (string) ($databaseContext['database'] ?? 'appdb');
        $user = (string) ($databaseContext['username'] ?? 'appuser');
        $password = (string) ($databaseContext['password'] ?? '');
        $rootPassword = (string) ($deployment->env_values['MYSQL_ROOT_PASSWORD'] ?? '');

        try {
            $live = $this->migrator->readLiveMysqlSidecarEnv($ssh, $containerPath, $dbService);
            if (($live['MYSQL_PASSWORD'] ?? '') !== '') {
                $password = $live['MYSQL_PASSWORD'];
            }
            if (($live['MYSQL_USER'] ?? '') !== '') {
                $user = $live['MYSQL_USER'];
            }
            if (($live['MYSQL_DATABASE'] ?? '') !== '') {
                $database = $live['MYSQL_DATABASE'];
            }
            if (($live['MYSQL_ROOT_PASSWORD'] ?? '') !== '') {
                $rootPassword = $live['MYSQL_ROOT_PASSWORD'];
            }
        } catch (\Throwable) {
        }

        $importUser = $rootPassword !== '' ? 'root' : $user;
        $importPass = $rootPassword !== '' ? $rootPassword : $password;
        if ($importPass === '') {
            throw new \RuntimeException(
                'Database password is missing from the sidecar. Repair DB credentials, then retry the import.'
            );
        }

        $statements = $this->splitSqlStatements($sql);
        if ($statements === []) {
            throw new \RuntimeException('SQL dump contained no executable statements after cleanup.');
        }

        $appRoot = $containerPath.'/app';
        $ssh->mkdirp($appRoot);
        $localJsonl = tempnam(sys_get_temp_dir(), 'ts-sql-jsonl-');
        if ($localJsonl === false) {
            throw new \RuntimeException('Could not create a temporary file for SQL statements.');
        }
        $handle = fopen($localJsonl, 'wb');
        if ($handle === false) {
            @unlink($localJsonl);
            throw new \RuntimeException('Could not write SQL statements for import.');
        }
        try {
            foreach ($statements as $statement) {
                fwrite($handle, json_encode($statement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            }
        } finally {
            fclose($handle);
        }

        $token = bin2hex(random_bytes(4));
        $remoteJsonl = $appRoot.'/.talksasa-import-'.$token.'.jsonl';
        $remotePhp = $appRoot.'/.talksasa-import-'.$token.'.php';
        $localPhp = $localJsonl.'.php';
        file_put_contents($localPhp, $this->pdoDumpImporterScript());

        try {
            $ssh->uploadFromLocal($localJsonl, $remoteJsonl);
            $ssh->uploadFromLocal($localPhp, $remotePhp);
            $appService = $this->composeAppService($ssh, $deployment, $containerPath);

            $command = 'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T'
                .' -e TS_IMPORT_HOST='.escapeshellarg($dbService)
                .' -e TS_IMPORT_DB='.escapeshellarg($database)
                .' -e TS_IMPORT_USER='.escapeshellarg($importUser)
                .' -e TS_IMPORT_PASS='.escapeshellarg($importPass)
                .' '.escapeshellarg($appService)
                .' php '.escapeshellarg('/app/.talksasa-import-'.$token.'.php')
                .' '.escapeshellarg('/app/.talksasa-import-'.$token.'.jsonl');

            return $ssh->exec($command, 600);
        } finally {
            @unlink($localJsonl);
            @unlink($localPhp);
            try {
                $ssh->exec(
                    'rm -f '.escapeshellarg($remoteJsonl).' '.escapeshellarg($remotePhp),
                    10
                );
            } catch (\Throwable) {
            }
        }
    }

    public function pdoDumpImporterScript(): string
    {
        return <<<'PHP'
<?php
$host = (string) getenv('TS_IMPORT_HOST');
$db = (string) getenv('TS_IMPORT_DB');
$user = (string) getenv('TS_IMPORT_USER');
$pass = (string) getenv('TS_IMPORT_PASS');
$file = $argv[1] ?? '';
if ($host === '' || $db === '' || $user === '' || $file === '' || ! is_file($file)) {
    fwrite(STDERR, "Import script is missing database settings or the statement file.\n");
    exit(1);
}
$pdo = new PDO(
    'mysql:host='.$host.';port=3306;dbname='.$db.';charset=utf8mb4',
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET NAMES utf8mb4');
$handle = fopen($file, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Could not read import statements.\n");
    exit(1);
}
$count = 0;
try {
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $statement = json_decode($line, true);
        if (! is_string($statement) || trim($statement) === '') {
            throw new RuntimeException('Invalid statement payload at statement '.($count + 1));
        }
        $pdo->exec($statement);
        $count++;
    }
} finally {
    fclose($handle);
}
fwrite(STDOUT, 'Imported '.$count." SQL statements via PDO.\n");
PHP;
    }

    private function composeAppService(
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $containerPath,
    ): string {
        try {
            $yaml = trim($ssh->exec('cat '.escapeshellarg($containerPath.'/docker-compose.yml'), 15));
            $compose = Yaml::parse($yaml);
            if (is_array($compose)) {
                $key = app(ContainerDeploymentService::class)
                    ->resolveComposeAppServiceKey($compose, $deployment->container_name);
                if (is_string($key) && $key !== '') {
                    return $key;
                }
            }
        } catch (\Throwable) {
        }

        return $deployment->container_name;
    }

    /**
     * @param  array<string, mixed>  $databaseContext
     */
    private function importPostgres(
        SSHService $ssh,
        ContainerDeployment $deployment,
        array $databaseContext,
        string $containerPath,
        string $remotePath,
    ): string {
        $db = escapeshellarg((string) ($databaseContext['database'] ?? 'appdb'));
        $user = escapeshellarg((string) ($databaseContext['username'] ?? 'appuser'));
        $password = escapeshellarg((string) (
            $databaseContext['password']
            ?? $deployment->env_values['DB_PASSWORD']
            ?? $deployment->env_values['POSTGRES_PASSWORD']
            ?? ''
        ));
        $remoteArg = escapeshellarg($remotePath);

        $command = "cd {$containerPath} && cat {$remoteArg} | docker compose exec -T -e PGPASSWORD={$password} db "
            ."psql -v ON_ERROR_STOP=1 -U {$user} -d {$db}";

        return $ssh->exec($command, 600);
    }
}
