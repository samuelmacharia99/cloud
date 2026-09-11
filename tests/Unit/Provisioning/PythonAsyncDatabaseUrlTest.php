<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform rebuilds DATABASE_URL from freshly aligned credentials in four
 * places, each composing a bare scheme. Any one of them running after the
 * driver was resolved would silently un-pin it and put the stack straight back
 * into the crash loop it was just brought out of.
 */
class PythonAsyncDatabaseUrlTest extends TestCase
{
    #[Test]
    public function rehoming_a_stack_keeps_the_async_driver_its_url_already_named(): void
    {
        $pinned = app(ContainerDeploymentService::class)->pinApplicationDatabaseHost([
            'DB_USERNAME' => 'u493_s457',
            'DB_PASSWORD' => 'secret',
            'DB_DATABASE' => 's457_db',
            'DB_HOST' => 'db',
            'DATABASE_URL' => 'postgresql+asyncpg://u493_s457:secret@db:5432/s457_db',
        ], 'user-493-service-457-python', 'postgresql');

        $this->assertSame('user-493-service-457-python-db', $pinned['DB_HOST']);
        $this->assertSame(
            'postgresql+asyncpg://u493_s457:secret@user-493-service-457-python-db:5432/s457_db',
            $pinned['DATABASE_URL'],
        );
    }

    #[Test]
    public function a_stack_without_a_pinned_driver_still_gets_a_bare_scheme(): void
    {
        $pinned = app(ContainerDeploymentService::class)->pinApplicationDatabaseHost([
            'DB_USERNAME' => 'u493_s457',
            'DB_PASSWORD' => 'secret',
            'DB_DATABASE' => 's457_db',
            'DB_HOST' => 'db',
            'DATABASE_URL' => 'postgresql://u493_s457:secret@db:5432/s457_db',
        ], 'user-493-service-457-python', 'postgresql');

        $this->assertSame(
            'postgresql://u493_s457:secret@user-493-service-457-python-db:5432/s457_db',
            $pinned['DATABASE_URL'],
        );
    }

    #[Test]
    public function moving_a_stack_to_another_engine_drops_the_driver_rather_than_carrying_it(): void
    {
        // asyncpg cannot speak to MySQL. Carrying the suffix across a scheme
        // change would produce a URL that names a dialect that does not exist.
        $pinned = app(ContainerDeploymentService::class)->pinApplicationDatabaseHost([
            'DB_USERNAME' => 'u493_s457',
            'DB_PASSWORD' => 'secret',
            'DB_DATABASE' => 's457_db',
            'DB_HOST' => 'db',
            'DATABASE_URL' => 'postgresql+asyncpg://u493_s457:secret@db:5432/s457_db',
        ], 'user-493-service-457-python', 'mysql');

        $this->assertStringStartsWith('mysql://', $pinned['DATABASE_URL']);
        $this->assertStringNotContainsString('asyncpg', $pinned['DATABASE_URL']);
    }

    #[Test]
    public function aligning_credentials_keeps_the_driver_while_correcting_the_password(): void
    {
        $normalized = app(ContainerDeploymentService::class)->normalizeDatabaseEnvironment(
            $this->service(),
            [
                'DB_USERNAME' => 'u493_s457',
                'DB_PASSWORD' => 'correct-password',
                'DB_DATABASE' => 's457_db',
                'DB_HOST' => 'db',
                'POSTGRES_PASSWORD' => 'correct-password',
                'DATABASE_URL' => 'postgresql+asyncpg://u493_s457:stale@db:5432/s457_db',
            ],
            'postgresql',
        );

        $url = $normalized['env']['DATABASE_URL'];

        $this->assertStringStartsWith('postgresql+asyncpg://', $url);
        $this->assertStringContainsString('correct-password', $url);
        $this->assertStringNotContainsString(':stale@', $url);
    }

    private function service(): Service
    {
        $service = new Service;
        $service->id = 457;
        $service->user_id = 493;

        return $service;
    }
}
