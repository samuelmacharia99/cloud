<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDoctorWordPressTreatments;
use App\Services\Provisioning\WordPressContainerHardeningService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The treatments and the convert's hygiene step share the same file edits; both
 * are exercised here against a fixture WordPress directory.
 */
class ContainerDoctorWordPressTreatmentsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/talksasa-wp-treat-'.uniqid();
        File::ensureDirectoryExists($this->root.'/wp-content/plugins/broken-plugin');
        File::ensureDirectoryExists($this->root.'/wp-content/plugins/akismet');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function every_command_parses_as_bash(): void
    {
        $treatments = new ContainerDoctorWordPressTreatments;
        foreach ([
            $treatments->disablePluginCommand('/opt/talksasa/containers/x/app', 'litespeed-cache'),
            $treatments->quarantineDropinsCommand('/opt/talksasa/containers/x/app', '20260914-120000'),
            $treatments->stripPrependCommand('/opt/talksasa/containers/x/app'),
            $treatments->purgePageCacheCommand('example.com'),
            (new WordPressContainerHardeningService)->buildMigratedRuntimeCleanupCommand('/opt/talksasa/containers/x/app'),
            (new WordPressContainerHardeningService)->ensureWpCliPharCommand(),
        ] as $command) {
            $file = $this->root.'/cmd-'.uniqid().'.sh';
            File::put($file, $command);
            exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    #[Test]
    public function disabling_a_plugin_moves_its_folder_aside(): void
    {
        File::put($this->root.'/wp-content/plugins/broken-plugin/broken-plugin.php', "<?php\n");
        $treatments = new ContainerDoctorWordPressTreatments;

        $output = (string) shell_exec('bash -c '.escapeshellarg($treatments->disablePluginCommand($this->root, 'broken-plugin')).' 2>&1');

        $this->assertStringContainsString('moved broken-plugin', $output);
        $this->assertDirectoryDoesNotExist($this->root.'/wp-content/plugins/broken-plugin');
        $this->assertFileExists($this->root.'/wp-content/plugins-disabled/broken-plugin/broken-plugin.php');
        $this->assertDirectoryExists($this->root.'/wp-content/plugins/akismet');

        $again = (string) shell_exec('bash -c '.escapeshellarg($treatments->disablePluginCommand($this->root, 'broken-plugin')).' 2>&1');
        $this->assertStringContainsString('missing', $again);
    }

    #[Test]
    public function stripping_the_prepend_removes_only_those_lines(): void
    {
        File::put($this->root.'/.user.ini', "auto_prepend_file = '/home/u/wordfence-waf.php'\nsession.save_path = \"/x\"\n");
        File::put($this->root.'/.htaccess', "php_value auto_prepend_file /home/u/wordfence-waf.php\nRewriteEngine On\n");

        shell_exec('bash -c '.escapeshellarg((new ContainerDoctorWordPressTreatments)->stripPrependCommand($this->root)).' 2>&1');

        $this->assertSame("session.save_path = \"/x\"\n", File::get($this->root.'/.user.ini'));
        $this->assertSame("RewriteEngine On\n", File::get($this->root.'/.htaccess'));
    }

    #[Test]
    public function quarantining_dropins_moves_cache_files_and_the_maintenance_lock(): void
    {
        File::put($this->root.'/wp-content/advanced-cache.php', "<?php // litespeed\n");
        File::put($this->root.'/.maintenance', "<?php\n");
        File::put($this->root.'/wp-content/plugins/akismet/akismet.php', "<?php\n");

        $output = (string) shell_exec('bash -c '.escapeshellarg((new ContainerDoctorWordPressTreatments)->quarantineDropinsCommand($this->root, '20260914-120000')).' 2>&1');

        $this->assertStringContainsString('wp-content/advanced-cache.php', $output);
        $this->assertStringContainsString('.maintenance', $output);
        $this->assertFileDoesNotExist($this->root.'/wp-content/advanced-cache.php');
        $this->assertFileDoesNotExist($this->root.'/.maintenance');
        $this->assertFileExists($this->root.'/wp-content/talksasa-disabled/20260914-120000/wp-content/advanced-cache.php');
        $this->assertFileExists($this->root.'/wp-content/plugins/akismet/akismet.php');
    }

    #[Test]
    public function the_plugin_that_wrote_a_drop_in_is_recognised_from_its_header(): void
    {
        $treatments = new ContainerDoctorWordPressTreatments;
        $this->assertSame('litespeed-cache', $treatments->dropinOwner("<?php\n/**\n * LiteSpeed Cache advanced cache\n */")['slug']);
        $this->assertSame('w3-total-cache', $treatments->dropinOwner('<?php // W3TC advanced cache')['slug']);
        $this->assertSame('redis-cache', $treatments->dropinOwner('<?php /* Plugin Name: Redis Object Cache Drop-In */')['slug']);
        $this->assertSame('wp-optimize', $treatments->dropinOwner("<?php if (!defined('WPO_CACHE_DIR')) {}")['slug']);
        $this->assertNull($treatments->dropinOwner('<?php // hand-written'));
        $this->assertNull($treatments->dropinOwner(''));
    }

    #[Test]
    public function wp_config_edits_cover_every_mode_and_keep_the_file_valid_php(): void
    {
        $cfg = $this->root.'/wp-config.php';
        File::put($cfg, implode("\n", [
            '<?php',
            "define('DB_NAME', 'x');",
            "define( 'WP_HOME', 'http://old.example.com' );",
            'define(\'WP_SITEURL\', "http://old.example.com");',
            "define('WP_CACHE', true);",
            "define( 'WP_DEBUG', false );",
            "define('ABSPATH', '/home/citych/public_html/');",
            "require_once ABSPATH . 'wp-settings.php';",
            '',
        ]));

        $this->assertStringContainsString('REMOVED WP_HOME=http://old.example.com', $this->runEdit('remove_url_constants'));
        $this->assertStringContainsString('CHANGED', $this->runEdit('unset_wp_cache'));
        $this->assertStringContainsString('CHANGED', $this->runEdit('abspath'));
        $this->assertStringContainsString('UNCHANGED', $this->runEdit('abspath'));
        $this->assertStringContainsString('CHANGED', $this->runEdit('enable_debug_log'));
        $this->assertStringContainsString('PRESENT', $this->runEdit('enable_debug_log'));

        $text = File::get($cfg);
        $this->assertStringNotContainsString('WP_HOME', $text);
        $this->assertStringNotContainsString('WP_SITEURL', $text);
        $this->assertStringContainsString("define('WP_CACHE', false)", $text);
        $this->assertStringContainsString("define('ABSPATH', __DIR__ . '/')", $text);
        $this->assertStringContainsString("define('WP_DEBUG_LOG', '/tmp/talksasa-wp-debug.log')", $text);
        $this->assertStringContainsString("define('WP_DEBUG_DISPLAY', false)", $text);
        $this->assertStringNotContainsString("define( 'WP_DEBUG', false )", $text, 'the old WP_DEBUG define is removed so nothing is defined twice');
        $this->assertSame(1, substr_count($text, "define('WP_DEBUG', true)"));
        exec(escapeshellcmd(PHP_BINARY).' -l '.escapeshellarg($cfg).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    #[Test]
    public function convert_hygiene_strips_prepend_quarantines_dropins_and_normalises_wp_config(): void
    {
        $hardening = new WordPressContainerHardeningService;
        File::put($this->root.'/.user.ini', "auto_prepend_file = \"/home/citych/wordfence-waf.php\"\nsession.save_path = \"/x\"\n");
        File::put($this->root.'/.htaccess', "php_value auto_prepend_file /home/citych/wordfence-waf.php\nRewriteEngine On\n");
        File::put($this->root.'/wp-content/advanced-cache.php', "<?php\n");
        File::put($this->root.'/.maintenance', '');
        File::put($this->root.'/wp-config.php', implode("\n", [
            '<?php',
            "define( 'WP_HOME', 'http://citychoiceproperties.com' );",
            "define('WP_CACHE', true);",
            "define('WP_DEBUG_DISPLAY', true);",
            "define('ABSPATH', '/home/citych/domains/citychoiceproperties.com/public_html/');",
            "require_once ABSPATH . 'wp-settings.php';",
            '',
        ]));

        $output = (string) shell_exec('bash -c '.escapeshellarg($hardening->buildMigratedRuntimeCleanupCommand($this->root)).' 2>&1');
        $this->assertStringContainsString('prepend:.user.ini', $output);
        $this->assertStringContainsString('prepend:.htaccess', $output);
        $this->assertStringContainsString('dropin:wp-content/advanced-cache.php', $output);
        $this->assertStringContainsString('dropin:.maintenance', $output);
        $this->assertSame("session.save_path = \"/x\"\n", File::get($this->root.'/.user.ini'));
        $this->assertFileDoesNotExist($this->root.'/wp-content/advanced-cache.php');

        $script = str_replace("'/var/www/html/wp-config.php'", var_export($this->root.'/wp-config.php', true), $hardening->migratedWpConfigNormalizeScript());
        File::put($this->root.'/normalize.php', "<?php\n".$script);
        $lines = (string) shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($this->root.'/normalize.php').' 2>&1');
        $this->assertStringContainsString('abspath:/home/citych/domains/citychoiceproperties.com/public_html/', $lines);
        $this->assertStringContainsString('wp_home:http://citychoiceproperties.com', $lines);
        $this->assertStringContainsString('wp_cache:off', $lines);
        $this->assertStringContainsString('debug_display:off', $lines);

        $text = File::get($this->root.'/wp-config.php');
        $this->assertStringContainsString("define('ABSPATH', __DIR__ . '/');", $text);
        $this->assertStringNotContainsString('WP_HOME', $text);
        $this->assertStringContainsString("define('WP_CACHE', false)", $text);

        // Second run: nothing left to change.
        $this->assertSame("DONE\n", (string) shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($this->root.'/normalize.php').' 2>&1'));

        // A clean config is untouched.
        File::put($this->root.'/wp-config.php', "<?php\ndefine('DB_NAME', 'x');\ndefine('ABSPATH', __DIR__ . '/');\n");
        $this->assertSame("DONE\n", (string) shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($this->root.'/normalize.php').' 2>&1'));
        $this->assertSame("<?php\ndefine('DB_NAME', 'x');\ndefine('ABSPATH', __DIR__ . '/');\n", File::get($this->root.'/wp-config.php'));
    }

    #[Test]
    public function uploads_ini_logs_fatals_and_the_node_tools_directory_is_mounted(): void
    {
        $hardening = new WordPressContainerHardeningService;

        $this->assertStringContainsString('log_errors = On', $hardening->uploadsIniContents());
        $this->assertStringContainsString('display_errors = Off', $hardening->uploadsIniContents());
        $this->assertSame('/opt/talksasa/tools:/opt/talksasa/tools:ro', $hardening->toolsVolumeMount());
        $this->assertSame('/opt/talksasa/tools/wp-cli.phar', $hardening->wpCliPharHostPath());
        $this->assertStringContainsString('wp-cli ready', $hardening->ensureWpCliPharCommand());
    }

    #[Test]
    public function deactivating_missing_plugins_edits_the_option_and_reads_it_back(): void
    {
        File::put($this->root.'/wp-content/plugins/akismet/akismet.php', "<?php\n");
        $stub = <<<'PHP'
<?php
define('WP_PLUGIN_DIR', __DIR__.'/wp-content/plugins');
$GLOBALS['__opts'] = ['active_plugins' => ['akismet/akismet.php', 'wordfence/wordfence.php', 'gone/gone.php']];
function get_option($n, $d = false) { return $GLOBALS['__opts'][$n] ?? $d; }
function update_option($n, $v) { $GLOBALS['__opts'][$n] = $v; return true; }
function get_site_option($n, $d = false) { return $d; }
function update_site_option($n, $v) { return true; }
function is_multisite() { return false; }
function wp_cache_delete($k, $g = '') {}
PHP;
        File::put($this->root.'/wp-load.php', $stub);
        $script = "<?php\nrequire ".var_export($this->root.'/wp-load.php', true).";\n".(new ContainerDoctorWordPressTreatments)->deactivateMissingPluginsScript();
        File::put($this->root.'/deactivate.php', $script);

        $output = (string) shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($this->root.'/deactivate.php').' 2>&1');

        $this->assertMatchesRegularExpression('/TALKSASA_DEACTIVATED=\{.*\}/', $output);
        preg_match('/TALKSASA_DEACTIVATED=(\{.*\})/', $output, $m);
        $parsed = json_decode($m[1], true);
        $this->assertSame(['wordfence/wordfence.php', 'gone/gone.php'], $parsed['removed']);
        $this->assertSame([], $parsed['still_missing']);
    }

    private function runEdit(string $mode): string
    {
        $script = str_replace(var_export('/var/www/html/wp-config.php', true), var_export($this->root.'/wp-config.php', true), (new ContainerDoctorWordPressTreatments)->wpConfigEditScript($mode));
        $file = $this->root.'/edit-'.$mode.'.php';
        File::put($file, "<?php\n".$script);

        return (string) shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($file).' 2>&1');
    }
}
