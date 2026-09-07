<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerSqlDumpImportService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerSqlDumpImportServiceTest extends TestCase
{
    private function importer(): ContainerSqlDumpImportService
    {
        return app(ContainerSqlDumpImportService::class);
    }

    #[Test]
    public function it_strips_directadmin_schema_wrappers_and_keeps_tables(): void
    {
        $dump = <<<'SQL'
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `digiworl_roadtrip`
/*!40100 DEFAULT CHARACTER SET utf8mb4 */;
USE `digiworl_roadtrip`;
GRANT ALL PRIVILEGES ON `digiworl_roadtrip`.* TO 'digiworl'@'%';
DROP TABLE IF EXISTS `trips`;
CREATE TABLE `trips` (
  `id` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;
INSERT INTO `trips` VALUES (1);
SQL;

        $sanitized = $this->importer()->sanitizeDumpForSidecar($dump);

        $this->assertStringNotContainsString('CREATE DATABASE', $sanitized);
        $this->assertStringNotContainsString('USE `digiworl_roadtrip`', $sanitized);
        $this->assertStringNotContainsString('GRANT ALL', $sanitized);
        $this->assertStringContainsString('CREATE TABLE `trips`', $sanitized);
        $this->assertStringContainsString('INSERT INTO `trips` VALUES (1)', $sanitized);

        $this->importer()->assertSafeSqlImport($sanitized);
    }

    #[Test]
    public function it_strips_definer_clauses_from_views(): void
    {
        $dump = "CREATE ALGORITHM=UNDEFINED DEFINER=`digiworl`@`localhost` SQL SECURITY DEFINER VIEW `v` AS SELECT 1;\n";

        $sanitized = $this->importer()->sanitizeDumpForSidecar($dump);

        $this->assertStringNotContainsString('DEFINER=', $sanitized);
        $this->assertStringContainsString('CREATE ALGORITHM=UNDEFINED', $sanitized);
        $this->assertStringContainsString('VIEW `v`', $sanitized);
    }

    #[Test]
    public function it_rejects_leftover_database_level_statements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database-level');

        $this->importer()->assertSafeSqlImport('CREATE DATABASE leftover_db;');
    }
}
