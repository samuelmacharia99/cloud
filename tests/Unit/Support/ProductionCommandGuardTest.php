<?php

namespace Tests\Unit\Support;

use App\Support\ProductionCommandGuard;
use RuntimeException;
use Tests\TestCase;

class ProductionCommandGuardTest extends TestCase
{
    private function asProduction(): void
    {
        config(['app.env' => 'production']);
    }

    public function test_blocks_destructive_migrate_commands(): void
    {
        $this->asProduction();

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'] as $command) {
            try {
                ProductionCommandGuard::assertCommandAllowed($command);
                $this->fail("Expected {$command} to be blocked");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('blocked in production', $e->getMessage());
            }
        }
    }

    public function test_blocks_db_seed_without_class(): void
    {
        $this->asProduction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without --class');

        ProductionCommandGuard::assertCommandAllowed('db:seed', []);
    }

    public function test_blocks_non_allowlisted_seeder(): void
    {
        $this->asProduction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowlisted');

        ProductionCommandGuard::assertCommandAllowed('db:seed', ['class' => 'SettingSeeder']);
    }

    public function test_allows_cron_job_seeder(): void
    {
        $this->asProduction();

        ProductionCommandGuard::assertCommandAllowed('db:seed', ['class' => 'CronJobSeeder']);

        $this->assertTrue(true);
    }

    public function test_allows_destructive_commands_outside_production(): void
    {
        config(['app.env' => 'testing']);

        ProductionCommandGuard::assertCommandAllowed('migrate:fresh');
        ProductionCommandGuard::assertCommandAllowed('db:seed', []);

        $this->assertTrue(true);
    }
}
