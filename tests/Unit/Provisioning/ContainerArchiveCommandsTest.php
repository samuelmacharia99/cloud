<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerArchiveCommands;
use App\Services\SSH\SSHService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ContainerArchiveCommandsTest extends TestCase
{
    public function test_kind_recognises_the_archives_we_extract(): void
    {
        $this->assertSame('zip', ContainerArchiveCommands::kind('Site Backup.ZIP'));
        $this->assertSame('tgz', ContainerArchiveCommands::kind('site.tar.gz'));
        $this->assertSame('tgz', ContainerArchiveCommands::kind('site.tgz'));
        $this->assertSame('tar', ContainerArchiveCommands::kind('site.tar'));
        $this->assertNull(ContainerArchiveCommands::kind('site.rar'));
        $this->assertNull(ContainerArchiveCommands::kind('notes.gz'));
        $this->assertNull(ContainerArchiveCommands::kind('index.php'));
    }

    public function test_every_command_is_valid_bash_even_with_hostile_names(): void
    {
        $nasty = "/opt/talksasa/containers/x/app/it's \$(rm -rf) \"quoted\".zip";
        $commands = [
            ContainerArchiveCommands::inspect($nasty, 'zip', 1024),
            ContainerArchiveCommands::inspect($nasty, 'tgz', 1024),
            ContainerArchiveCommands::extract($nasty, 'zip', '/opt/talksasa/containers/x/app/dest dir', 1024),
            ContainerArchiveCommands::extract($nasty, 'tar', '/opt/talksasa/containers/x/app/dest', 1024),
            ContainerArchiveCommands::buildZip('/opt/base', ['a b', "c'd"], '/opt/tmp/out.zip'),
            ContainerArchiveCommands::buildTarGz('/opt/base', ['a b'], '/opt/tmp/out.tar.gz'),
            ContainerArchiveCommands::measure(['/opt/base/a b', '/opt/base/c']),
            ContainerArchiveCommands::chownTree('/opt/base/dest', '33:33'),
            ContainerArchiveCommands::pruneOlderThan('/opt/tmp', 60),
            ContainerArchiveCommands::pythonAvailable(),
        ];

        foreach ($commands as $command) {
            exec('bash -n -c '.escapeshellarg($command).' 2>&1', $output, $code);
            $this->assertSame(0, $code, "Not valid bash:\n".$command."\n".implode("\n", $output));
        }
    }

    public function test_heredoc_commands_end_with_a_newline_so_a_wrapping_subshell_closes_cleanly(): void
    {
        $command = ContainerArchiveCommands::extract('/a/b.zip', 'zip', '/a', 10);
        $this->assertStringEndsWith("TALKSASA_PY\n", $command);

        $wrapped = '( '.$command.' ); printf \'\n__TALKSASA_STATUS__:%d\' "$?"';
        exec('bash -n -c '.escapeshellarg($wrapped).' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_exec_with_status_parses_the_marker_and_never_throws_on_failure(): void
    {
        /** @var SSHService&MockInterface $ssh */
        $ssh = Mockery::mock(SSHService::class)->makePartial();
        $ssh->shouldReceive('exec')->once()->andReturn("UNSAFE ../evil\n__TALKSASA_STATUS__:3");

        $result = $ssh->execWithStatus('python3 - x', 5);

        $this->assertSame(3, $result['status']);
        $this->assertSame('UNSAFE ../evil', $result['output']);

        $ssh->shouldReceive('exec')->once()->andReturn('no marker at all');
        $this->assertSame(255, $ssh->execWithStatus('true', 5)['status']);
    }

    public function test_zip_script_rejects_traversal_symlinks_and_oversize_and_extracts_safe_archives(): void
    {
        if (! $this->pythonAvailable()) {
            $this->markTestSkipped('python3 is not installed on this machine');
        }

        $dir = sys_get_temp_dir().'/fm-zip-'.uniqid();
        mkdir($dir, 0700, true);
        $script = $dir.'/zip.py';
        file_put_contents($script, ContainerArchiveCommands::zipScript());

        try {
            // Safe archive: inspect then extract.
            $safe = $dir.'/safe.zip';
            $zip = new \ZipArchive;
            $zip->open($safe, \ZipArchive::CREATE);
            $zip->addFromString('index.html', '<h1>hi</h1>');
            $zip->addFromString('assets/app.css', 'body{}');
            $zip->close();

            [$code, $out] = $this->runScript($script, ['inspect', $safe, '/nonexistent', 1024 * 1024]);
            $this->assertSame(0, $code, $out);
            $this->assertMatchesRegularExpression('/^OK \d+ 2$/m', $out);

            $dest = $dir.'/out';
            [$code, $out] = $this->runScript($script, ['extract', $safe, $dest, 1024 * 1024]);
            $this->assertSame(0, $code, $out);
            $this->assertFileExists($dest.'/index.html');
            $this->assertFileExists($dest.'/assets/app.css');

            // Traversal entry.
            $evil = $dir.'/evil.zip';
            $zip = new \ZipArchive;
            $zip->open($evil, \ZipArchive::CREATE);
            $zip->addFromString('../../etc/evil.txt', 'x');
            $zip->close();
            [$code, $out] = $this->runScript($script, ['inspect', $evil, '/nonexistent', 1024]);
            $this->assertSame(3, $code);
            $this->assertStringContainsString('UNSAFE ../../etc/evil.txt', $out);

            // Absolute entry.
            $absolute = $dir.'/absolute.zip';
            $zip = new \ZipArchive;
            $zip->open($absolute, \ZipArchive::CREATE);
            $zip->addFromString('/etc/passwd', 'x');
            $zip->close();
            [$code, $out] = $this->runScript($script, ['inspect', $absolute, '/nonexistent', 1024]);
            $this->assertSame(3, $code);
            $this->assertStringContainsString('UNSAFE /etc/passwd', $out);

            // Symlink entry.
            $link = $dir.'/link.zip';
            $zip = new \ZipArchive;
            $zip->open($link, \ZipArchive::CREATE);
            $zip->addFromString('link', '/etc/passwd');
            $zip->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
            $zip->close();
            [$code, $out] = $this->runScript($script, ['inspect', $link, '/nonexistent', 1024]);
            $this->assertSame(3, $code);
            $this->assertStringContainsString('SYMLINK link', $out);

            // Over the cap.
            [$code, $out] = $this->runScript($script, ['inspect', $safe, '/nonexistent', 3]);
            $this->assertSame(4, $code);
            $this->assertStringContainsString('TOO_LARGE', $out);

            // Not a zip at all.
            file_put_contents($dir.'/bad.zip', 'this is not a zip');
            [$code, $out] = $this->runScript($script, ['inspect', $dir.'/bad.zip', '/nonexistent', 1024]);
            $this->assertSame(2, $code);
            $this->assertStringContainsString('BAD_ARCHIVE', $out);
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    public function test_build_zip_script_packs_relative_paths_and_skips_symlinks(): void
    {
        if (! $this->pythonAvailable()) {
            $this->markTestSkipped('python3 is not installed on this machine');
        }

        $dir = sys_get_temp_dir().'/fm-build-'.uniqid();
        mkdir($dir.'/base/site/css', 0700, true);
        file_put_contents($dir.'/base/site/index.html', 'x');
        file_put_contents($dir.'/base/site/css/a.css', 'y');
        file_put_contents($dir.'/base/readme.txt', 'z');
        symlink('/etc/passwd', $dir.'/base/site/leak');
        $script = $dir.'/build.py';
        file_put_contents($script, ContainerArchiveCommands::buildZipScript());

        try {
            [$code, $out] = $this->runScript($script, [$dir.'/out.zip', $dir.'/base', 'site', 'readme.txt']);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('OK 3', $out);

            $zip = new \ZipArchive;
            $zip->open($dir.'/out.zip');
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $this->assertContains('site/index.html', $names);
            $this->assertContains('site/css/a.css', $names);
            $this->assertContains('readme.txt', $names);
            $this->assertNotContains('site/leak', $names);
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    private function pythonAvailable(): bool
    {
        exec('command -v python3 2>/dev/null', $out, $code);

        return $code === 0;
    }

    /**
     * @param  list<string|int>  $args
     * @return array{0: int, 1: string}
     */
    private function runScript(string $script, array $args): array
    {
        $command = 'python3 '.escapeshellarg($script).' '.implode(' ', array_map(fn ($a) => escapeshellarg((string) $a), $args)).' 2>&1';
        exec($command, $output, $code);

        return [$code, implode("\n", $output)];
    }
}
