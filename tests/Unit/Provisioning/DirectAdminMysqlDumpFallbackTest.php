<?php

namespace Tests\Unit\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\SSH\SSHService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DirectAdminMysqlDumpFallbackTest extends TestCase
{
    private const SITE_CREDS = [
        'DB_NAME' => 'reelmagic_wp',
        'DB_USER' => 'reelmagic_wp_reelmagicflies_com',
        'DB_PASSWORD' => 'site-secret',
        'DB_HOST' => 'localhost',
    ];

    public function test_it_recognises_why_mysql_refused_the_site_user(): void
    {
        $locked = "SSH command failed: mysqldump --defaults-extra-file='/opt/x/mysqldump.cnf' 'db' > '/opt/x/db.sql'\n"
            ."Error: Command exited with status 2\n"
            ."Output: mysqldump: Got error: 3118: Access denied for user 'reelmagic_wp'@'localhost'. Account is locked. when trying to connect";

        $this->assertSame('account is locked', DirectAdminToContainerMigrationService::describeMysqlUserRefusal($locked));
        $this->assertSame('access denied', DirectAdminToContainerMigrationService::describeMysqlUserRefusal(
            "mysqldump: Got error: 1045: Access denied for user 'wp'@'localhost' (using password: YES) when trying to connect"
        ));
        $this->assertSame('account is blocked after failed logins', DirectAdminToContainerMigrationService::describeMysqlUserRefusal(
            "mysqldump: Got error: 3955: Access denied for user 'wp'@'localhost'. Account is blocked for 1 day(s) (1 day(s) remaining) due to 3 consecutive failed logins."
        ));
        $this->assertNull(DirectAdminToContainerMigrationService::describeMysqlUserRefusal(
            "mysqldump: Got error: 1049: Unknown database 'reelmagic_wp' when trying to connect"
        ));
        $this->assertNull(DirectAdminToContainerMigrationService::describeMysqlUserRefusal('Connection timed out'));
    }

    public function test_a_locked_site_user_falls_back_to_the_directadmin_admin_mysql_user(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $commands = [];
        $ssh = $this->sshRefusingSiteUser($commands, "user=da_admin\npasswd=admin-secret\nhost=localhost\n");
        $notes = [];

        $used = $migrator->dumpDatabaseOrFallBackToDirectAdminAdmin(
            $ssh,
            self::SITE_CREDS,
            'reelmagic_wp',
            '/opt/work/db.sql',
            '/opt/work/mysqldump.cnf',
            function (string $message) use (&$notes): void {
                $notes[] = $message;
            },
        );

        $this->assertSame('admin', $used);

        $adminDumps = array_values(array_filter($commands, fn (string $c) => str_starts_with($c, 'mysqldump') && str_contains($c, "mysqldump.cnf.admin'")));
        $this->assertCount(1, $adminDumps);
        $this->assertStringContainsString("'reelmagic_wp' > '/opt/work/db.sql'", $adminDumps[0]);

        $defaultsWrites = array_values(array_filter($commands, fn (string $c) => str_contains($c, 'base64 -d') && str_contains($c, "mysqldump.cnf.admin'")));
        $this->assertCount(1, $defaultsWrites);
        preg_match("/echo '([^']+)' \\| base64 -d/", $defaultsWrites[0], $m);
        $cnf = base64_decode($m[1] ?? '', true);
        $this->assertStringContainsString('user="da_admin"', (string) $cnf);
        $this->assertStringContainsString('password="admin-secret"', (string) $cnf);

        $this->assertContains("rm -f '/opt/work/mysqldump.cnf.admin'", $commands);
        $this->assertCount(1, array_filter($notes, fn (string $n) => str_contains($n, 'account is locked') && str_contains($n, 'DirectAdmin admin MySQL user')));
    }

    public function test_a_locked_site_user_without_readable_admin_credentials_says_what_to_do(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $commands = [];
        $ssh = $this->sshRefusingSiteUser($commands, '');

        try {
            $migrator->dumpDatabaseOrFallBackToDirectAdminAdmin($ssh, self::SITE_CREDS, 'reelmagic_wp', '/opt/work/db.sql', '/opt/work/mysqldump.cnf');
            $this->fail('expected the export to stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('reelmagic_wp_reelmagicflies_com', $e->getMessage());
            $this->assertStringContainsString('account is locked', $e->getMessage());
            $this->assertStringContainsString('Unsuspend the DirectAdmin account', $e->getMessage());
            $this->assertInstanceOf(SSHCommandException::class, $e->getPrevious());
        }

        $this->assertSame([], array_filter($commands, fn (string $c) => str_contains($c, 'mysqldump.cnf.admin')));
    }

    public function test_other_dump_failures_are_not_retried_as_admin(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $commands = [];
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands): string {
            $commands[] = $command;
            if (str_starts_with($command, 'mysqldump')) {
                throw new SSHCommandException($command, "mysqldump: Got error: 1049: Unknown database 'reelmagic_wp' when trying to connect", 'Command exited with status 2');
            }

            return '';
        });

        $this->expectException(SSHCommandException::class);
        $this->expectExceptionMessage('Unknown database');
        try {
            $migrator->dumpDatabaseOrFallBackToDirectAdminAdmin($ssh, self::SITE_CREDS, 'reelmagic_wp', '/opt/work/db.sql', '/opt/work/mysqldump.cnf');
        } finally {
            $this->assertCount(1, $commands, 'no mysql.conf read, no admin retry');
        }
    }

    /**
     * @param  list<string>  $commands
     */
    private function sshRefusingSiteUser(array &$commands, string $mysqlConf): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $mysqlConf): string {
            $commands[] = $command;
            if (str_starts_with($command, 'mysqldump') && ! str_contains($command, 'mysqldump.cnf.admin')) {
                throw new SSHCommandException(
                    $command,
                    "mysqldump: Got error: 3118: Access denied for user 'reelmagic_wp_reelmagicflies_com'@'localhost'. Account is locked. when trying to connect",
                    'Command exited with status 2',
                );
            }
            if (str_contains($command, '/usr/local/directadmin/conf/mysql.conf')) {
                return $mysqlConf;
            }

            return '';
        });

        return $ssh;
    }
}
