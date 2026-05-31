<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeployOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerDeployOptionsTest extends TestCase
{
    #[Test]
    public function it_resets_database_only_on_redeploy_when_requested(): void
    {
        $options = ContainerDeployOptions::redeploy(resetDatabase: true);

        $this->assertTrue($options->shouldResetDatabase(true));
        $this->assertFalse($options->shouldResetDatabase(false));
        $this->assertTrue($options->shouldSyncLaravelDatabase('laravel'));
        $this->assertFalse($options->shouldSyncLaravelDatabase('php'));
    }

    #[Test]
    public function it_skips_database_reset_on_initial_deploy(): void
    {
        $options = new ContainerDeployOptions(resetDatabase: true);

        $this->assertFalse($options->shouldResetDatabase(true));
        $this->assertTrue($options->shouldPrepareLaravelApplication('laravel'));
        $this->assertTrue($options->shouldRunLaravelMigrations('laravel'));
        $this->assertTrue($options->shouldSyncLaravelDatabase('laravel'));
    }

    #[Test]
    public function it_prepares_laravel_on_redeploy_without_running_migrations_by_default(): void
    {
        $options = ContainerDeployOptions::redeploy(resetDatabase: false);

        $this->assertTrue($options->shouldPrepareLaravelApplication('laravel'));
        $this->assertFalse($options->shouldRunLaravelMigrations('laravel'));
        $this->assertFalse($options->shouldSyncLaravelDatabase('laravel'));
    }
}
