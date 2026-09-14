<?php

namespace Tests\Unit\Provisioning;

use App\Jobs\SendTelegramMonitorAlertJob;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerIncidentService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The incident service only issues shell commands and file uploads, so a fake
 * SSH session that runs them on this machine exercises the real flow: zip,
 * verify, delete, manifest, restore.
 */
class ContainerIncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/talksasa-incident-'.uniqid();
        File::ensureDirectoryExists($this->base.'/app/wp-content/uploads/2024');
        File::ensureDirectoryExists($this->base.'/app/wp-content/uploads/alfacgiapi');
        File::put($this->base.'/app/index.php', "<?php // core\n");
        File::put($this->base.'/app/wp-content/uploads/2024/shell.php', "<?php system(\$_GET['c']);\n");
        File::put($this->base.'/app/wp-content/uploads/alfacgiapi/perl.alfa', 'x');
        File::put($this->base.'/app/wp-content/uploads/2024/photo.jpg', 'jpeg');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    #[Test]
    public function open_zips_verifies_deletes_and_records_but_never_a_protected_file(): void
    {
        Bus::fake([SendTelegramMonitorAlertJob::class]);
        $service = Service::factory()->create();
        $deployment = new ContainerDeployment(['container_name' => 'site-wordpress', 'service_id' => $service->id]);
        $incidents = $this->localIncidents();

        $result = $incidents->open($this->localSsh(), $service, $deployment, 'doctor', 'malware', [
            ['path' => 'wp-content/uploads/2024/shell.php', 'reasons' => ['php_in_uploads', 'signature'], 'size' => 27, 'mtime' => 1700000000],
            ['path' => 'wp-content/uploads/alfacgiapi', 'reasons' => ['known_webshell_family'], 'size' => 60, 'mtime' => 1700000000],
            ['path' => 'index.php', 'reasons' => ['core_checksum_mismatch'], 'size' => 14, 'mtime' => 1700000000],
            ['path' => '../etc/passwd', 'reasons' => ['signature'], 'size' => 1, 'mtime' => 1],
        ], ['index.php']);

        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-[a-z0-9]{6}$/', $result['id']);
        $this->assertSame(['wp-content/uploads/2024/shell.php', 'wp-content/uploads/alfacgiapi'], $result['removed']);
        $this->assertSame(['index.php'], $result['skipped']);
        $this->assertGreaterThan(0, $result['bytes']);

        $dir = $this->base.'/incidents/'.$result['id'];
        $this->assertFileExists($dir.'/quarantine.zip');
        $this->assertFileExists($dir.'/manifest.json');
        $this->assertFileExists($dir.'/report.txt');
        $this->assertFileDoesNotExist($this->base.'/app/wp-content/uploads/2024/shell.php');
        $this->assertDirectoryDoesNotExist($this->base.'/app/wp-content/uploads/alfacgiapi');
        $this->assertFileExists($this->base.'/app/index.php', 'a protected core file is never deleted');
        $this->assertFileExists($this->base.'/app/wp-content/uploads/2024/photo.jpg');

        $manifest = json_decode(File::get($dir.'/manifest.json'), true);
        $this->assertSame('doctor', $manifest['trigger']);
        $this->assertSame(hash('sha256', "<?php system(\$_GET['c']);\n"), $manifest['files'][0]['sha256']);
        $this->assertTrue($manifest['files'][0]['removed']);
        $this->assertSame(['index.php'], $manifest['skipped_protected']);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($dir.'/quarantine.zip'));
        $this->assertNotFalse($zip->locateName('wp-content/uploads/2024/shell.php'));
        $this->assertNotFalse($zip->locateName('wp-content/uploads/alfacgiapi/perl.alfa'));
        $zip->close();

        $recorded = $service->fresh()->service_meta['security_incidents'][0];
        $this->assertSame($result['id'], $recorded['id']);
        $this->assertSame(2, $recorded['files']);
        $this->assertSame(['php_in_uploads' => 1, 'signature' => 1, 'known_webshell_family' => 1], $recorded['reasons']);
        $this->assertNull($recorded['restored_at']);
    }

    #[Test]
    public function restore_puts_the_archived_files_back_and_marks_the_incident(): void
    {
        $service = Service::factory()->create();
        $deployment = new ContainerDeployment(['container_name' => 'site-wordpress', 'service_id' => $service->id]);
        $incidents = $this->localIncidents();
        $ssh = $this->localSsh();

        $opened = $incidents->open($ssh, $service, $deployment, 'nightly', 'malware', [
            ['path' => 'wp-content/uploads/2024/shell.php', 'reasons' => ['php_in_uploads'], 'size' => 27, 'mtime' => 1],
        ]);
        $this->assertFileDoesNotExist($this->base.'/app/wp-content/uploads/2024/shell.php');

        $restored = $incidents->restore($ssh, $service, $deployment, $opened['id']);

        $this->assertSame(1, $restored['restored']);
        $this->assertFileExists($this->base.'/app/wp-content/uploads/2024/shell.php');
        $this->assertNotNull($service->fresh()->service_meta['security_incidents'][0]['restored_at']);
        $this->assertFileExists($this->base.'/incidents/'.$opened['id'].'/quarantine.zip', 'the archive stays after a restore');

        $this->expectException(\InvalidArgumentException::class);
        $incidents->restore($ssh, $service, $deployment, '../../etc');
    }

    #[Test]
    public function archive_only_mode_keeps_the_originals(): void
    {
        $service = Service::factory()->create();
        $deployment = new ContainerDeployment(['container_name' => 'site-wordpress', 'service_id' => $service->id]);

        $result = $this->localIncidents()->open($this->localSsh(), $service, $deployment, 'doctor', 'core', [
            ['path' => 'index.php', 'reasons' => ['core_checksum_mismatch'], 'size' => 14, 'mtime' => 1],
        ], [], [], deleteOriginals: false);

        $this->assertSame([], $result['removed']);
        $this->assertFileExists($this->base.'/app/index.php');
        $this->assertFileExists($this->base.'/incidents/'.$result['id'].'/quarantine.zip');
        $this->assertTrue($service->fresh()->service_meta['security_incidents'][0]['archived_only']);
    }

    #[Test]
    public function exposed_backups_are_moved_not_zipped_and_can_be_restored(): void
    {
        $service = Service::factory()->create();
        $deployment = new ContainerDeployment(['container_name' => 'site-wordpress', 'service_id' => $service->id]);
        File::put($this->base.'/app/ct.zip', str_repeat('x', 4096));
        $incidents = $this->localIncidents();
        $ssh = $this->localSsh();

        $result = $incidents->open($ssh, $service, $deployment, 'doctor', 'exposed', [
            ['path' => 'ct.zip', 'reasons' => ['exposed_backup'], 'size' => 4096, 'mtime' => 1],
        ], [], [], true, ContainerIncidentService::STORAGE_FILES);

        $this->assertSame(['ct.zip'], $result['removed']);
        $this->assertSame(4096, $result['bytes']);
        $this->assertFileDoesNotExist($this->base.'/app/ct.zip');
        $this->assertFileExists($this->base.'/incidents/'.$result['id'].'/files/ct.zip');
        $this->assertFileDoesNotExist($this->base.'/incidents/'.$result['id'].'/quarantine.zip');
        $row = $service->fresh()->service_meta['security_incidents'][0];
        $this->assertSame('files', $row['storage']);
        $this->assertTrue($incidents->downloadable($row));
        config(['containers.file_manager.max_archive_download_mb' => 0]);
        $this->assertTrue($incidents->downloadable($row), 'a zero cap still allows one megabyte');

        $staged = $incidents->stageForDownload($ssh, $deployment, $result['id']);
        $this->assertFileExists($staged);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($staged));
        $this->assertNotFalse($zip->locateName('ct.zip'));
        $zip->close();

        $restored = $incidents->restore($ssh, $service, $deployment, $result['id']);
        $this->assertSame(1, $restored['restored']);
        $this->assertFileExists($this->base.'/app/ct.zip');
        $this->assertDirectoryDoesNotExist($this->base.'/incidents/'.$result['id'].'/files');
    }

    #[Test]
    public function a_failed_archive_deletes_nothing(): void
    {
        $service = Service::factory()->create();
        $deployment = new ContainerDeployment(['container_name' => 'site-wordpress', 'service_id' => $service->id]);
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn('');
        $ssh->shouldReceive('execWithStatus')->andReturnUsing(function (string $command) {
            return str_contains($command, 'zipfile') ? ['output' => 'Traceback: disk full', 'status' => 1] : ['output' => '1', 'status' => 0];
        });
        $ssh->shouldNotReceive('upload');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nothing was deleted');
        $this->localIncidents()->open($ssh, $service, $deployment, 'doctor', 'malware', [
            ['path' => 'wp-content/uploads/2024/shell.php', 'reasons' => ['php_in_uploads'], 'size' => 27, 'mtime' => 1],
        ]);
    }

    #[Test]
    public function relative_paths_are_cleaned_and_traversal_rejected(): void
    {
        $incidents = app(ContainerIncidentService::class);
        $this->assertSame('wp-content/x.php', $incidents->cleanRelativePath('./wp-content/x.php'));
        $this->assertSame('wp-content/x.php', $incidents->cleanRelativePath('/wp-content/x.php'));
        $this->assertNull($incidents->cleanRelativePath('../x.php'));
        $this->assertNull($incidents->cleanRelativePath('wp-content/../../x.php'));
        $this->assertNull($incidents->cleanRelativePath(''));
    }

    private function localIncidents(): ContainerIncidentService
    {
        $incidents = Mockery::mock(ContainerIncidentService::class)->makePartial();
        $incidents->shouldReceive('incidentsDir')->andReturn($this->base.'/incidents');
        $incidents->shouldReceive('hostAppPath')->andReturn($this->base.'/app');
        $incidents->shouldReceive('scratchDir')->andReturn($this->base.'/scratch');

        return $incidents;
    }

    /**
     * An SSHService whose exec/execWithStatus/upload act on this machine.
     */
    private function localSsh(): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $run = function (string $command): array {
            $output = (string) shell_exec('bash -c '.escapeshellarg($command."\necho \"__RC__:$?\"").' 2>&1');
            preg_match('/__RC__:(\d+)\s*$/', $output, $m);

            return ['output' => trim((string) preg_replace('/__RC__:\d+\s*$/', '', $output)), 'status' => (int) ($m[1] ?? 1)];
        };
        $ssh->shouldReceive('exec')->andReturnUsing(fn (string $command) => $run($command)['output']);
        $ssh->shouldReceive('execWithStatus')->andReturnUsing($run);
        $ssh->shouldReceive('upload')->andReturnUsing(function (string $content, string $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $content);
        });
        $ssh->shouldReceive('disconnect');

        return $ssh;
    }
}
