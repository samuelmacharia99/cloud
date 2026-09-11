<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerPostgresExtensionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enabling PostGIS wrote the error Doctor then reported as a critical fault.
 *
 * It ran `psql -U postgres` first, on the assumption that the application role
 * is not a superuser. On a volume created with a custom POSTGRES_USER there is
 * no `postgres` role at all: the app user owns the cluster. So the first
 * attempt always failed, Postgres logged `FATAL: role "postgres" does not
 * exist`, and the fallback succeeded. Doctor read six hours of logs, found the
 * line the platform had just written, and raised it to the customer.
 */
class ContainerPostgisRoleOrderTest extends TestCase
{
    #[Test]
    public function the_application_role_is_tried_before_postgres(): void
    {
        $roles = app(ContainerDeploymentService::class)->postgresqlAdminRoleCandidates(
            ['DB_USERNAME' => 'u493_s457', 'POSTGRES_USER' => 'u493_s457'],
            'u493_s457',
        );

        $this->assertSame('u493_s457', $roles[0]);
        $this->assertContains('postgres', $roles, 'A volume that does have the role must still be reachable.');
        $this->assertGreaterThan(0, array_search('postgres', $roles, true));
    }

    #[Test]
    public function the_extension_step_no_longer_hardcodes_the_postgres_role(): void
    {
        // Read as source rather than executed: the command only runs against a
        // live sidecar, and what matters here is that the literal is gone.
        $source = (string) file_get_contents(
            base_path('app/Services/Provisioning/ContainerPostgresExtensionService.php')
        );

        $this->assertStringNotContainsString('psql -U postgres', $source);
        $this->assertStringContainsString('postgresqlAdminRoleCandidates', $source);
    }

    #[Test]
    public function the_volumes_own_superuser_is_tried_before_any_platform_name(): void
    {
        // Not postgresqlAdminRoleCandidates(): that one leads with a platform
        // admin name which is itself sometimes `postgres`, which would put the
        // failing login straight back at the front of the queue.
        $method = new \ReflectionMethod(ContainerPostgresExtensionService::class, 'superuserCandidates');

        $roles = $method->invoke(app(ContainerPostgresExtensionService::class), [
            'POSTGRES_USER' => 'u493_s457',
            'DB_USERNAME' => 'u493_s457',
            'TALKSASA_PLATFORM_DB_USERNAME' => 'postgres',
        ]);

        $this->assertSame('u493_s457', $roles[0]);
        $this->assertSame('postgres', $roles[count($roles) - 1]);
    }

    #[Test]
    public function a_volume_that_really_is_owned_by_postgres_still_works(): void
    {
        $method = new \ReflectionMethod(ContainerPostgresExtensionService::class, 'superuserCandidates');

        $roles = $method->invoke(app(ContainerPostgresExtensionService::class), [
            'POSTGRES_USER' => 'postgres',
            'DB_USERNAME' => 'postgres',
        ]);

        $this->assertSame(['postgres'], $roles);
    }

    #[Test]
    public function the_extension_existing_is_what_counts_as_success(): void
    {
        // One failed login among several attempts is not a failure if the
        // extension is there afterwards. Reporting it as one sent a customer
        // back to a button that had already done its job.
        $source = (string) file_get_contents(
            base_path('app/Services/Provisioning/ContainerPostgresExtensionService.php')
        );

        $this->assertStringContainsString('extensionInstalled', $source);
        $this->assertStringContainsString('SELECT 1 FROM pg_extension', $source);
    }
}
