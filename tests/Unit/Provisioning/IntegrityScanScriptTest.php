<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerIntegrityScanner;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Runs resources/tools/integrity-scan.py the way the node does, against a
 * fixture WordPress tree. Every rule has a hit and a decoy that must stay quiet.
 */
class IntegrityScanScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/talksasa-scan-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function the_script_declares_the_version_the_scanner_uploads(): void
    {
        $scanner = app(ContainerIntegrityScanner::class);
        $this->assertGreaterThan(0, $scanner->localToolVersion());
        $this->assertSame((string) $scanner->localToolVersion(), trim((string) shell_exec('python3 '.escapeshellarg($scanner->localToolPath()).' --version')));
    }

    #[Test]
    public function every_rule_fires_on_its_hit_and_stays_quiet_on_its_decoy(): void
    {
        $tree = $this->root.'/app';
        $this->writeFixtureTree($tree);
        $manifest = $this->writeManifest($tree);

        [$hits, $summary] = $this->scan($tree, $manifest);

        $reasons = fn (string $path) => $hits[$path]['reasons'] ?? [];

        // Location rules.
        $this->assertContains('php_in_uploads', $reasons('wp-content/uploads/2024/01/shell.php'));
        $this->assertContains('htaccess_php_handler', $reasons('wp-content/uploads/.htaccess'));
        $this->assertContains('php_in_image', $reasons('wp-content/uploads/2024/01/logo.ico'));
        $this->assertArrayNotHasKey('wp-content/uploads/wpo/logs/index.php', $hits, 'a "silence is golden" index.php is not malware');
        $this->assertArrayNotHasKey('wp-content/uploads/2024/01/index.php', $hits);
        $this->assertArrayNotHasKey('wp-content/uploads/2024/01/photo.jpg', $hits);
        $this->assertArrayNotHasKey('wp-content/uploads/2024/01/brochure.zip', $hits, 'downloads under uploads are not exposed backups');

        // Name rules.
        $this->assertContains('unexpected_root_php', $reasons('iisgg8.php'));
        $this->assertContains('known_webshell_family', $reasons('wp-homes.php'));
        $this->assertContains('core_lookalike', $reasons('wp-homes.php'));
        $this->assertContains('known_webshell_family', $reasons('wp-content/uploads/alfacgiapi'));
        $this->assertContains('unexpected_mu_plugin', $reasons('wp-content/mu-plugins/xkzq9.php'));
        $this->assertArrayNotHasKey('wp-content/mu-plugins/talksasa-admin-sso.php', $hits);
        $this->assertContains('random_name', $reasons('wp-content/qzxvb7k.php'));
        $this->assertArrayNotHasKey('wp-content/advanced-cache.php', $hits, 'a drop-in is not a foreign file');

        // Content rules.
        $this->assertContains('signature', $reasons('iisgg8.php'));
        $this->assertContains('signature', $reasons('wp-content/plugins/akismet/evil.php'));
        $this->assertContains('obfuscated_code', $reasons('wp-content/plugins/akismet/dropper.php'));
        $this->assertArrayNotHasKey('wp-content/plugins/akismet/readme.php', $hits, 'eval( inside a comment is not a signature');
        $this->assertArrayNotHasKey('wp-content/plugins/akismet/class.akismet.php', $hits);
        $this->assertArrayNotHasKey('wp-content/talksasa-disabled/old/evil.php', $hits, 'already-quarantined files are not re-reported');

        // Core against the manifest.
        $this->assertContains('core_checksum_mismatch', $reasons('index.php'), 'a 576 KB index.php is a modified core file, whatever its name says');
        $this->assertContains('obfuscated_code', $reasons('index.php'));
        $this->assertContains('core_checksum_mismatch', $reasons('wp-admin/includes/misc.php'));
        $this->assertContains('core_unexpected_file', $reasons('wp-includes/utf8.php'));
        $this->assertContains('core_missing_file', $reasons('wp-admin/includes/file.php'));
        $this->assertArrayNotHasKey('wp-includes/functions.php', $hits);
        $this->assertArrayNotHasKey('wp-includes/ID3/getid3.php', $hits);
        $this->assertArrayNotHasKey('wp-login.php', $hits);

        // Exposed backups.
        $this->assertContains('exposed_backup', $reasons('wp-config.php.bak'));
        $this->assertContains('exposed_backup', $reasons('backup-2024.sql'));
        $this->assertContains('exposed_backup', $reasons('wp-content/backups/site.zip'));

        $this->assertTrue($summary['core']['verified']);
        $this->assertSame('6.6.2', $summary['core']['version']);
        $this->assertSame(2, $summary['core']['modified']);
        $this->assertFalse($summary['truncated']);
    }

    #[Test]
    public function hidden_characters_empty_cache_files_and_plugin_schema_files_are_judged_correctly(): void
    {
        $tree = $this->root.'/app';
        File::ensureDirectoryExists($tree.'/wp-content/cache/wpo-cache/x');
        File::ensureDirectoryExists($tree.'/wp-content/plugins/litespeed-cache/src/data_structure');
        File::ensureDirectoryExists($tree.'/wp-content/plugins/redux-framework/sample');
        File::ensureDirectoryExists($tree.'/wp-content/uploads/2024');
        File::put($tree.'/index.php', "<?php // core\n");
        File::put($tree."/\u{0456}ndex.php", '<?php eval(base64_decode("x"));');
        File::put($tree.'/wp-content/uploads/2024/shell.php ', '<?php echo 1;');
        File::put($tree.'/wp-content/cache/wpo-cache/index.php', '');
        File::put($tree.'/wp-content/cache/wpo-cache/x/index.php', '');
        File::put($tree.'/wp-content/uploads/2024/real.php', "<?php // real\n");
        File::put($tree.'/wp-content/plugins/litespeed-cache/src/data_structure/avatar.sql', 'CREATE TABLE x');
        File::put($tree.'/wp-content/plugins/litespeed-cache/src/data_structure/dump.sql', str_repeat('INSERT INTO wp_users VALUES (1);', 20000));
        File::put($tree.'/wp-content/plugins/redux-framework/sample/radio.php', "<?php echo 1;\n");

        [$hits] = $this->scan($tree, null);
        $reasons = fn (string $path) => $hits[$path]['reasons'] ?? [];

        $this->assertContains('deceptive_name', $reasons("\u{0456}ndex.php"));
        $this->assertContains('core_lookalike', $reasons("\u{0456}ndex.php"), 'a Cyrillic i folds to index.php');
        $this->assertContains('unexpected_root_php', $reasons("\u{0456}ndex.php"));
        $this->assertArrayNotHasKey('index.php', $hits, 'the real index.php is not reported');
        $this->assertContains('deceptive_name', $reasons('wp-content/uploads/2024/shell.php '));
        $this->assertArrayNotHasKey('wp-content/cache/wpo-cache/index.php', $hits, 'an empty index.php in a cache folder executes nothing');
        $this->assertArrayNotHasKey('wp-content/cache/wpo-cache/x/index.php', $hits);
        $this->assertContains('php_in_uploads', $reasons('wp-content/uploads/2024/real.php'));
        $this->assertArrayNotHasKey('wp-content/plugins/litespeed-cache/src/data_structure/avatar.sql', $hits, 'a plugin schema file is not an exposed dump');
        $this->assertContains('exposed_backup', $reasons('wp-content/plugins/litespeed-cache/src/data_structure/dump.sql'), 'a real dump inside a plugin folder still is');
        $this->assertArrayNotHasKey('wp-content/plugins/redux-framework/sample/radio.php', $hits, 'radio.php is a common legitimate name');
    }

    #[Test]
    public function without_a_manifest_core_falls_back_to_modification_times(): void
    {
        $tree = $this->root.'/app';
        $this->writeFixtureTree($tree);
        touch($tree.'/wp-includes/version.php', time() - 3 * 86400);
        touch($tree.'/wp-includes/functions.php', time() - 3 * 86400);
        touch($tree.'/wp-admin/admin.php', time() - 3600);

        [$hits, $summary] = $this->scan($tree, null);

        $this->assertContains('core_modified_after_install', $hits['wp-admin/admin.php']['reasons']);
        $this->assertArrayNotHasKey('wp-includes/functions.php', $hits);
        $this->assertFalse($summary['core']['verified']);
    }

    #[Test]
    public function a_non_wordpress_tree_only_gets_location_and_content_rules(): void
    {
        $tree = $this->root.'/app';
        File::ensureDirectoryExists($tree.'/public/uploads');
        File::put($tree.'/index.php', "<?php echo 'app';\n");
        File::put($tree.'/public/uploads/x.php', "<?php\n");
        File::put($tree.'/shell.php', "<?php system(\$_GET['c']);\n");

        [$hits] = $this->scan($tree, null, wordpress: false);

        $this->assertContains('php_in_uploads', $hits['public/uploads/x.php']['reasons']);
        $this->assertContains('signature', $hits['shell.php']['reasons']);
        $this->assertArrayNotHasKey('index.php', $hits);
        $this->assertArrayNotHasKey('unexpected_root_php', $hits['shell.php']['reasons']);
    }

    /**
     * @return array{0: array<string, array{path: string, reasons: list<string>, size: int, mtime: int}>, 1: array<string, mixed>}
     */
    private function scan(string $tree, ?string $manifest, bool $wordpress = true): array
    {
        $scanner = app(ContainerIntegrityScanner::class);
        $command = 'python3 '.escapeshellarg($scanner->localToolPath()).' --root '.escapeshellarg($tree).($wordpress ? ' --wordpress' : '').($manifest ? ' --manifest '.escapeshellarg($manifest) : '');
        $output = (string) shell_exec($command.' 2>&1');
        $hits = [];
        foreach ($scanner->parseScan($output) as $hit) {
            $hits[$hit['path']] = $hit;
        }

        return [$hits, $scanner->parseSummary($output)];
    }

    private function writeFixtureTree(string $t): void
    {
        foreach (['wp-admin/includes', 'wp-includes/ID3', 'wp-content/uploads/wpo/logs', 'wp-content/uploads/2024/01', 'wp-content/plugins/akismet', 'wp-content/mu-plugins', 'wp-content/uploads/alfacgiapi', 'wp-content/talksasa-disabled/old', 'wp-content/backups'] as $dir) {
            File::ensureDirectoryExists($t.'/'.$dir);
        }
        File::put($t.'/wp-content/uploads/wpo/logs/index.php', "<?php\n// Silence is golden.\n");
        File::put($t.'/wp-content/uploads/2024/01/index.php', '<?php');
        foreach (['wp-load.php', 'wp-login.php', 'wp-settings.php', 'xmlrpc.php', 'wp-config.php'] as $core) {
            File::put($t.'/'.$core, "<?php // core {$core}\n");
        }
        $blob = '';
        while (strlen($blob) < 576 * 1024) {
            $blob .= base64_encode(random_bytes(3000));
        }
        File::put($t.'/index.php', '<?php $x = "'.substr($blob, 0, 576 * 1024).'"; eval(gzinflate(base64_decode($x)));');
        File::put($t.'/wp-includes/version.php', "<?php \$wp_version = '6.6.2';\n");
        File::put($t.'/wp-includes/functions.php', "<?php // core\n");
        File::put($t.'/wp-includes/utf8.php', "<?php // not core\n");
        File::put($t.'/wp-includes/ID3/getid3.php', "<?php // core\n");
        File::put($t.'/wp-admin/admin.php', "<?php // core\n");
        File::put($t.'/wp-admin/includes/misc.php', "<?php // core\n");
        File::put($t.'/wp-content/plugins/akismet/readme.php', "<?php\n// never call eval(base64_decode( here, it is a comment\n/* also eval( in a block */\necho 'ok';\n");
        File::put($t.'/wp-content/plugins/akismet/class.akismet.php', "<?php\nclass Akismet { public static function init() { add_action('init', [self::class, 'run']); } }\n");
        File::put($t.'/wp-content/plugins/akismet/evil.php', "<?php eval(base64_decode('aGVsbG8='));\n");
        File::put($t.'/wp-content/plugins/akismet/dropper.php', '<?php $a="'.str_repeat('QUJD', 120).'"; $b = "base64" . "_decode"; $c = $b($a); ${"GLOBALS"}["x"] = $c; assert($c);');
        File::put($t.'/wp-content/uploads/2024/01/shell.php', "<?php echo 1;\n");
        File::put($t.'/wp-content/uploads/.htaccess', "AddHandler application/x-httpd-php .jpg\n");
        File::put($t.'/wp-content/uploads/2024/01/logo.ico', '<?php phpinfo();');
        File::put($t.'/wp-content/uploads/2024/01/photo.jpg', "\xff\xd8\xff\xe0JFIF real image");
        File::put($t.'/wp-content/uploads/2024/01/brochure.zip', "PK\x03\x04");
        File::put($t.'/wp-content/uploads/alfacgiapi/x.txt', 'x');
        File::put($t.'/iisgg8.php', "<?php system(\$_GET['c']);\n");
        File::put($t.'/wp-homes.php', "<?php echo 'hi';\n");
        File::put($t.'/wp-content/mu-plugins/talksasa-admin-sso.php', "<?php // sso\n");
        File::put($t.'/wp-content/mu-plugins/xkzq9.php', "<?php // foreign\n");
        File::put($t.'/wp-content/talksasa-disabled/old/evil.php', "<?php eval(base64_decode('aGVsbG8='));\n");
        File::put($t.'/wp-config.php.bak', "<?php define('DB_PASSWORD','x');");
        File::put($t.'/backup-2024.sql', 'INSERT INTO wp_users ...');
        File::put($t.'/wp-content/backups/site.zip', "PK\x03\x04");
        File::put($t.'/wp-content/qzxvb7k.php', "<?php // dropped\n");
        File::put($t.'/wp-content/advanced-cache.php', "<?php // drop-in\n");
    }

    private function writeManifest(string $t): string
    {
        $md5 = fn (string $rel) => md5_file($t.'/'.$rel);
        $manifest = ['version' => '6.6.2', 'checksums' => [
            'index.php' => 'deadbeefdeadbeefdeadbeefdeadbeef',
            'wp-load.php' => $md5('wp-load.php'),
            'wp-login.php' => $md5('wp-login.php'),
            'wp-settings.php' => $md5('wp-settings.php'),
            'xmlrpc.php' => $md5('xmlrpc.php'),
            'wp-includes/version.php' => $md5('wp-includes/version.php'),
            'wp-includes/functions.php' => $md5('wp-includes/functions.php'),
            'wp-includes/ID3/getid3.php' => $md5('wp-includes/ID3/getid3.php'),
            'wp-admin/admin.php' => $md5('wp-admin/admin.php'),
            'wp-admin/includes/misc.php' => 'ffffffffffffffffffffffffffffffff',
            'wp-admin/includes/file.php' => '00000000000000000000000000000000',
            'wp-content/themes/twentytwentyfour/style.css' => 'x',
            'readme.html' => 'y',
        ]];
        $path = $this->root.'/manifest.json';
        File::put($path, (string) json_encode($manifest));

        return $path;
    }
}
