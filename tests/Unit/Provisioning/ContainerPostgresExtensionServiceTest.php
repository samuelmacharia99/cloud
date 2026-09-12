<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerPostgresExtensionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A dump that creates PostGIS cannot load into the stock postgres image, and
 * psql stops on the first error, so the whole import is lost to one line. The
 * check has to happen before anything is written.
 */
class ContainerPostgresExtensionServiceTest extends TestCase
{
    #[Test]
    public function it_reads_the_extensions_a_dump_asks_for(): void
    {
        $names = $this->service()->requiredExtensions(<<<'SQL'
        CREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA public;
        CREATE EXTENSION "uuid-ossp";
        CREATE TABLE places (geom geometry);
        SQL);

        $this->assertSame(['postgis', 'uuid-ossp'], $names);
    }

    #[Test]
    public function it_ignores_the_extension_every_image_already_has(): void
    {
        $this->assertSame([], $this->service()->requiredExtensions('CREATE EXTENSION IF NOT EXISTS plpgsql;'));
    }

    #[Test]
    public function a_dump_that_needs_nothing_asks_for_nothing(): void
    {
        $this->assertSame([], $this->service()->requiredExtensions('CREATE TABLE t (id int);'));
    }

    #[Test]
    public function it_offers_the_postgis_build_of_the_same_major_version(): void
    {
        $this->assertSame('postgis/postgis:16-3.4-alpine', $this->service()->postgisImageFor('postgres:16-alpine'));
        $this->assertSame('postgis/postgis:15-3.4-alpine', $this->service()->postgisImageFor('postgres:15-alpine'));
    }

    #[Test]
    public function it_will_not_move_a_musl_data_directory_onto_a_glibc_image(): void
    {
        // The default target is Alpine; a Debian-based source must not take it,
        // because the collation on disk would no longer match the server's.
        $this->assertNull($this->service()->postgisImageFor('postgres:16'));
    }

    #[Test]
    public function a_database_already_running_postgis_needs_no_swap(): void
    {
        $this->assertNull($this->service()->postgisImageFor('postgis/postgis:16-3.4-alpine'));
    }

    #[Test]
    public function it_sets_the_image_on_the_database_service_alone(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            image: node:22-alpine
          db:
            image: postgres:16-alpine
            container_name: user-1-service-1-nodejs-db
        YAML;

        $patched = $this->service()->patchComposeDatabaseImage($yaml, 'postgis/postgis:16-3.4-alpine');

        $this->assertStringContainsString('postgis/postgis:16-3.4-alpine', $patched);
        $this->assertStringContainsString('node:22-alpine', $patched);
        $this->assertStringContainsString('user-1-service-1-nodejs-db', $patched);
        $this->assertSame('postgis/postgis:16-3.4-alpine', $this->service()->databaseImage($patched));
    }

    #[Test]
    public function a_compose_file_with_no_database_is_left_as_it_was(): void
    {
        $yaml = "services:\n  app:\n    image: node:22-alpine\n";

        $this->assertSame($yaml, $this->service()->patchComposeDatabaseImage($yaml, 'postgis/postgis:16-3.4-alpine'));
        $this->assertNull($this->service()->databaseImage($yaml));
    }

    #[Test]
    public function it_names_the_extension_a_failed_migration_was_missing(): void
    {
        $service = $this->service();

        $this->assertSame('vector', $service->extensionRequiredByError(
            "sqlalchemy.exc.InternalError: (psycopg2.errors.RaiseException) pgvector extension is required before this migration\nCONTEXT:  PL/pgSQL function inline_code_block line 4 at RAISE"
        ));
        $this->assertSame('vector', $service->extensionRequiredByError('ERROR:  type "vector" does not exist'));
        $this->assertSame('vector', $service->extensionRequiredByError('ERROR:  extension "vector" is not available'));
        $this->assertSame('postgis', $service->extensionRequiredByError(
            'could not open extension control file "/usr/local/share/postgresql/extension/postgis.control": No such file'
        ));
        $this->assertSame('uuid-ossp', $service->extensionRequiredByError('ERROR:  permission denied to create extension "uuid-ossp"'));
        $this->assertSame('postgis', $service->extensionRequiredByError('ERROR:  function st_geomfromtext(unknown) does not exist'));
        $this->assertNull($service->extensionRequiredByError('ERROR:  relation "users" does not exist'));
        $this->assertNull($service->extensionRequiredByError('ERROR:  permission denied to create extension "plpgsql"'));
    }

    #[Test]
    public function pgvector_reaches_an_alpine_sidecar_only_when_the_caller_will_rebuild_the_indexes(): void
    {
        $service = $this->service();

        // Debian build only, so an Alpine data directory crosses C libraries.
        $this->assertNull($service->imageFor('postgres:16-alpine', 'vector'));
        $this->assertSame('pgvector/pgvector:pg16', $service->imageFor('postgres:16-alpine', 'vector', allowLibcChange: true));
        $this->assertTrue($service->swapChangesLibc('postgres:16-alpine', 'pgvector/pgvector:pg16'));

        // Same library, no rebuild needed.
        $this->assertSame('pgvector/pgvector:pg15', $service->imageFor('postgres:15.4', 'vector'));
        $this->assertFalse($service->swapChangesLibc('postgres:15.4', 'pgvector/pgvector:pg15'));

        $this->assertNull($service->imageFor('pgvector/pgvector:pg16', 'vector'));
        $this->assertNull($service->imageFor('postgres:16-alpine', 'no-such-extension'));
    }

    private function service(): ContainerPostgresExtensionService
    {
        return app(ContainerPostgresExtensionService::class);
    }
}
