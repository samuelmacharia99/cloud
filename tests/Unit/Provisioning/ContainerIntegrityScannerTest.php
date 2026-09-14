<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerIntegrityScanner;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerIntegrityScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/talksasa-integrity-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function every_command_parses_as_bash(): void
    {
        $scanner = new ContainerIntegrityScanner;

        foreach ([
            $scanner->scanCommand('/opt/talksasa/containers/x/app', true),
            $scanner->scanCommand('/opt/talksasa/containers/x/app', false),
            $scanner->checksumCommand('/opt/talksasa/containers/x', 'x'),
            $scanner->quarantineCommand('/opt/talksasa/containers/x/app', '/opt/talksasa/containers/x/.quarantine/1', ['a.php', 'wp-content/uploads/b.php']),
            $scanner->restoreCoreCommand('/opt/talksasa/containers/x', 'x'),
        ] as $command) {
            $this->assertBashParses($command);
        }
    }

    #[Test]
    public function scan_flags_webshells_and_foreign_files_but_not_core_or_decoys(): void
    {
        $this->writeFixtureTree();
        $scanner = new ContainerIntegrityScanner;

        $output = (string) shell_exec('bash -c '.escapeshellarg($scanner->scanCommand($this->root, true)).' 2>&1');
        $hits = collect($scanner->parseScan($output))->keyBy('path');

        $this->assertContains('php_in_uploads', $hits['wp-content/uploads/2024/01/shell.php']['reasons'] ?? []);
        $this->assertContains('htaccess_php_handler', $hits['wp-content/uploads/.htaccess']['reasons'] ?? []);
        $this->assertContains('php_in_image', $hits['wp-content/uploads/2024/01/logo.ico']['reasons'] ?? []);
        $this->assertContains('known_webshell_family', $hits['wp-homes.php']['reasons'] ?? []);
        $this->assertContains('known_webshell_family', $hits['wp-content/uploads/alfacgiapi']['reasons'] ?? []);
        $this->assertContains('signature', $hits['iisgg8.php']['reasons'] ?? []);
        $this->assertContains('unexpected_root_php', $hits['iisgg8.php']['reasons'] ?? []);
        $this->assertContains('signature', $hits['wp-content/plugins/akismet/evil.php']['reasons'] ?? []);
        $this->assertContains('random_name', $hits['wp-includes/qzxvb7k.php']['reasons'] ?? []);
        $this->assertContains('unexpected_mu_plugin', $hits['wp-content/mu-plugins/xkzq9.php']['reasons'] ?? []);
        $this->assertContains('core_modified_after_install', $hits['wp-includes/functions.php']['reasons'] ?? []);

        $this->assertArrayNotHasKey('wp-content/plugins/akismet/readme.php', $hits->all(), 'the word eval in a comment is not a signature');
        $this->assertArrayNotHasKey('wp-content/mu-plugins/talksasa-admin-sso.php', $hits->all());
        $this->assertArrayNotHasKey('wp-login.php', $hits->all());
        $this->assertArrayNotHasKey('wp-includes/pluggable.php', $hits->all(), 'untouched core with a readable name is clean');
        $this->assertArrayNotHasKey('wp-content/talksasa-disabled/old/evil.php', $hits->all(), 'already-quarantined drop-ins are not re-reported');
    }

    #[Test]
    public function random_name_heuristic_separates_dropper_names_from_core_names(): void
    {
        $scanner = new ContainerIntegrityScanner;

        foreach (['iisgg8.php', 'qzxvb7k.php', 'xkzq9.php', 'abc123.php', 'bxvtq.php', 'x7f2k.php'] as $name) {
            $this->assertTrue($scanner->looksRandom($name), $name.' should look random');
        }
        foreach (['pluggable.php', 'functions.php', 'getid3.php', 'smtp.php', 'l10n.php', 'kses.php', 'xmlrpc.php', 'shortcodes.php', 'canonical.php', 'widgets.php', 'index.php', 'class-wp-hook.php'] as $name) {
            $this->assertFalse($scanner->looksRandom($name), $name.' is a real WordPress name');
        }
    }

    #[Test]
    public function quarantine_preserves_paths_and_skips_traversal(): void
    {
        $this->writeFixtureTree();
        $scanner = new ContainerIntegrityScanner;
        $quarantine = $this->root.'-quarantine/20260914-1';

        $command = $scanner->quarantineCommand($this->root, $quarantine, ['wp-content/uploads/2024/01/shell.php', 'iisgg8.php', '../outside.php', 'missing.php']);
        $output = (string) shell_exec('bash -c '.escapeshellarg($command).' 2>&1');

        $this->assertStringContainsString('__MOVED__:2', $output);
        $this->assertFileExists($quarantine.'/wp-content/uploads/2024/01/shell.php');
        $this->assertFileExists($quarantine.'/iisgg8.php');
        $this->assertFileDoesNotExist($this->root.'/wp-content/uploads/2024/01/shell.php');
        $this->assertFileDoesNotExist($this->root.'/iisgg8.php');
        $this->assertFileExists($this->root.'/wp-login.php');

        File::deleteDirectory($this->root.'-quarantine');
    }

    #[Test]
    public function checksum_output_is_parsed_into_modified_and_extra_files(): void
    {
        $scanner = new ContainerIntegrityScanner;
        $parsed = $scanner->parseChecksums(implode("\n", [
            "Warning: File doesn't verify against checksum: wp-includes/pluggable.php",
            'Warning: File should not exist: wp-includes/qzxvb7k.php',
            'Error: WordPress installation doesn\'t verify against checksums.',
        ]));

        $this->assertSame(['wp-includes/pluggable.php'], $parsed['modified']);
        $this->assertSame(['wp-includes/qzxvb7k.php'], $parsed['extra']);
        $this->assertTrue($parsed['ran']);

        $this->assertFalse($scanner->parseChecksums('sh: wp: not found')['ran']);
    }

    #[Test]
    public function findings_split_suspicious_files_from_modified_core(): void
    {
        $scanner = new ContainerIntegrityScanner;
        $result = [
            'hits' => [
                ['path' => 'iisgg8.php', 'reasons' => ['signature', 'unexpected_root_php'], 'size' => 26, 'mtime' => 1700000000],
                ['path' => 'wp-includes/functions.php', 'reasons' => ['core_modified_after_install'], 'size' => 100, 'mtime' => 1700000000],
            ],
            'core' => ['modified' => [], 'extra' => [], 'ran' => false],
            'scanned_at' => '2026-09-14T00:00:00+00:00',
        ];

        $findings = collect($scanner->findings($result))->keyBy('id');
        $this->assertSame('quarantine_suspicious_files', $findings['integrity_suspicious_files']['treat_action']);
        $this->assertSame('critical', $findings['integrity_suspicious_files']['severity']);
        $this->assertStringContainsString('iisgg8.php', $findings['integrity_suspicious_files']['evidence'][0]);
        $this->assertStringContainsString('webshell signature', $findings['integrity_suspicious_files']['evidence'][0]);
        $this->assertSame('restore_wordpress_core', $findings['integrity_core_modified']['treat_action']);

        $result['core'] = ['modified' => [], 'extra' => [], 'ran' => true];
        $this->assertArrayNotHasKey('integrity_core_modified', collect($scanner->findings($result))->keyBy('id')->all(), 'a clean checksum pass outranks the mtime heuristic');

        $this->assertSame([], $scanner->findings(['hits' => [], 'core' => ['modified' => [], 'extra' => [], 'ran' => true]]));
    }

    private function writeFixtureTree(): void
    {
        $t = $this->root;
        foreach (['wp-admin', 'wp-includes', 'wp-content/uploads/2024/01', 'wp-content/plugins/akismet', 'wp-content/themes/twentytwentyfour', 'wp-content/mu-plugins', 'wp-content/uploads/alfacgiapi', 'wp-content/talksasa-disabled/old'] as $dir) {
            File::ensureDirectoryExists($t.'/'.$dir);
        }
        foreach (['index.php', 'wp-load.php', 'wp-login.php', 'wp-config.php', 'wp-settings.php', 'xmlrpc.php'] as $core) {
            File::put($t.'/'.$core, "<?php // core\n");
        }
        File::put($t.'/wp-includes/version.php', "<?php \$wp_version = '6.6';\n");
        File::put($t.'/wp-includes/functions.php', "<?php // core\n");
        File::put($t.'/wp-includes/pluggable.php', "<?php // core\n");
        File::put($t.'/wp-includes/qzxvb7k.php', "<?php // dropped\n");
        File::put($t.'/wp-admin/admin.php', "<?php // core\n");
        File::put($t.'/wp-content/plugins/akismet/readme.php', "<?php // this readme mentions eval( in a comment only\n");
        File::put($t.'/wp-content/plugins/akismet/evil.php', "<?php eval(base64_decode('aGVsbG8='));\n");
        File::put($t.'/wp-content/uploads/2024/01/shell.php', "<?php echo 1;\n");
        File::put($t.'/wp-content/uploads/.htaccess', "AddHandler application/x-httpd-php .jpg\n");
        File::put($t.'/wp-content/uploads/2024/01/logo.ico', '<?php phpinfo();');
        File::put($t.'/wp-content/uploads/alfacgiapi/x.txt', 'x');
        File::put($t.'/iisgg8.php', "<?php system(\$_GET['c']);\n");
        File::put($t.'/wp-homes.php', "<?php echo 'hi';\n");
        File::put($t.'/wp-content/mu-plugins/talksasa-admin-sso.php', "<?php // sso\n");
        File::put($t.'/wp-content/mu-plugins/xkzq9.php', "<?php // foreign\n");
        File::put($t.'/wp-content/talksasa-disabled/old/evil.php', "<?php eval(base64_decode('aGVsbG8='));\n");

        // Core files carry the install time; anything newer than version.php + 1h was touched after.
        $installed = time() - 3 * 86400;
        foreach (['wp-includes/version.php', 'wp-includes/pluggable.php', 'wp-admin/admin.php', 'index.php', 'wp-load.php', 'wp-login.php', 'wp-config.php', 'wp-settings.php', 'xmlrpc.php'] as $core) {
            touch($t.'/'.$core, $installed);
        }
        touch($t.'/wp-includes/functions.php', time() - 3600);
    }

    private function assertBashParses(string $command): void
    {
        $file = $this->root.'/cmd-'.uniqid().'.sh';
        File::put($file, $command);
        exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
