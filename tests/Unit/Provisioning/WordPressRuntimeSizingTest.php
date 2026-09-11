<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerTemplateEnvironmentService;
use App\Services\Provisioning\WordPressContainerHardeningService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MySQL and Apache used to be sized for the smallest plan, always: a fixed
 * 256 MB buffer pool, 50 connections, and whatever worker ceiling the image
 * shipped. A customer on four gigabytes ran the same database as one on one,
 * and nothing bounded how many PHP workers could exist at once.
 */
class WordPressRuntimeSizingTest extends TestCase
{
    #[Test]
    public function the_buffer_pool_grows_with_the_plan(): void
    {
        $small = $this->bufferPoolMb(1024);
        $large = $this->bufferPoolMb(4096);

        $this->assertGreaterThan($small, $large);
        $this->assertSame(736, $large);
    }

    #[Test]
    public function connections_grow_with_the_plan_but_stay_bounded(): void
    {
        $this->assertSame(76, $this->maxConnections(1024));
        $this->assertSame(300, $this->maxConnections(65536), 'A huge plan still cannot ask for unlimited connections.');
        $this->assertSame(50, $this->maxConnections(128), 'A tiny plan keeps a usable floor.');
    }

    #[Test]
    public function a_caller_without_a_plan_gets_exactly_the_old_numbers(): void
    {
        $flags = app(ContainerTemplateEnvironmentService::class)->mysqlTuningFlags(null);

        $this->assertContains('--innodb-buffer-pool-size=256M', $flags);
        $this->assertContains('--max-connections=50', $flags);
    }

    #[Test]
    public function the_flags_that_keep_mysql_alive_on_these_nodes_survive(): void
    {
        // Native AIO off and performance_schema off are not tuning choices;
        // they are what keeps MySQL starting on this fleet at all.
        foreach ([null, 1024, 8192] as $plan) {
            $flags = app(ContainerTemplateEnvironmentService::class)->mysqlTuningFlags($plan);

            $this->assertContains('--innodb-use-native-aio=0', $flags);
            $this->assertContains('--performance-schema=OFF', $flags);
        }
    }

    #[Test]
    public function apache_workers_are_bounded_by_the_plan(): void
    {
        $hardening = app(WordPressContainerHardeningService::class);

        $this->assertSame(11, $hardening->maxRequestWorkers(1024));
        $this->assertSame(44, $hardening->maxRequestWorkers(4096));
        $this->assertSame(8, $hardening->maxRequestWorkers(128), 'A tiny plan still serves a few requests at once.');
        $this->assertSame(64, $hardening->maxRequestWorkers(1048576), 'A huge plan does not get an unbounded ceiling.');
        $this->assertSame(16, $hardening->maxRequestWorkers(null), 'An unknown plan gets a conservative default.');
    }

    #[Test]
    public function the_worker_file_is_valid_apache_configuration(): void
    {
        $conf = app(WordPressContainerHardeningService::class)->apacheWorkersConfContents(1024);

        $this->assertStringContainsString('<IfModule mpm_prefork_module>', $conf);
        $this->assertStringContainsString('MaxRequestWorkers 11', $conf);
        $this->assertStringContainsString('</IfModule>', $conf);
    }

    #[Test]
    public function the_worker_file_is_mounted_where_apache_reads_it(): void
    {
        $mount = app(WordPressContainerHardeningService::class)->apacheWorkersVolumeMount('user-1-service-1-wordpress');

        $this->assertStringEndsWith(':/etc/apache2/conf-enabled/talksasa-workers.conf:ro', $mount);
        $this->assertStringContainsString('/opt/talksasa/containers/user-1-service-1-wordpress/php/', $mount);
    }

    private function bufferPoolMb(int $planMemoryMb): int
    {
        return $this->flagValue($planMemoryMb, '--innodb-buffer-pool-size=');
    }

    private function maxConnections(int $planMemoryMb): int
    {
        return $this->flagValue($planMemoryMb, '--max-connections=');
    }

    private function flagValue(int $planMemoryMb, string $prefix): int
    {
        $flags = app(ContainerTemplateEnvironmentService::class)->mysqlTuningFlags($planMemoryMb);
        $flag = (string) collect($flags)->first(fn (string $f): bool => str_starts_with($f, $prefix));

        return (int) rtrim(substr($flag, strlen($prefix)), 'M');
    }
}
