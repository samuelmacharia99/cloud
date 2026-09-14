<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\WordPressSecurityBaseline;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WordPressSecurityBaselineTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/talksasa-baseline-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function the_apache_conf_denies_php_in_data_folders_and_hides_secrets(): void
    {
        $baseline = new WordPressSecurityBaseline;
        $conf = $baseline->securityConfContents(true);

        $this->assertStringContainsString('<Directory /var/www/html/wp-content/uploads>', $conf);
        $this->assertStringContainsString('php_flag engine off', $conf);
        $this->assertStringContainsString('<Files "xmlrpc.php">', $conf);
        $this->assertStringContainsString('wp-config\.php', $conf);
        $this->assertStringContainsString('Options -Indexes', $conf);
        $this->assertStringContainsString(WordPressSecurityBaseline::CONF_VERSION, $conf);
        $this->assertStringNotContainsString('<Files "xmlrpc.php">', $baseline->securityConfContents(false, 'the jetpack plugin needs it'));
        $this->assertStringContainsString('xmlrpc.php left open: the jetpack plugin needs it', $baseline->securityConfContents(false, 'the jetpack plugin needs it'));
        $this->assertSame('/opt/talksasa/containers/x/php/talksasa-security.conf:/etc/apache2/conf-enabled/talksasa-security.conf:ro', $baseline->securityConfVolumeMount('x'));

        $apachectl = trim((string) shell_exec('command -v apache2ctl 2>/dev/null'));
        if ($apachectl !== '' && is_file('/etc/apache2/apache2.conf')) {
            $file = $this->root.'/sec.conf';
            File::put($file, $conf);
            exec($apachectl.' -t -f /etc/apache2/apache2.conf -C '.escapeshellarg('Include '.$file).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    #[Test]
    public function xmlrpc_stays_open_when_jetpack_is_installed_or_the_setting_says_so(): void
    {
        $baseline = new WordPressSecurityBaseline;

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->once()->andReturn("jetpack\n");
        $this->assertSame(['block' => false, 'reason' => 'the jetpack plugin needs it'], $baseline->xmlrpcDecision($ssh, '/opt/x/app'));

        $none = Mockery::mock(SSHService::class);
        $none->shouldReceive('exec')->once()->andReturn('');
        $this->assertTrue($baseline->xmlrpcDecision($none, '/opt/x/app')['block']);

        config(['containers.wordpress.block_xmlrpc' => false]);
        $this->assertFalse($baseline->xmlrpcDecision(Mockery::mock(SSHService::class), '/opt/x/app')['block']);
    }

    #[Test]
    public function the_compose_mount_is_added_once(): void
    {
        $baseline = new WordPressSecurityBaseline;
        $yaml = "services:\n  x:\n    image: wordpress:latest\n    volumes:\n      - /opt/talksasa/containers/x/app:/var/www/html\n";

        $patched = $baseline->patchComposeMount($yaml, 'x');
        $this->assertStringContainsString('talksasa-security.conf:/etc/apache2/conf-enabled/talksasa-security.conf:ro', $patched);
        $this->assertSame($patched, $baseline->patchComposeMount($patched, 'x'));
    }

    #[Test]
    public function probe_scripts_lint_and_parse(): void
    {
        $baseline = new WordPressSecurityBaseline;
        foreach ([$baseline->securityProbeScript(['example.com'], ['googleapis.com']), $baseline->rotateSaltsScript(), $baseline->stripInjectionScript()] as $script) {
            $file = $this->root.'/'.uniqid().'.php';
            File::put($file, "<?php\n".$script);
            exec(escapeshellcmd(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }

        $this->assertNull($baseline->parseSecurityProbe('nothing'));
        $this->assertSame(['loaded' => true], $baseline->parseSecurityProbe("x\nTALKSASA_WPSEC={\"loaded\":true}\n"));

        $updates = $baseline->parseUpdates("Success: WordPress is at the latest version.\n__SEP__\n[{\"name\":\"akismet\",\"version\":\"5.0\",\"update_version\":\"5.3\"}]\n__SEP__\n[]\n__SEP__\n6.6.2\n");
        $this->assertSame([], $updates['core']);
        $this->assertSame('akismet', $updates['plugins'][0]['name']);
        $this->assertSame('6.6.2', $updates['current']);
        $this->assertSame('7.0', $baseline->parseUpdates('[{"version":"7.0","update_type":"major"}]__SEP__[]__SEP__[]__SEP__6.6.2')['core'][0]['version']);
    }

    #[Test]
    public function the_security_probe_reports_keys_admins_and_injected_scripts(): void
    {
        $this->writeWordPressStub();
        $baseline = new WordPressSecurityBaseline;
        $script = str_replace("require '/var/www/html/wp-load.php';", 'require '.var_export($this->root.'/wp-load.php', true).';', $baseline->securityProbeScript(['citychoice.co.ke'], ['googleapis.com']));
        File::put($this->root.'/probe.php', "<?php\n".$script);

        $sec = $baseline->parseSecurityProbe((string) shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($this->root.'/probe.php').' 2>&1'));

        $this->assertNotNull($sec);
        $this->assertTrue($sec['loaded']);
        $this->assertSame(['NONCE_SALT'], $sec['salts']['missing']);
        $this->assertSame(['AUTH_KEY'], $sec['salts']['placeholder']);
        $this->assertTrue($sec['salts']['duplicate']);
        $this->assertFalse($sec['disallow_file_edit']);
        $this->assertTrue($sec['users_can_register']);
        $this->assertSame('administrator', $sec['default_role']);
        $this->assertSame(2, $sec['admin_count']);
        $this->assertSame(['evil.example'], $sec['injection']['domains']);
        $this->assertSame([['name' => 'widget_text', 'domains' => ['evil.example'], 'patterns' => []]], $sec['injection']['options']);
        $this->assertCount(1, $sec['injection']['posts']);
        $this->assertSame(7, $sec['injection']['posts'][0]['id']);
        $this->assertSame(['inline-script'], $sec['injection']['posts'][0]['patterns']);
    }

    #[Test]
    public function the_clean_up_removes_only_flagged_script_tags_and_backs_the_rows_up_first(): void
    {
        $this->writeWordPressStub();
        $baseline = new WordPressSecurityBaseline;
        $script = str_replace("require '/var/www/html/wp-load.php';", 'require '.var_export($this->root.'/wp-load.php', true).';', $baseline->stripInjectionScript());
        File::put($this->root.'/strip.php', "<?php\n".$script);

        $payload = json_encode(['options' => ['widget_text', 'home'], 'posts' => [7, 8], 'domains' => ['evil.example']]);
        $output = (string) shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($this->root.'/strip.php').' '.escapeshellarg($payload).' 2>&1');
        $parsed = $baseline->parseStripOutput($output);

        $this->assertNotNull($parsed['cleaned'], $output);
        $this->assertSame(['options' => 1, 'posts' => 1, 'tags' => 2], $parsed['cleaned']);
        $this->assertArrayHasKey('widget_text', $parsed['backup']['options']);
        $this->assertArrayHasKey(7, $parsed['backup']['posts']);

        $state = json_decode(File::get($this->root.'/state.json'), true);
        $this->assertStringNotContainsString('evil.example', $state['options']['widget_text']);
        $this->assertStringContainsString('<p>Welcome</p>', $state['options']['widget_text']);
        $this->assertStringNotContainsString('<script', $state['posts'][7]);
        $this->assertStringContainsString('<script src="https://www.citychoice.co.ke/app.js"></script>', $state['posts'][8], 'the site\'s own script survives');
    }

    #[Test]
    public function rotate_salts_writes_eight_fresh_keys_and_keeps_the_config_parseable(): void
    {
        Http::fake(['api.wordpress.org/secret-key/*' => Http::response(implode("\n", array_map(fn ($k) => "define('".$k."',         '".str_repeat('x', 40).$k."');", WordPressSecurityBaseline::SALT_KEYS)))]);
        $baseline = new WordPressSecurityBaseline;
        $salts = $baseline->freshSalts();
        $this->assertCount(8, $salts);
        $this->assertStringEndsWith('AUTH_KEY', $salts['AUTH_KEY']);

        File::put($this->root.'/wp-config.php', "<?php\ndefine('AUTH_KEY', 'put your unique phrase here');\ndefine( 'SECURE_AUTH_KEY', \"abc\" );\ndefine('DB_NAME', 'x');\n");
        $script = str_replace("'/var/www/html/wp-config.php'", var_export($this->root.'/wp-config.php', true), $baseline->rotateSaltsScript());
        File::put($this->root.'/salts.php', "<?php\n".$script);
        $output = (string) shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($this->root.'/salts.php').' '.escapeshellarg((string) json_encode($salts)).' 2>&1');

        $this->assertStringContainsString('ROTATED 8 replaced=2 inserted=6', $output);
        $config = File::get($this->root.'/wp-config.php');
        foreach (WordPressSecurityBaseline::SALT_KEYS as $key) {
            $this->assertStringContainsString("define('".$key."', '", $config);
        }
        $this->assertStringNotContainsString('put your unique phrase here', $config);
        exec(escapeshellcmd(PHP_BINARY).' -l '.escapeshellarg($this->root.'/wp-config.php').' 2>&1', $out, $code);
        $this->assertSame(0, $code);
    }

    #[Test]
    public function findings_cover_gaps_keys_registration_admins_injection_and_updates(): void
    {
        $baseline = new WordPressSecurityBaseline;
        $sec = [
            'loaded' => true,
            'salts' => ['missing' => [], 'placeholder' => ['AUTH_KEY'], 'short' => [], 'duplicate' => false],
            'disallow_file_edit' => false,
            'users_can_register' => true,
            'default_role' => 'administrator',
            'admins' => [
                ['id' => 1, 'login' => 'owner', 'email' => 'owner@citychoice.co.ke', 'registered' => '2020-01-01 00:00:00'],
                ['id' => 9, 'login' => 'wp-admin', 'email' => 'x@mail.ru', 'registered' => now()->subDays(2)->toDateTimeString()],
            ],
            'admin_count' => 2,
            'injection' => ['options' => [['name' => 'widget_text', 'domains' => ['evil.example'], 'patterns' => []]], 'posts' => [], 'domains' => ['evil.example']],
        ];
        $updates = ['core' => [['version' => '7.0', 'update_type' => 'major']], 'plugins' => [['name' => 'akismet', 'version' => '5.0', 'update_version' => '5.3']], 'themes' => [], 'current' => '6.6.2'];

        $findings = collect($baseline->findings($sec, $updates, ['conf_present' => false, 'conf_version_ok' => false, 'mount_persisted' => false]))->keyBy('id');

        $this->assertSame('harden_wordpress_runtime', $findings['wordpress_hardening_gaps']['treat_action']);
        $this->assertCount(2, $findings['wordpress_hardening_gaps']['evidence']);
        $this->assertSame('rotate_wordpress_security_keys', $findings['wordpress_salts_weak']['treat_action']);
        $this->assertSame('close_wordpress_registration', $findings['wordpress_open_registration_admin']['treat_action']);
        $this->assertStringContainsString('wp-admin <x@mail.ru>', $findings['wordpress_admin_review']['evidence'][0]);
        $this->assertStringNotContainsString('owner', implode("\n", $findings['wordpress_admin_review']['evidence']));
        $this->assertArrayNotHasKey('treat_action', $findings['wordpress_admin_review']);
        $this->assertSame('strip_wordpress_script_injection', $findings['wordpress_script_injection']['treat_action']);
        $this->assertSame('update_wordpress_core', $findings['wordpress_core_update_available']['treat_action']);
        $this->assertSame('warning', $findings['wordpress_core_update_available']['severity']);
        $this->assertSame('update_wordpress_extensions', $findings['wordpress_updates_available']['treat_action']);

        $clean = $baseline->findings([
            'loaded' => true, 'salts' => ['missing' => [], 'placeholder' => [], 'short' => [], 'duplicate' => false], 'disallow_file_edit' => true,
            'users_can_register' => false, 'default_role' => 'subscriber', 'admins' => $sec['admins'][0] ? [$sec['admins'][0]] : [], 'admin_count' => 1,
            'injection' => ['options' => [], 'posts' => [], 'domains' => []],
        ], ['core' => [], 'plugins' => [], 'themes' => [], 'current' => '6.6.2'], ['conf_present' => true, 'conf_version_ok' => true, 'mount_persisted' => true]);
        $this->assertSame([], $clean);
    }

    /**
     * A wp-load.php stand-in with just the WordPress surface the probes touch,
     * backed by a JSON state file so the clean-up test can read the writes back.
     */
    private function writeWordPressStub(): void
    {
        $state = [
            'options' => [
                'home' => 'https://www.citychoice.co.ke',
                'siteurl' => 'https://www.citychoice.co.ke',
                'users_can_register' => '1',
                'default_role' => 'administrator',
                'widget_text' => serialize(['2' => ['text' => '<p>Welcome</p><script src="//evil.example/x.js"></script>']]),
                'theme_mods_x' => serialize(['a' => '<script src="https://fonts.googleapis.com/x.js"></script>']),
            ],
            'posts' => [
                7 => '<p>Hi</p><script>eval(String.fromCharCode(1,2,3));</script>',
                8 => '<p>Own</p><script src="https://www.citychoice.co.ke/app.js"></script>',
            ],
        ];
        File::put($this->root.'/state.json', (string) json_encode($state));
        $stub = <<<'PHP'
<?php
define('AUTH_KEY', 'put your unique phrase here');
define('SECURE_AUTH_KEY', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
define('LOGGED_IN_KEY', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
define('NONCE_KEY', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
define('AUTH_SALT', 'ccccccccccccccccccccccccccccccccccccccccc');
define('SECURE_AUTH_SALT', 'ddddddddddddddddddddddddddddddddddddddddd');
define('LOGGED_IN_SALT', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['wp_version'] = '6.6.2';
$GLOBALS['__state_file'] = __DIR__.'/state.json';
$GLOBALS['__state'] = json_decode(file_get_contents($GLOBALS['__state_file']), true);
function __save() { file_put_contents($GLOBALS['__state_file'], json_encode($GLOBALS['__state'])); }
function get_option(string $name, $default = false) { return $GLOBALS['__state']['options'][$name] ?? $default; }
function update_option(string $name, $value) { $GLOBALS['__state']['options'][$name] = is_array($value) || is_object($value) ? serialize($value) : $value; __save(); return true; }
function maybe_unserialize($v) { return is_string($v) && preg_match('/^[aOs]:/', $v) ? unserialize($v) : $v; }
function maybe_serialize($v) { return is_array($v) || is_object($v) ? serialize($v) : $v; }
function wp_cache_delete($k, $g = '') { return true; }
function clean_post_cache($id) {}
function get_users(array $args) {
    return [
        (object) ['ID' => 1, 'user_login' => 'owner', 'user_email' => 'owner@citychoice.co.ke', 'user_registered' => '2020-01-01 00:00:00'],
        (object) ['ID' => 9, 'user_login' => 'wp-admin', 'user_email' => 'x@mail.ru', 'user_registered' => date('Y-m-d H:i:s')],
    ];
}
final class StubWpdb {
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';
    public function prepare(string $sql, ...$args): string { return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $sql), $args); }
    public function get_results(string $sql, $type = null): array {
        if (str_contains($sql, 'FROM wp_options')) {
            $rows = [];
            foreach ($GLOBALS['__state']['options'] as $k => $v) { if (str_contains((string) $v, '<script') || str_contains((string) $v, '<iframe')) { $rows[] = ['option_name' => $k, 'option_value' => $v]; } }
            return $rows;
        }
        $rows = [];
        foreach ($GLOBALS['__state']['posts'] as $id => $content) { if (str_contains($content, '<script')) { $rows[] = ['ID' => $id, 'post_title' => 'Post '.$id, 'post_type' => 'post', 'post_content' => $content]; } }
        return $rows;
    }
    public function get_var(string $sql) {
        if (preg_match("/option_name = '([^']+)'/", $sql, $m)) { return $GLOBALS['__state']['options'][$m[1]] ?? null; }
        return null;
    }
    public function get_row(string $sql, $type = null) {
        if (preg_match('/ID = (\d+)/', $sql, $m) && isset($GLOBALS['__state']['posts'][(int) $m[1]])) { return ['ID' => (int) $m[1], 'post_content' => $GLOBALS['__state']['posts'][(int) $m[1]]]; }
        return null;
    }
    public function update(string $table, array $data, array $where): int {
        if ($table === 'wp_options') { $GLOBALS['__state']['options'][$where['option_name']] = $data['option_value']; }
        else { $GLOBALS['__state']['posts'][(int) $where['ID']] = $data['post_content']; }
        __save();
        return 1;
    }
}
$wpdb = new StubWpdb;
PHP;
        File::put($this->root.'/wp-load.php', $stub);
    }
}
