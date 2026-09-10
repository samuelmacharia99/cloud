<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerSqlDumpImportService;
use Illuminate\Http\UploadedFile;
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
    public function it_keeps_grant_and_use_text_inside_insert_rows(): void
    {
        $dump = <<<'SQL'
INSERT INTO `wp_options` VALUES (1,'sql','please GRANT ALL; then USE `other`;');
GRANT ALL PRIVILEGES ON `digiworl_roadtrip`.* TO 'digiworl'@'%';
SQL;

        $sanitized = $this->importer()->sanitizeDumpForSidecar($dump);

        $this->assertStringContainsString('please GRANT ALL; then USE `other`;', $sanitized);
        $this->assertStringNotContainsString('GRANT ALL PRIVILEGES', $sanitized);
        $this->importer()->assertSafeSqlImport($sanitized);
    }

    #[Test]
    public function it_splits_statements_without_breaking_backslash_newlines_in_strings(): void
    {
        $dump = "INSERT INTO `t` VALUES ('foo\n\\bar');\nINSERT INTO `u` VALUES (1);\n";

        $statements = $this->importer()->splitSqlStatements($dump);

        $this->assertCount(2, $statements);
        $this->assertSame("INSERT INTO `t` VALUES ('foo\n\\bar')", $statements[0]);
        $this->assertSame('INSERT INTO `u` VALUES (1)', $statements[1]);
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
        $this->expectExceptionMessage('CREATE/DROP DATABASE');

        $this->importer()->assertSafeSqlImport('CREATE DATABASE leftover_db;');
    }

    #[Test]
    public function it_flattens_statements_so_mysql_client_never_sees_a_backslash_command_line(): void
    {
        $dump = "INSERT INTO `t` VALUES ('foo\n\\bar');\nINSERT INTO `u` VALUES (1);\n";

        $prepared = $this->importer()->mysqlClientDump($dump);
        $lines = preg_split('/\n/', trim($prepared)) ?: [];

        $this->assertGreaterThanOrEqual(2, count($lines));
        foreach ($lines as $line) {
            $this->assertFalse(
                str_starts_with(ltrim($line), '\\'),
                'mysql client command line: '.$line
            );
        }
        $this->assertStringContainsString("VALUES ('foo\\n", $prepared);
        $this->assertStringContainsString('INSERT INTO `u` VALUES (1)', $prepared);
    }

    #[Test]
    public function it_keeps_create_table_columns_when_flattening_line_comments(): void
    {
        $sql = "CREATE TABLE `t` (\n  -- ignore\n  `id` int NOT NULL\n)";

        $flat = $this->importer()->flattenSqlStatementForMysqlClient($sql);

        $this->assertStringNotContainsString("\n", $flat);
        $this->assertStringContainsString('`id` int NOT NULL', $flat);
        $this->assertStringNotContainsString('ignore', $flat);
    }

    #[Test]
    public function it_parses_php_ini_size_values(): void
    {
        $importer = $this->importer();

        $this->assertSame(8 * 1024 * 1024, $importer->iniToBytes('8M'));
        $this->assertSame(100 * 1024 * 1024, $importer->iniToBytes('100M'));
        $this->assertSame(512, $importer->iniToBytes('512'));
    }

    #[Test]
    public function it_explains_php_ini_upload_failures(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sql-upload-');
        file_put_contents($path, 'x');
        $file = new UploadedFile($path, 'dump.sql', 'text/plain', UPLOAD_ERR_INI_SIZE, true);

        $message = $this->importer()->describePhpUploadFailure($file);

        $this->assertNotNull($message);
        $this->assertStringContainsString('larger than PHP allows', $message);
        @unlink($path);
    }

    #[Test]
    public function it_drops_the_owner_of_a_dump_taken_on_someone_else_machine(): void
    {
        $dump = <<<'SQL'
CREATE TABLE public.users (id integer NOT NULL);
ALTER TABLE public.users OWNER TO macharia;
ALTER SEQUENCE public.users_id_seq OWNER TO macharia;
INSERT INTO public.users VALUES (1);
SQL;

        $rewritten = $this->importer()->postgresClientDump($dump);

        $this->assertStringNotContainsString('OWNER TO', $rewritten);
        $this->assertStringContainsString('CREATE TABLE public.users', $rewritten);
        $this->assertStringContainsString('INSERT INTO public.users VALUES (1);', $rewritten);
    }

    #[Test]
    public function it_drops_session_authorization_which_also_names_a_missing_role(): void
    {
        $rewritten = $this->importer()->postgresClientDump(
            "SET SESSION AUTHORIZATION 'macharia';\nCREATE TABLE t (id int);\nRESET SESSION AUTHORIZATION;\n"
        );

        $this->assertStringNotContainsString('SESSION AUTHORIZATION', $rewritten);
        $this->assertStringContainsString('CREATE TABLE t (id int);', $rewritten);
    }

    #[Test]
    public function it_leaves_a_row_that_merely_mentions_an_owner_alone(): void
    {
        $dump = "INSERT INTO audit VALUES (1, 'ALTER TABLE x OWNER TO bob;');\n";

        $this->assertStringContainsString('OWNER TO bob', $this->importer()->postgresClientDump($dump));
    }

    #[Test]
    public function it_assembles_upload_chunks_in_order(): void
    {
        $importer = $this->importer();
        $uploadId = str_repeat('ab', 16);
        $first = UploadedFile::fake()->createWithContent('dump.sql', "CREATE TABLE t;\n");
        $second = UploadedFile::fake()->createWithContent('dump.sql', "INSERT INTO t VALUES (1);\n");

        $pending = $importer->storeChunk(426, $uploadId, 0, 2, $first);
        $this->assertFalse($pending['complete']);
        $this->assertSame(1, $pending['received']);

        $done = $importer->storeChunk(426, $uploadId, 1, 2, $second);
        $this->assertTrue($done['complete']);
        $this->assertStringContainsString('CREATE TABLE t;', file_get_contents($done['path']));
        $this->assertStringContainsString('INSERT INTO t VALUES (1);', file_get_contents($done['path']));

        $importer->forgetUpload(426, $uploadId);
        $this->assertFileDoesNotExist($done['path']);
    }
}
