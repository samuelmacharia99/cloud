<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MysqlSidecarCredentialsTest extends TestCase
{
    #[Test]
    public function privilege_repair_sql_grants_the_app_user_from_any_host_and_localhost(): void
    {
        $sql = app(ContainerDeploymentService::class)->mysqlPrivilegeRepairSql(
            's24_db',
            'u74_s24',
            'secret',
        );

        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `s24_db`', $sql);
        $this->assertStringContainsString("CREATE USER IF NOT EXISTS 'u74_s24'@'%' IDENTIFIED BY 'secret'", $sql);
        $this->assertStringContainsString("ALTER USER 'u74_s24'@'%' IDENTIFIED BY 'secret'", $sql);
        $this->assertStringContainsString("CREATE USER IF NOT EXISTS 'u74_s24'@'localhost' IDENTIFIED BY 'secret'", $sql);
        $this->assertStringContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'%'", $sql);
        $this->assertStringContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'localhost'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS ''@'%'", $sql);
        $this->assertStringContainsString('FLUSH PRIVILEGES', $sql);
    }
}
