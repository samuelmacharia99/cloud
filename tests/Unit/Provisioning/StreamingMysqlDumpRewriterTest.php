<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\ContainerSqlDumpImportService;
use App\Services\Provisioning\DaConvertProgress;
use App\Services\Provisioning\StreamingMysqlDumpRewriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamingMysqlDumpRewriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_streamed_rewrite_matches_the_in_memory_rewrite_on_a_real_shaped_dump(): void
    {
        $dump = <<<'SQL'
-- MySQL dump 10.13  Distrib 8.0.36
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
CREATE DATABASE IF NOT EXISTS `citychoi_wp` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;
USE `citychoi_wp`;
GRANT ALL ON `citychoi_wp`.* TO 'citychoi'@'localhost';

DROP TABLE IF EXISTS `wp_options`;
CREATE TABLE `wp_options` (
  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT, -- primary
  `option_value` longtext NOT NULL,
  PRIMARY KEY (`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `wp_options` VALUES (1,'a:1:{s:4:\"text\";s:12:\"line one
\\line two\";}'),(2,'it''s "quoted"; still one row'),(3,'ends with backslash \\
next');
INSERT INTO `wp_posts` VALUES (9,'<p>Hello</p>\r\n<p>World</p>');

DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`citychoi`@`localhost`*/ /*!50003 TRIGGER `after_insert` AFTER INSERT ON `wp_posts` FOR EACH ROW BEGIN
  UPDATE `wp_options` SET `option_value` = 'x;y' WHERE `option_id` = 1;
END */;;
DELIMITER ;

CREATE TABLE `has``tick` (`c` int);
SQL;

        $importer = app(ContainerSqlDumpImportService::class);
        $expected = $importer->mysqlClientDump($dump);

        $in = tempnam(sys_get_temp_dir(), 'dump');
        $out = $in.'.out';
        file_put_contents($in, $dump);
        $written = $this->rewriter($importer)->rewrite($in, $out);
        $actual = file_get_contents($out);
        @unlink($in);
        @unlink($out);

        $this->assertSame($expected, $actual);
        $this->assertSame(7, $written);
        $this->assertStringStartsWith("SET NAMES utf8mb4;\n", $actual);
        $this->assertStringNotContainsString('CREATE DATABASE', $actual);
        $this->assertStringNotContainsString('GRANT ALL', $actual);
        $this->assertStringNotContainsString('USE `citychoi_wp`', $actual);
        $this->assertStringNotContainsString('DEFINER=', $actual);
        $this->assertStringContainsString('line one\\n\\\\line two', $actual);
        foreach (explode("\n", trim($actual)) as $line) {
            $this->assertFalse(str_starts_with(ltrim($line), '\\'), 'mysql client command line: '.$line);
        }
    }

    public function test_phpmyadmin_triggers_and_procedures_are_kept_whole_for_the_client(): void
    {
        $dump = <<<'SQL'
-- phpMyAdmin SQL Dump
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
CREATE TABLE `marks` (`id` int NOT NULL, `total` int DEFAULT 0) ENGINE=InnoDB;
INSERT INTO `marks` VALUES (1, 0), (2, 5);

--
-- Triggers `marks`
--
DELIMITER $$
CREATE TRIGGER `after_mark_insert` AFTER INSERT ON `marks` FOR EACH ROW BEGIN
  UPDATE `totals` SET `count` = `count` + 1 WHERE `id` = 1;
  INSERT INTO `audit` (`note`) VALUES ('mark; inserted');
END
$$
DELIMITER ;

DELIMITER //
CREATE DEFINER=`blinksof`@`localhost` PROCEDURE `recalc`(IN sid INT)
BEGIN
  UPDATE `marks` SET `total` = `total` + 1 WHERE `id` = sid;
END//
DELIMITER ;

INSERT INTO `marks` VALUES (3, 9);
SQL;

        $importer = app(ContainerSqlDumpImportService::class);
        $expected = $importer->mysqlClientDump($dump);

        $in = tempnam(sys_get_temp_dir(), 'dump');
        $out = $in.'.out';
        file_put_contents($in, $dump);
        $written = $this->rewriter($importer)->rewrite($in, $out);
        $actual = (string) file_get_contents($out);
        @unlink($in);
        @unlink($out);

        $this->assertSame($expected, $actual, 'the streamed and in-memory rewrites agree');
        $this->assertSame(6, $written);
        $this->assertMatchesRegularExpression("/DELIMITER ;;\nCREATE TRIGGER `after_mark_insert` AFTER INSERT ON `marks` FOR EACH ROW BEGIN\\s+UPDATE `totals` SET `count` = `count` \\+ 1 WHERE `id` = 1;\\s+INSERT INTO `audit` \\(`note`\\) VALUES \\('mark; inserted'\\); END;;\nDELIMITER ;\n/", $actual);
        $this->assertMatchesRegularExpression("/DELIMITER ;;\nCREATE PROCEDURE `recalc`\\(IN sid INT\\) BEGIN\\s+UPDATE `marks` SET `total` = `total` \\+ 1 WHERE `id` = sid; END;;\nDELIMITER ;\n/", $actual);
        $this->assertStringContainsString("INSERT INTO `marks` VALUES (1, 0), (2, 5);\n", $actual);
        $this->assertStringContainsString("INSERT INTO `marks` VALUES (3, 9);\n", $actual);
        $this->assertStringNotContainsString('DELIMITER $$', $actual);
        $this->assertStringNotContainsString('DEFINER=', $actual);

        // The mysql client sends a compound statement as one unit only between DELIMITER lines;
        // every other statement still ends with a single semicolon on its own line.
        $lines = explode("\n", trim($actual));
        $this->assertSame('SET NAMES utf8mb4;', $lines[0]);
        $this->assertSame(4, count(array_filter($lines, fn ($l) => str_starts_with($l, 'DELIMITER '))));
    }

    public function test_rewrite_in_place_keeps_memory_flat_on_a_large_dump(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bigdump');
        $handle = fopen($path, 'wb');
        fwrite($handle, "CREATE TABLE `t` (`id` int, `body` longtext);\n");
        $row = str_repeat('lorem ipsum dolor sit amet, ', 40)."\n\\continued";
        for ($i = 0; $i < 12000; $i++) {
            fwrite($handle, "INSERT INTO `t` VALUES ({$i},'{$row}');\n");
        }
        fclose($handle);
        $size = filesize($path);
        $this->assertGreaterThan(12 * 1024 * 1024, $size, 'fixture should be a real-sized dump');

        $before = memory_get_peak_usage(true);
        app(ContainerSqlDumpImportService::class)->rewriteLocalDumpForMysqlClient($path);
        $growth = memory_get_peak_usage(true) - $before;

        $rewritten = file_get_contents($path);
        @unlink($path);

        $this->assertLessThan(8 * 1024 * 1024, $growth, 'rewrite must not scale with the dump size (grew '.round($growth / 1048576, 1).' MB for a '.round($size / 1048576, 1).' MB dump)');
        $this->assertStringStartsWith("SET NAMES utf8mb4;\n", $rewritten);
        $this->assertSame(12002, substr_count($rewritten, "\n"));
        $this->assertStringNotContainsString("\n\\continued", $rewritten, 'a real newline before a backslash would become a mysql client command');
        $this->assertStringContainsString('\\n\\continued', $rewritten);
    }

    public function test_empty_or_comment_only_dumps_are_refused(): void
    {
        $importer = app(ContainerSqlDumpImportService::class);
        $path = tempnam(sys_get_temp_dir(), 'dump');

        // Block comments are kept (mysqldump's /*!40101 ... */ lines are real statements); line comments are not.
        file_put_contents($path, "-- nothing here\n# nor here\n");
        try {
            $importer->rewriteLocalDumpForMysqlClient($path);
            $this->fail('comment-only dump must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no executable statements', $e->getMessage());
        }

        file_put_contents($path, '');
        try {
            $importer->rewriteLocalDumpForMysqlClient($path);
            $this->fail('empty dump must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing or empty', $e->getMessage());
        }
        @unlink($path);
    }

    public function test_a_fatal_error_marks_a_running_convert_failed_with_the_reason(): void
    {
        $service = Service::factory()->create(['service_meta' => ['da_convert' => ['status' => 'running', 'steps' => ['Importing site into container']]]]);
        $progress = app(DaConvertProgress::class);

        $this->assertFalse($progress->recordFatal($service->id, ['type' => E_WARNING, 'message' => 'not fatal']));
        $this->assertSame('running', $service->fresh()->service_meta['da_convert']['status']);

        $this->assertTrue($progress->recordFatal($service->id, [
            'type' => E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 913408 bytes)',
            'file' => '/var/www/app/Services/Provisioning/ContainerSqlDumpImportService.php',
            'line' => 521,
        ]));
        $meta = $service->fresh()->service_meta['da_convert'];
        $this->assertSame('failed', $meta['status']);
        $this->assertStringContainsString('PHP stopped: Allowed memory size of 134217728 bytes exhausted', $meta['error']);
        $this->assertStringContainsString('(ContainerSqlDumpImportService.php:521)', $meta['error']);

        // A convert that already finished is left alone.
        $this->assertFalse($progress->recordFatal($service->id, ['type' => E_ERROR, 'message' => 'late']));
        $this->assertSame('failed', $service->fresh()->service_meta['da_convert']['status']);
    }

    private function rewriter(ContainerSqlDumpImportService $importer): StreamingMysqlDumpRewriter
    {
        return new StreamingMysqlDumpRewriter(fn (string $s): string => $importer->flattenSqlStatementForMysqlClient($s));
    }
}
