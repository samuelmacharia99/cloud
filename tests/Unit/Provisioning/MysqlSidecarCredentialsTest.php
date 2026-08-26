<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MysqlSidecarCredentialsTest extends TestCase
{
    #[Test]
    public function privilege_repair_sql_drops_shadow_hosts_and_recreates_percent_and_localhost_only(): void
    {
        $sql = app(ContainerDeploymentService::class)->mysqlPrivilegeRepairSql(
            's24_db',
            'u74_s24',
            'secret',
            ['10.201.0.26'],
        );

        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `s24_db`', $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'10.201.0.26'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'10.%'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'172.%'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'%'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'localhost'", $sql);
        $this->assertStringContainsString("CREATE USER 'u74_s24'@'%' IDENTIFIED BY 'secret'", $sql);
        $this->assertStringContainsString("CREATE USER 'u74_s24'@'localhost' IDENTIFIED BY 'secret'", $sql);
        $this->assertStringContainsString("ALTER USER 'u74_s24'@'%' IDENTIFIED BY 'secret'", $sql);
        $this->assertStringContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'%'", $sql);
        $this->assertStringContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'localhost'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS ''@'%'", $sql);
        $this->assertStringContainsString('FLUSH PRIVILEGES', $sql);

        $this->assertStringNotContainsString('CREATE USER IF NOT EXISTS', $sql);
        $this->assertStringNotContainsString("CREATE USER 'u74_s24'@'10.201.0.26'", $sql);
        $this->assertStringNotContainsString("CREATE USER 'u74_s24'@'10.%'", $sql);
        $this->assertStringNotContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'10.201.0.26'", $sql);
    }

    #[Test]
    public function grant_sql_warnings_must_not_take_the_mysql_sidecar_down(): void
    {
        $service = app(ContainerDeploymentService::class);

        $this->assertFalse($service->mysqlSidecarGrantShouldStopDatabase(
            "ERROR 1396 (HY000) at line 1: Operation DROP USER failed for 'u74_s24'@'%'"
        ));
        $this->assertFalse($service->mysqlSidecarGrantShouldStopDatabase(
            "ERROR 1396 (HY000): Operation CREATE USER failed for 'u74_s24'@'%'"
        ));
        $this->assertTrue($service->mysqlSidecarGrantShouldStopDatabase(
            "ERROR 1045 (28000): Access denied for user 'root'@'localhost' (using password: YES)"
        ));
    }
}
