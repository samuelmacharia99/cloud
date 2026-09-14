<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerIncidentService;
use App\Services\Provisioning\ContainerIntegrityScanner;
use App\Services\Provisioning\WordPressCoreChecksumService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerIntegrityScannerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function commands_parse_as_bash_and_carry_the_configured_caps(): void
    {
        config(['containers.integrity.max_file_kb' => 512, 'containers.integrity.max_hits_per_rule' => 50]);
        $scanner = app(ContainerIntegrityScanner::class);
        $command = $scanner->scanCommand('/opt/talksasa/containers/x/app', true, '/opt/talksasa/tools/cache/wp-checksums-6.6.2-en_US.json', ['talksasa-admin-sso.php', 'custom.php']);

        $this->assertStringContainsString("--root '/opt/talksasa/containers/x/app' --wordpress --manifest '/opt/talksasa/tools/cache/wp-checksums-6.6.2-en_US.json' --allow-mu 'talksasa-admin-sso.php,custom.php' --max-file-kb 512 --max-hits 50", $command);
        foreach ([$command, $scanner->scanCommand('/opt/x/app', false), $scanner->restoreCoreCommand('/opt/x', 'x')] as $c) {
            $file = tempnam(sys_get_temp_dir(), 'cmd');
            File::put($file, $c);
            exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
            unlink($file);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    #[Test]
    public function scan_output_is_parsed_into_hits_and_a_summary(): void
    {
        $scanner = app(ContainerIntegrityScanner::class);
        $output = implode("\n", [
            "signature\t26\t1700000000\tiisgg8.php",
            "unexpected_root_php\t26\t1700000000\tiisgg8.php",
            "core_checksum_mismatch\t589874\t1700000000\tindex.php",
            'garbage line',
            "__SUMMARY__\t".json_encode(['truncated' => true, 'core' => ['verified' => true, 'version' => '6.6.2']]),
        ]);

        $hits = collect($scanner->parseScan($output))->keyBy('path');
        $this->assertSame(['signature', 'unexpected_root_php'], $hits['iisgg8.php']['reasons']);
        $this->assertSame(589874, $hits['index.php']['size']);
        $this->assertCount(2, $hits);

        $summary = $scanner->parseSummary($output);
        $this->assertTrue($summary['truncated']);
        $this->assertSame('6.6.2', $summary['core']['version']);
        $this->assertTrue($scanner->parseSummary('no summary')['missing_summary']);
    }

    #[Test]
    public function findings_split_malware_core_and_exposed_files_with_the_right_treatments(): void
    {
        $scanner = app(ContainerIntegrityScanner::class);
        $result = [
            'hits' => [
                ['path' => 'iisgg8.php', 'reasons' => ['signature', 'unexpected_root_php'], 'size' => 26, 'mtime' => 1700000000],
                ['path' => 'index.php', 'reasons' => ['core_checksum_mismatch', 'obfuscated_code'], 'size' => 589874, 'mtime' => 1700000000],
                ['path' => 'wp-includes/utf8.php', 'reasons' => ['core_unexpected_file'], 'size' => 6800, 'mtime' => 1700000000],
                ['path' => 'wp-admin/includes/file.php', 'reasons' => ['core_missing_file'], 'size' => 0, 'mtime' => 0],
                ['path' => 'wp-config.php.bak', 'reasons' => ['exposed_backup'], 'size' => 3000, 'mtime' => 1700000000],
            ],
            'core' => ['ran' => true, 'version' => '6.6.2', 'modified' => ['index.php'], 'extra' => ['wp-includes/utf8.php'], 'missing' => ['wp-admin/includes/file.php'], 'error' => null],
            'summary' => ['truncated' => true],
            'scanned_at' => '2026-09-14T00:00:00+00:00',
        ];

        $findings = collect($scanner->findings($result))->keyBy('id');

        $suspicious = $findings['integrity_suspicious_files'];
        $this->assertSame('quarantine_suspicious_files', $suspicious['treat_action']);
        $this->assertSame('Quarantine 2 file(s)', $suspicious['treat_label']);
        $this->assertStringContainsString('index.php', $findings['integrity_core_modified']['evidence'][0]);
        $this->assertStringNotContainsString('index.php · webshell', implode("\n", $suspicious['evidence']), 'a modified core file belongs to the core finding, not the quarantine list');
        $this->assertStringContainsString('not part of WordPress core', implode("\n", $suspicious['evidence']));

        $core = $findings['integrity_core_modified'];
        $this->assertSame('restore_wordpress_core', $core['treat_action']);
        $this->assertStringContainsString('2 WordPress 6.6.2 core file(s) differ', $core['title']);
        $this->assertStringContainsString('1 modified, 1 missing', $core['summary']);

        $this->assertSame('archive_exposed_files', $findings['integrity_exposed_files']['treat_action']);
        $this->assertSame('info', $findings['integrity_scan_partial']['severity']);

        $unverified = $result;
        $unverified['core'] = ['ran' => false, 'version' => null, 'modified' => [], 'extra' => [], 'missing' => [], 'error' => 'offline'];
        $unverified['hits'] = [['path' => 'wp-admin/admin.php', 'reasons' => ['core_modified_after_install'], 'size' => 10, 'mtime' => 1]];
        $fallback = collect($scanner->findings($unverified))->keyBy('id');
        $this->assertStringContainsString('changed after the last update', $fallback['integrity_core_modified']['title']);
        $this->assertStringContainsString('offline', $fallback['integrity_core_modified']['summary']);

        $this->assertSame([], $scanner->findings(['hits' => [], 'core' => ['ran' => true, 'modified' => [], 'extra' => [], 'missing' => []], 'summary' => []]));
    }

    #[Test]
    public function quarantine_delegates_to_an_incident_and_protects_core_files(): void
    {
        $incidents = Mockery::mock(ContainerIncidentService::class);
        $incidents->shouldReceive('open')->once()
            ->withArgs(function ($ssh, $service, $deployment, $trigger, $kind, $files, $protected) {
                return $trigger === 'nightly'
                    && $kind === 'malware'
                    && array_column($files, 'path') === ['wp-content/uploads/x.php', 'index.php']
                    && in_array('index.php', $protected, true)
                    && in_array('wp-login.php', $protected, true);
            })
            ->andReturn(['id' => '20260914-120000-abc123', 'dir' => '/opt/x/incidents/20260914-120000-abc123', 'archive' => 'q.zip', 'removed' => ['wp-content/uploads/x.php'], 'skipped' => ['index.php'], 'bytes' => 42]);
        $scanner = new ContainerIntegrityScanner(app(WordPressCoreChecksumService::class), $incidents);

        $result = $scanner->quarantine(
            Mockery::mock(SSHService::class),
            new Service,
            new ContainerDeployment(['container_name' => 'x']),
            [
                ['path' => 'wp-content/uploads/x.php', 'reasons' => ['php_in_uploads'], 'size' => 1, 'mtime' => 1],
                ['path' => 'index.php', 'reasons' => ['core_checksum_mismatch', 'known_webshell_family'], 'size' => 1, 'mtime' => 1],
                ['path' => 'wp-content/qz.php', 'reasons' => ['random_name'], 'size' => 1, 'mtime' => 1],
            ],
            ContainerIntegrityScanner::AUTO_QUARANTINE_REASONS,
            'nightly',
        );

        $this->assertSame(['wp-content/uploads/x.php'], $result['moved']);
        $this->assertSame('20260914-120000-abc123', $result['incident']);
        $this->assertSame(['index.php'], $result['skipped']);

        $nothing = $scanner->quarantine(Mockery::mock(SSHService::class), new Service, new ContainerDeployment(['container_name' => 'x']), [], null);
        $this->assertNull($nothing['incident']);
    }

    #[Test]
    public function persist_records_the_scan_and_reports_paths_not_seen_before(): void
    {
        $service = Service::factory()->create(['service_meta' => ['integrity_scan' => ['hits' => [['path' => 'old.php', 'reasons' => ['signature']]]]]]);
        $scanner = app(ContainerIntegrityScanner::class);
        $result = [
            'hits' => [
                ['path' => 'old.php', 'reasons' => ['signature'], 'size' => 1, 'mtime' => 1],
                ['path' => 'new.php', 'reasons' => ['signature'], 'size' => 1, 'mtime' => 1],
                ['path' => 'gone.php', 'reasons' => ['php_in_uploads'], 'size' => 1, 'mtime' => 1],
                ['path' => 'dump.sql', 'reasons' => ['exposed_backup'], 'size' => 1, 'mtime' => 1],
            ],
            'core' => ['ran' => true, 'version' => '6.6.2', 'modified' => [], 'extra' => [], 'missing' => [], 'error' => null],
            'summary' => ['truncated' => false],
            'scanned_at' => '2026-09-14T03:20:00+00:00',
        ];

        $stored = $scanner->persist($service, $result, ['gone.php']);

        $this->assertSame(['new.php'], $stored['new_paths']);
        $this->assertSame(2, $stored['suspicious']);
        $meta = $service->fresh()->service_meta['integrity_scan'];
        $this->assertSame(['old.php', 'new.php', 'dump.sql'], array_column($meta['hits'], 'path'));
        $this->assertSame('6.6.2', $meta['core_version']);
        $this->assertSame(1, $meta['exposed_count']);
        $this->assertSame(1, $meta['quarantined_count']);
    }
}
