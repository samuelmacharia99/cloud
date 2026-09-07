<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
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
        $stripped = preg_replace(
            [
                '/\bCREATE\s+DATABASE\b[^;]*;/is',
                '/\bDROP\s+DATABASE\b[^;]*;/is',
                '/\bDROP\s+SCHEMA\b[^;]*;/is',
                '/\bCREATE\s+USER\b[^;]*;/is',
                '/\bALTER\s+USER\b[^;]*;/is',
                '/\bGRANT\b[^;]*;/is',
                '/\bREVOKE\b[^;]*;/is',
                '/\bUSE\s+[`\'"]?[A-Za-z0-9_\-]+[`\'"]?\s*;/i',
                '/\sDEFINER=(?:`[^`]+`|\'[^\']+\')@(?:`[^`]+`|\'[^\']+\')/i',
            ],
            '',
            $sql
        );

        return is_string($stripped) ? $stripped : $sql;
    }

    public function assertSafeSqlImport(string $sql): void
    {
        $blocked = [
            '/\bDROP\s+DATABASE\b/i',
            '/\bCREATE\s+DATABASE\b/i',
            '/\bDROP\s+SCHEMA\b/i',
            '/\bGRANT\s+/i',
            '/\bREVOKE\s+/i',
        ];

        foreach ($blocked as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw new \InvalidArgumentException(
                    'SQL file still contains database-level privilege or schema statements after cleanup. Remove them and try again.'
                );
            }
        }
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
        $importDir = $containerPath.'/.db-imports';
        $ssh->mkdirp($importDir);

        $localDump = tempnam(sys_get_temp_dir(), 'ts-sql-import-');
        if ($localDump === false) {
            throw new \RuntimeException('Could not create a temporary file for the SQL dump.');
        }
        $localDump .= '.sql';
        if (file_put_contents($localDump, $sql) === false) {
            throw new \RuntimeException('Could not write the SQL dump to a temporary file.');
        }

        $remotePath = $importDir.'/import_'.time().'_'.bin2hex(random_bytes(4)).'.sql';

        try {
            $ssh->uploadFromLocal($localDump, $remotePath);

            $dbType = (string) ($databaseContext['type'] ?? '');
            if (in_array($dbType, ['mysql', 'mariadb'], true)) {
                return $this->importMysql($ssh, $deployment, $databaseContext, $containerPath, $remotePath);
            }

            if ($dbType === 'postgresql') {
                return $this->importPostgres($ssh, $deployment, $databaseContext, $containerPath, $remotePath);
            }

            throw new \RuntimeException('SQL import is not supported for this database type');
        } finally {
            @unlink($localDump);
            try {
                $ssh->exec('rm -f '.escapeshellarg($remotePath), 10);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param  array<string, mixed>  $databaseContext
     */
    private function importMysql(
        SSHService $ssh,
        ContainerDeployment $deployment,
        array $databaseContext,
        string $containerPath,
        string $remotePath,
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

        $command = $this->migrator->buildMysqlDumpImportCommand(
            $containerPath,
            $dbService,
            $remotePath,
            $importUser,
            $importPass,
            $database,
        );

        return $ssh->exec($command, 600);
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
