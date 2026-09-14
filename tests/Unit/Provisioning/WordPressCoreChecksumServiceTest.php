<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\WordPressCoreChecksumService;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WordPressCoreChecksumServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/talksasa-checksums-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function manifest_is_fetched_once_falls_back_to_en_us_and_rejects_junk(): void
    {
        Cache::flush();
        Http::fake([
            'api.wordpress.org/core/checksums/1.0/?version=6.6.2&locale=sw_KE' => Http::response('', 404),
            'api.wordpress.org/core/checksums/1.0/?version=6.6.2&locale=en_US' => Http::response(['checksums' => ['index.php' => '580c4406318ee35ca18c97f8c6359b7e', 'bad' => 'nope']]),
        ]);
        $service = app(WordPressCoreChecksumService::class);

        $manifest = $service->manifest('6.6.2', 'sw_KE');
        $this->assertSame('en_US', $manifest['locale']);
        $this->assertSame(['index.php' => '580c4406318ee35ca18c97f8c6359b7e'], $manifest['checksums']);

        $service->manifest('6.6.2', 'sw_KE');
        Http::assertSentCount(2);

        $this->assertNull($service->manifest('not-a-version'));
        $this->assertSame('6.6', $service->cleanVersion(' 6.6 '));
        $this->assertNull($service->cleanVersion('6.6.2; rm -rf /'));
    }

    #[Test]
    public function the_version_command_survives_the_shell(): void
    {
        File::ensureDirectoryExists($this->root.'/wp-includes');
        File::put($this->root.'/wp-includes/version.php', "<?php\n\$wp_version = '6.6.2';\n");
        File::put($this->root.'/wp-config.php', "<?php\ndefine( 'WPLANG', 'sw_KE' );\n");
        $service = app(WordPressCoreChecksumService::class);

        $output = (string) shell_exec('bash -c '.escapeshellarg($service->installedVersionCommand($this->root)).' 2>&1');
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->once()->andReturn($output);

        $this->assertSame(['version' => '6.6.2', 'locale' => 'sw_KE'], $service->installedVersion($ssh, $this->root));
    }

    #[Test]
    public function installed_version_and_locale_are_read_from_the_host(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->once()->andReturn("\$wp_version = '6.6.2';\ndefine( 'WPLANG', 'sw_KE' );\n");

        $this->assertSame(['version' => '6.6.2', 'locale' => 'sw_KE'], app(WordPressCoreChecksumService::class)->installedVersion($ssh, '/opt/x/app'));

        $blank = Mockery::mock(SSHService::class);
        $blank->shouldReceive('exec')->once()->andReturn('');
        $this->assertSame(['version' => null, 'locale' => 'en_US'], app(WordPressCoreChecksumService::class)->installedVersion($blank, '/opt/x/app'));
    }

    #[Test]
    public function manifest_is_uploaded_to_the_node_only_when_absent(): void
    {
        Cache::flush();
        Http::fake(['api.wordpress.org/*' => Http::response(['checksums' => ['index.php' => '580c4406318ee35ca18c97f8c6359b7e']])]);
        $service = app(WordPressCoreChecksumService::class);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/head -c 40/'), Mockery::any())->once()->andReturn('absent');
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/mkdir -p/'), Mockery::any())->once()->andReturn('');
        $ssh->shouldReceive('upload')->once()->withArgs(fn ($json, $path) => str_contains($json, '580c4406318ee35ca18c97f8c6359b7e') && $path === '/opt/talksasa/tools/cache/wp-checksums-6.6.2-en_US.json');
        $this->assertSame('/opt/talksasa/tools/cache/wp-checksums-6.6.2-en_US.json', $service->ensureManifestOnNode($ssh, '6.6.2'));

        $present = Mockery::mock(SSHService::class);
        $present->shouldReceive('exec')->once()->andReturn('present');
        $present->shouldNotReceive('upload');
        $this->assertNotNull($service->ensureManifestOnNode($present, '6.6.2'));
    }

    #[Test]
    public function restore_copies_only_the_named_files_from_the_release_archive(): void
    {
        $service = app(WordPressCoreChecksumService::class);
        $site = $this->root.'/app';
        File::ensureDirectoryExists($site.'/wp-admin/includes');
        File::put($site.'/index.php', 'MALWARE');
        File::put($site.'/wp-admin/includes/misc.php', 'changed');
        File::put($site.'/wp-content-keep.txt', 'keep');

        $release = $this->root.'/wordpress-6.6.2-no-content.zip';
        $zip = new \ZipArchive;
        $zip->open($release, \ZipArchive::CREATE);
        $zip->addFromString('wordpress/index.php', "<?php // official index\n");
        $zip->addFromString('wordpress/wp-admin/includes/misc.php', "<?php // official misc\n");
        $zip->addFromString('wordpress/wp-admin/includes/file.php', "<?php // official file\n");
        $zip->addFromString('wordpress/wp-login.php', "<?php // official login\n");
        $zip->close();

        $script = str_replace('os.chown(dest, 33, 33)', 'pass', $service->restoreScript());
        $command = 'python3 - '.escapeshellarg($release).' '.escapeshellarg($site).' index.php wp-admin/includes/misc.php wp-admin/includes/file.php ../outside.php nope.php'." <<'TALKSASA_PY'\n".$script."\nTALKSASA_PY\n";
        $output = (string) shell_exec('bash -c '.escapeshellarg($command).' 2>&1');

        $this->assertStringContainsString('__RESTORED__:3', $output);
        $this->assertStringContainsString('absent nope.php', $output);
        $this->assertSame("<?php // official index\n", File::get($site.'/index.php'));
        $this->assertSame("<?php // official misc\n", File::get($site.'/wp-admin/includes/misc.php'));
        $this->assertFileExists($site.'/wp-admin/includes/file.php');
        $this->assertFileDoesNotExist($site.'/wp-login.php', 'files that were not asked for are not written');
        $this->assertFileDoesNotExist($this->root.'/outside.php');
        $this->assertSame('keep', File::get($site.'/wp-content-keep.txt'));

        foreach ([$service->ensureReleaseArchiveCommand('6.6.2'), $service->restoreFilesCommand('6.6.2', '/opt/x/app', ['index.php'])] as $c) {
            $file = tempnam(sys_get_temp_dir(), 'cmd');
            File::put($file, $c);
            exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
            unlink($file);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }
}
