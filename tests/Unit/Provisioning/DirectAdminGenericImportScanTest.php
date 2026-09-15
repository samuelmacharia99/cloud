<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerIncidentService;
use App\Services\Provisioning\ContainerIntegrityScanner;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A plain PHP, Laravel or CodeIgniter import gets the same webshell scan a
 * WordPress import gets: certain hits are archived and removed, the rest
 * are named on the console, and the outcome is recorded for the boards.
 */
class DirectAdminGenericImportScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_certain_hits_are_archived_and_removed_and_the_rest_reported(): void
    {
        [$service, $deployment] = $this->deployedService();
        $hits = [
            ['path' => 'public/uploads/shell.php', 'reasons' => ['known_family'], 'size' => 120, 'mtime' => 1],
            ['path' => 'app/Helpers/odd.php', 'reasons' => ['obfuscated_code'], 'size' => 900, 'mtime' => 1],
            ['path' => 'backup.sql', 'reasons' => ['exposed_backup'], 'size' => 5000, 'mtime' => 1],
        ];
        $ssh = Mockery::mock(SSHService::class);

        $scanner = Mockery::mock(ContainerIntegrityScanner::class);
        $scanner->shouldReceive('scan')->once()->withArgs(fn ($sshArg, $dep, $wordpress, $checksums) => $sshArg === $ssh && $dep instanceof ContainerDeployment && $wordpress === false && $checksums === false)->andReturn(['hits' => $hits, 'core' => ['ran' => false], 'summary' => []]);
        $scanner->shouldReceive('quarantine')->once()->withArgs(function ($sshArg, $svc, $dep, $hitsArg, $reasons, $trigger) {
            return $reasons === ContainerIntegrityScanner::AUTO_QUARANTINE_REASONS && $trigger === ContainerIncidentService::TRIGGER_CONVERT;
        })->andReturn(['moved' => ['public/uploads/shell.php'], 'incident' => '20260915-abc', 'bytes' => 120, 'skipped' => [], 'quarantine_dir' => '']);
        $scanner->shouldReceive('persist')->once()->withArgs(fn ($svc, $result, $moved) => $moved === ['public/uploads/shell.php'])->andReturn(['new_paths' => [], 'suspicious' => 1]);
        $scanner->shouldReceive('suspiciousHits')->andReturnUsing(fn ($h) => array_values(array_filter($h, fn ($x) => in_array('obfuscated_code', $x['reasons'], true) || in_array('known_family', $x['reasons'], true))));
        $scanner->shouldReceive('exposedHits')->andReturnUsing(fn ($h) => array_values(array_filter($h, fn ($x) => in_array('exposed_backup', $x['reasons'], true))));
        $scanner->shouldReceive('evidenceRows')->andReturn(['app/Helpers/odd.php · obfuscated code']);
        $this->app->instance(ContainerIntegrityScanner::class, $scanner);

        $incidents = Mockery::mock(ContainerIncidentService::class);
        $incidents->shouldReceive('alert')->once();
        $this->app->instance(ContainerIncidentService::class, $incidents);

        $lines = [];
        app(DirectAdminToContainerMigrationService::class)->scanImportedSiteFiles($service, $ssh, $deployment, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('Archived 1 file(s)', $joined);
        $this->assertStringContainsString('Quarantined: public/uploads/shell.php', $joined);
        $this->assertStringContainsString('1 file(s) need a look', $joined);
        $this->assertStringContainsString('Suspicious: app/Helpers/odd.php', $joined);
        $this->assertStringContainsString('1 backup, dump or log file(s)', $joined);
    }

    public function test_a_scanner_failure_never_blocks_the_import(): void
    {
        [$service, $deployment] = $this->deployedService();
        $ssh = Mockery::mock(SSHService::class);
        $scanner = Mockery::mock(ContainerIntegrityScanner::class);
        $scanner->shouldReceive('scan')->once()->andThrow(new \RuntimeException('python3 missing'));
        $this->app->instance(ContainerIntegrityScanner::class, $scanner);

        $lines = [];
        app(DirectAdminToContainerMigrationService::class)->scanImportedSiteFiles($service, $ssh, $deployment, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertStringContainsString('Integrity scan skipped: python3 missing', implode("\n", $lines));
    }

    public function test_a_clean_site_says_so(): void
    {
        [$service, $deployment] = $this->deployedService();
        $ssh = Mockery::mock(SSHService::class);
        $scanner = Mockery::mock(ContainerIntegrityScanner::class);
        $scanner->shouldReceive('scan')->once()->andReturn(['hits' => [], 'core' => ['ran' => false], 'summary' => []]);
        $scanner->shouldReceive('quarantine')->once()->andReturn(['moved' => [], 'incident' => null, 'bytes' => 0, 'skipped' => [], 'quarantine_dir' => '']);
        $scanner->shouldReceive('persist')->once()->andReturn(['new_paths' => [], 'suspicious' => 0]);
        $scanner->shouldReceive('suspiciousHits')->andReturn([]);
        $scanner->shouldReceive('exposedHits')->andReturn([]);
        $this->app->instance(ContainerIntegrityScanner::class, $scanner);

        $lines = [];
        app(DirectAdminToContainerMigrationService::class)->scanImportedSiteFiles($service, $ssh, $deployment, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertStringContainsString('Integrity scan clean', implode("\n", $lines));
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function deployedService(): array
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);
        $deployment = ContainerDeployment::factory()->create(['service_id' => $service->id, 'status' => 'running']);

        return [$service, $deployment];
    }
}
