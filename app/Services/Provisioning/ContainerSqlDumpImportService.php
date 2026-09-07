<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

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
