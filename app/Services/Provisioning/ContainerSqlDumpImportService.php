<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Http\UploadedFile;

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
     * Rewrite a dump so the mysql CLI can import it from stdin inside the db container.
     * Each statement is one physical line: a line starting with `\` is never a client command.
     */
    public function mysqlClientDump(string $sql): string
    {
        $sql = $this->sanitizeDumpForSidecar($sql);
        $this->assertSafeSqlImport($sql);

        $statements = $this->splitSqlStatements($sql);
        $lines = ['SET NAMES utf8mb4'];
        foreach ($statements as $statement) {
            $flat = $this->flattenSqlStatementForMysqlClient($statement);
            if ($flat !== '') {
                $lines[] = $flat;
            }
        }

        if (count($lines) < 2) {
            throw new \RuntimeException('SQL dump contained no executable statements after cleanup.');
        }

        return implode(";\n", $lines).";\n";
    }

    public function rewriteLocalDumpForMysqlClient(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('SQL dump file is missing or empty.');
        }

        if (file_put_contents($path, $this->mysqlClientDump($sql)) === false) {
            throw new \RuntimeException('Could not rewrite the SQL dump for the MySQL client.');
        }
    }

    /**
     * Put one statement on one line without changing string data except encoding real newlines as \n.
     */
    public function flattenSqlStatementForMysqlClient(string $sql): string
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $out = '';
        $length = strlen($sql);
        $i = 0;
        $state = 'code';

        while ($i < $length) {
            $ch = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'code') {
                if ($ch === '#' || ($ch === '-' && $next === '-')) {
                    $state = 'linecomment';
                    $i++;

                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $state = 'blockcomment';
                    $out .= '/*';
                    $i += 2;

                    continue;
                }
                if ($ch === "'") {
                    $state = 'single';
                    $out .= $ch;
                    $i++;

                    continue;
                }
                if ($ch === '"') {
                    $state = 'double';
                    $out .= $ch;
                    $i++;

                    continue;
                }
                if ($ch === '`') {
                    $state = 'backtick';
                    $out .= $ch;
                    $i++;

                    continue;
                }
                if ($ch === "\n" || $ch === "\t") {
                    if ($out !== '' && ! str_ends_with($out, ' ')) {
                        $out .= ' ';
                    }
                    $i++;

                    continue;
                }
                $out .= $ch;
                $i++;

                continue;
            }

            if ($state === 'single' || $state === 'double') {
                $quote = $state === 'single' ? "'" : '"';
                if ($ch === "\n") {
                    $out .= '\\n';
                    $i++;

                    continue;
                }
                if ($ch === '\\') {
                    if ($next === "\n") {
                        $out .= '\\n';
                        $i += 2;

                        continue;
                    }
                    $out .= $ch.$next;
                    $i += $next === '' ? 1 : 2;

                    continue;
                }
                if ($ch === $quote && $next === $quote) {
                    $out .= $quote.$quote;
                    $i += 2;

                    continue;
                }
                if ($ch === $quote) {
                    $state = 'code';
                }
                $out .= $ch;
                $i++;

                continue;
            }

            if ($state === 'backtick') {
                if ($ch === '`' && $next === '`') {
                    $out .= '``';
                    $i += 2;

                    continue;
                }
                if ($ch === '`') {
                    $state = 'code';
                }
                if ($ch === "\n" || $ch === "\t") {
                    $out .= ' ';
                    $i++;

                    continue;
                }
                $out .= $ch;
                $i++;

                continue;
            }

            if ($state === 'linecomment') {
                if ($ch === "\n") {
                    $state = 'code';
                    if ($out !== '' && ! str_ends_with($out, ' ')) {
                        $out .= ' ';
                    }
                }
                $i++;

                continue;
            }

            if ($ch === '*' && $next === '/') {
                $out .= '*/';
                $i += 2;
                $state = 'code';

                continue;
            }
            if ($ch === "\n" || $ch === "\t") {
                if (! str_ends_with($out, ' ')) {
                    $out .= ' ';
                }
                $i++;

                continue;
            }
            $out .= $ch;
            $i++;
        }

        return trim($out);
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
            file_put_contents($localDump, $this->postgresClientDump($sql));
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
        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $rootPassword = (string) ($env['MYSQL_ROOT_PASSWORD'] ?? '');

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

        // mysql runs inside the db container over the unix socket (root@localhost).
        // Connecting from the app container 1045s: official images reject root@<overlay-ip>
        // and often the app user@<overlay-ip> until GRANT % is applied after import.
        $importUser = $rootPassword !== '' ? 'root' : $user;
        $importPass = $rootPassword !== '' ? $rootPassword : $password;
        if ($importPass === '') {
            throw new \RuntimeException(
                'Database password is missing from the sidecar. Repair DB credentials, then retry the import.'
            );
        }

        $clientSql = $this->mysqlClientDump($sql);
        $statementCount = max(0, substr_count($clientSql, ";\n"));

        $importDir = $containerPath.'/.db-imports';
        $ssh->mkdirp($importDir);
        $localDump = tempnam(sys_get_temp_dir(), 'ts-sql-import-');
        if ($localDump === false) {
            throw new \RuntimeException('Could not create a temporary file for the SQL dump.');
        }
        $localDump .= '.sql';
        if (file_put_contents($localDump, $clientSql) === false) {
            throw new \RuntimeException('Could not write the SQL dump to a temporary file.');
        }

        $remotePath = $importDir.'/import_'.time().'_'.bin2hex(random_bytes(4)).'.sql';

        try {
            $ssh->uploadFromLocal($localDump, $remotePath);
            $this->migrator->waitForComposeMysql(
                $ssh,
                $containerPath,
                $dbService,
                $importPass,
                180,
                $importUser,
                false,
            );

            $output = $ssh->exec(
                $this->migrator->buildMysqlDumpImportCommand(
                    $containerPath,
                    $dbService,
                    $remotePath,
                    $importUser,
                    $importPass,
                    $database,
                ),
                600
            );

            $grantWarning = $this->grantAppUserAfterImport(
                $ssh,
                $containerPath,
                $dbService,
                $database,
                $user,
                $password,
                $rootPassword !== '' ? $rootPassword : $importPass,
            );

            $rewriteWarning = $this->rewriteAppDatabaseConfigAfterImport(
                $ssh,
                $deployment,
                $database,
                $user,
                $password,
            );

            $message = 'Imported '.$statementCount.' SQL statements into '.$database
                .' as '.$importUser.' via the sidecar mysql client.';
            if ($grantWarning !== null) {
                $message .= ' '.$grantWarning;
            }
            if ($rewriteWarning !== null) {
                $message .= ' '.$rewriteWarning;
            }
            if (trim($output) !== '') {
                $message .= ' '.$output;
            }

            return $message;
        } finally {
            @unlink($localDump);
            try {
                $ssh->exec('rm -f '.escapeshellarg($remotePath), 10);
            } catch (\Throwable) {
            }
        }
    }

    private function grantAppUserAfterImport(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $database,
        string $user,
        string $password,
        string $rootPassword,
    ): ?string {
        $user = trim($user);
        if ($user === '' || strcasecmp($user, 'root') === 0 || $password === '' || $rootPassword === '') {
            return null;
        }

        try {
            app(ContainerDeploymentService::class)->syncMysqlSidecarCredentials($ssh, $containerPath, [
                'DB_HOST' => $dbService,
                'DB_DATABASE' => $database,
                'DB_USERNAME' => $user,
                'DB_PASSWORD' => $password,
                'MYSQL_ROOT_PASSWORD' => $rootPassword,
                'MYSQL_DATABASE' => $database,
                'MYSQL_USER' => $user,
                'MYSQL_PASSWORD' => $password,
            ]);
        } catch (\Throwable $e) {
            return 'Dump loaded, but the application user could not be granted from Docker IPs: '
                .$e->getMessage();
        }

        return null;
    }

    private function rewriteAppDatabaseConfigAfterImport(
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $database,
        string $user,
        string $password,
    ): ?string {
        $deployment->loadMissing('service');
        $service = $deployment->service;
        if (! $service instanceof Service || $password === '' || strcasecmp($user, 'root') === 0) {
            return null;
        }

        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $env['DB_DATABASE'] = $database;
        $env['MYSQL_DATABASE'] = $database;
        $env['DB_USERNAME'] = $user;
        $env['MYSQL_USER'] = $user;
        $env['DB_PASSWORD'] = $password;
        $env['MYSQL_PASSWORD'] = $password;
        $deployment->env_values = $env;

        try {
            $changed = app(PhpSidecarDatabaseRewriter::class)->applyForDeployment($ssh, $service, $deployment);
        } catch (\Throwable $e) {
            return 'Dump loaded, but application config still names the old DirectAdmin database: '.$e->getMessage();
        }

        if ($changed > 0) {
            return 'Rewrote '.$changed.' application file(s) to use this sidecar. Reload the site — do not Reset database.';
        }

        return null;
    }

    /**
     * Rewrite a pg_dump so it loads as the sidecar's own role.
     *
     * A dump taken on a laptop names the role that owned the objects there.
     * The sidecar has never heard of it, so the first `ALTER TABLE ... OWNER TO`
     * raises "role does not exist", and psql stops on first error, which loses
     * the whole import over a line that changes no data. This is what
     * `pg_dump --no-owner --no-privileges` would have written; doing it here
     * means the customer does not have to know that flag exists.
     */
    public function postgresClientDump(string $sql): string
    {
        $rewritten = preg_replace(
            [
                '/^\s*ALTER\s+[^;\n]*\bOWNER\s+TO\b[^;\n]*;\s*$/im',
                '/^\s*(?:SET|RESET)\s+SESSION\s+AUTHORIZATION\b[^;\n]*;\s*$/im',
            ],
            '',
            $sql
        );

        return is_string($rewritten) ? $rewritten : $sql;
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
