<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerPostgresExtensionService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two walls look the same to a migration: an extension the image ships but
 * only a superuser may create, and one the image does not have at all. The
 * first is one statement; the second is an image swap, and when it crosses
 * from musl to glibc, a rebuild of every index too.
 */
class ContainerPostgresExtensionEnableTest extends TestCase
{
    use RefreshDatabase;

    private const COMPOSE = "services:\n  backend:\n    image: python:3.11\n  db:\n    image: postgres:16-alpine\n    container_name: user-493-service-457-db\n";

    /** @var list<string> */
    private array $commands = [];

    /** @var list<array{0: string, 1: string}> */
    private array $uploads = [];

    private bool $installed = false;

    /** @var list<string> */
    private array $available = ['plpgsql'];

    #[Test]
    public function an_extension_the_image_ships_is_created_as_the_cluster_owner_without_a_swap(): void
    {
        [$service, $deployment] = $this->stack();
        $this->available = ['plpgsql', 'uuid-ossp'];

        $message = (new ContainerPostgresExtensionService)->enableExtension($service, $deployment, $this->ssh(), 'uuid-ossp');

        $this->assertSame('uuid-ossp is available in this database.', $message);
        $joined = implode("\n", $this->commands);
        $this->assertStringContainsString('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"', $joined);
        $this->assertStringContainsString("psql -v ON_ERROR_STOP=1 -U 'u493_s457'", $joined);
        $this->assertStringNotContainsString('docker pull', $joined);
        $this->assertSame([], $this->uploads);
        $this->assertArrayNotHasKey('database_image', $service->fresh()->service_meta ?? []);
    }

    #[Test]
    public function pgvector_on_an_alpine_sidecar_swaps_the_image_rebuilds_the_indexes_and_creates_the_extension(): void
    {
        [$service, $deployment] = $this->stack();

        $message = (new ContainerPostgresExtensionService)->enableExtension($service, $deployment, $this->ssh(), 'vector');

        $this->assertSame(
            'pgvector is available in this database. The database now runs pgvector/pgvector:pg16. Its indexes were rebuilt for the new C library.',
            $message
        );

        $joined = implode("\n", $this->commands);
        $pull = $this->indexOf("docker pull 'pgvector/pgvector:pg16'");
        $up = $this->indexOf('docker compose up -d db');
        $reindex = $this->indexOf('REINDEX DATABASE "appdb"');
        $refresh = $this->indexOf('ALTER DATABASE "appdb" REFRESH COLLATION VERSION');
        $create = $this->indexOf('CREATE EXTENSION IF NOT EXISTS "vector"');
        $this->assertTrue($pull < $up && $up < $reindex && $reindex < $refresh && $refresh < $create, $joined);

        $this->assertCount(1, $this->uploads);
        $this->assertSame('/opt/talksasa/containers/'.$deployment->container_name.'/docker-compose.yml', $this->uploads[0][0]);
        $this->assertStringContainsString('pgvector/pgvector:pg16', $this->uploads[0][1]);
        $this->assertStringContainsString('python:3.11', $this->uploads[0][1]);
        $this->assertSame('pgvector/pgvector:pg16', $service->fresh()->service_meta['database_image']);
        $this->assertStringContainsString('pgvector/pgvector:pg16', (string) $deployment->fresh()->docker_compose_content);
    }

    #[Test]
    public function an_extension_that_is_already_there_is_left_alone(): void
    {
        [$service, $deployment] = $this->stack();
        $this->installed = true;

        $message = (new ContainerPostgresExtensionService)->enableExtension($service, $deployment, $this->ssh(), 'vector');

        $this->assertSame('pgvector is already available in this database.', $message);
        $this->assertStringNotContainsString('docker pull', implode("\n", $this->commands));
    }

    #[Test]
    public function an_unknown_extension_with_no_image_is_refused_with_the_operator_told_what_to_set(): void
    {
        [$service, $deployment] = $this->stack();

        try {
            (new ContainerPostgresExtensionService)->enableExtension($service, $deployment, $this->ssh(), 'timescaledb');
            $this->fail('Expected the extension to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No database image with timescaledb is configured for postgres:16-alpine', $e->getMessage());
            $this->assertStringContainsString('postgres_extensions', $e->getMessage());
        }

        $this->assertSame([], $this->uploads);
    }

    private function indexOf(string $needle): int
    {
        foreach ($this->commands as $i => $command) {
            if (str_contains($command, $needle)) {
                return $i;
            }
        }

        $this->fail("No command contained: {$needle}");
    }

    private function ssh(): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('upload')->andReturnUsing(function (string $content, string $path): void {
            $this->uploads[] = [$path, $content];
        });
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command): string {
            $this->commands[] = $command;

            if (str_contains($command, 'cat ')) {
                return self::COMPOSE;
            }
            if (str_contains($command, 'pg_available_extensions')) {
                return implode("\n", $this->available);
            }
            if (str_contains($command, 'FROM pg_extension WHERE extname')) {
                return $this->installed ? '1' : '';
            }
            if (str_contains($command, 'CREATE EXTENSION')) {
                $this->installed = true;
            }

            return '';
        });

        return $ssh;
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function stack(): array
    {
        $service = Service::factory()->create(['service_meta' => ['provision_template_slug' => 'python']]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => Node::factory()->containerHost()->create()->id,
            'docker_compose_content' => self::COMPOSE,
            'env_values' => [
                'DB_USERNAME' => 'u493_s457',
                'DB_PASSWORD' => 'pw',
                'DB_DATABASE' => 'appdb',
                'POSTGRES_USER' => 'u493_s457',
                'POSTGRES_PASSWORD' => 'pw',
                'POSTGRES_DB' => 'appdb',
            ],
        ]);

        return [$service->fresh(), $deployment];
    }
}
