<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDoctorWordPressAnalyzer;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDoctorWordPressAnalyzerTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/talksasa-wp-analyzer-'.uniqid();
        File::ensureDirectoryExists($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    #[Test]
    public function body_probe_classifies_each_failure_shape(): void
    {
        $analyzer = new ContainerDoctorWordPressAnalyzer;
        $trailer = fn (int $status, int $size, string $url = 'http://127.0.0.1:32001/', int $redirects = 0) => "\n__TALKSASA_HTTP__ status={$status} size={$size} url={$url} redirects={$redirects}";

        $this->assertSame('ok', $analyzer->classifyBody('<!doctype html><html><body>'.str_repeat('hello ', 100).'</body></html>'.$trailer(200, 700))['class']);
        $this->assertSame('blank', $analyzer->classifyBody(''.$trailer(200, 0))['class']);
        $this->assertSame('blank', $analyzer->classifyBody("\n\n".$trailer(200, 2))['class']);
        $this->assertSame('fatal_in_body', $analyzer->classifyBody('<b>Fatal error</b>: Uncaught Error: Call to undefined function foo() in /var/www/html/wp-content/plugins/x/x.php:3'.$trailer(500, 120))['class']);
        $this->assertSame('db_error', $analyzer->classifyBody('<html><body><h1>Error establishing a database connection</h1></body></html>'.$trailer(500, 400))['class']);
        $this->assertSame('install_redirect', $analyzer->classifyBody('<html><body>Welcome</body></html>'.$trailer(200, 3000, 'http://127.0.0.1:32001/wp-admin/install.php'))['class']);
        $this->assertSame('maintenance', $analyzer->classifyBody('<html><body>Briefly unavailable for scheduled maintenance. Check back in a minute.</body></html>'.$trailer(503, 300))['class']);
        $this->assertSame('redirect_loop', $analyzer->classifyBody(''.$trailer(301, 0, 'https://example.com/', 5))['class']);
        $this->assertSame('unreachable', $analyzer->classifyBody('curl: (7) Failed to connect')['class']);

        $classified = $analyzer->classifyBody('<html><body>Hi</body></html>'.$trailer(200, 30, 'http://127.0.0.1:32001/', 1));
        $this->assertSame(200, $classified['status']);
        $this->assertSame(30, $classified['size']);
        $this->assertSame(1, $classified['redirects']);
    }

    #[Test]
    public function probe_commands_and_scripts_are_well_formed(): void
    {
        $analyzer = new ContainerDoctorWordPressAnalyzer;

        $this->assertBashParses($analyzer->bodyProbeCommand('http://127.0.0.1:32001', 'example.com'));
        $this->assertBashParses($analyzer->runtimeProbeCommand('/opt/talksasa/containers/x', 'x'));
        $this->assertBashParses($analyzer->fatalLogCommand('x-wordpress'));
        $this->assertPhpLints($analyzer->runtimeProbeScript());
        $this->assertStringContainsString("-H 'Host: example.com'", $analyzer->bodyProbeCommand('http://127.0.0.1:32001', 'example.com'));
    }

    #[Test]
    public function runtime_probe_reports_a_fatal_from_a_plugin_and_still_emits_json(): void
    {
        $this->writeFixtureWordPress(withBrokenPlugin: true);
        $output = $this->runProbe();

        $runtime = (new ContainerDoctorWordPressAnalyzer)->parseRuntimeProbe($output);
        $this->assertNotNull($runtime, $output);
        $this->assertFalse($runtime['loaded']);
        $this->assertStringContainsString('undefined function', $runtime['fatal']['message']);
        $this->assertStringContainsString('wp-content/plugins/broken-plugin/broken-plugin.php', $runtime['fatal']['file']);
        $this->assertSame(['.user.ini', '/home/olduser/wordfence-waf.php'], [$runtime['prepend']['file'], $runtime['prepend']['value']]);
        $this->assertFalse($runtime['prepend_exists']);
        $this->assertSame(['advanced-cache.php', '.maintenance'], $runtime['dropins']);
    }

    #[Test]
    public function runtime_probe_reports_a_clean_boot_with_plugins_theme_and_constants(): void
    {
        $this->writeFixtureWordPress(withBrokenPlugin: false);
        $output = $this->runProbe();

        $runtime = (new ContainerDoctorWordPressAnalyzer)->parseRuntimeProbe($output);
        $this->assertNotNull($runtime, $output);
        $this->assertTrue($runtime['loaded']);
        $this->assertNull($runtime['fatal']);
        $this->assertSame(['akismet/akismet.php', 'gone-plugin/gone-plugin.php'], $runtime['active_plugins']);
        $this->assertSame(['gone-plugin/gone-plugin.php'], $runtime['missing_plugins']);
        $this->assertSame('twentytwentyfour', $runtime['active_theme']);
        $this->assertTrue($runtime['theme_exists']);
        $this->assertSame('http://old.example.com', $runtime['constants']['WP_HOME']);
        $this->assertSame('wp_', $runtime['table_prefix']);
        $this->assertTrue($runtime['options_table']);
    }

    #[Test]
    public function findings_name_the_plugin_behind_a_fatal_and_offer_to_disable_it(): void
    {
        $analyzer = new ContainerDoctorWordPressAnalyzer;
        $body = $analyzer->classifyBody("\n__TALKSASA_HTTP__ status=200 size=0 url=http://127.0.0.1:1/ redirects=0");
        $runtime = $this->runtime([
            'fatal' => ['message' => 'Uncaught Error: Call to undefined function litespeed_x()', 'file' => '/var/www/html/wp-content/plugins/litespeed-cache/litespeed-cache.php', 'line' => 40],
        ]);

        $findings = $analyzer->findings($body, $runtime, [], 'example.com', false);
        $ids = array_column($findings, 'id');

        $this->assertContains('wordpress_plugin_fatal', $ids);
        $plugin = $findings[array_search('wordpress_plugin_fatal', $ids, true)];
        $this->assertSame('critical', $plugin['severity']);
        $this->assertSame('disable_wordpress_plugin:litespeed-cache', $plugin['treat_action']);
        $this->assertNotContains('wordpress_blank_page', $ids, 'a blank page explained by a fatal must not also raise the generic blank finding');
    }

    #[Test]
    public function findings_cover_theme_fatal_prepend_dropins_urls_and_abspath(): void
    {
        $analyzer = new ContainerDoctorWordPressAnalyzer;
        $okBody = $analyzer->classifyBody('<!doctype html><html><body>'.str_repeat('x', 400).'</body></html>'."\n__TALKASA_IGNORED__\n__TALKSASA_HTTP__ status=200 size=430 url=http://127.0.0.1:1/ redirects=0");

        $theme = $analyzer->findings($okBody, $this->runtime([
            'fatal' => ['message' => 'Uncaught Error', 'file' => '/var/www/html/wp-content/themes/astra-child/functions.php', 'line' => 3],
        ]), [], 'example.com', false);
        $this->assertSame('switch_wordpress_theme_default', $theme[0]['treat_action']);
        $this->assertSame('wordpress_theme_fatal', $theme[0]['id']);

        $prepend = $analyzer->findings($okBody, $this->runtime([
            'prepend' => ['file' => '.user.ini', 'value' => '/home/user/wordfence-waf.php'],
            'prepend_exists' => false,
        ]), [], 'example.com', false);
        $this->assertContains('wordpress_stale_prepend', array_column($prepend, 'id'));

        $dropins = $analyzer->findings($okBody, $this->runtime([
            'dropins' => ['advanced-cache.php', 'object-cache.php'],
            'constants' => ['WP_CACHE' => true],
            'ext_redis' => false,
            'ext_memcached' => false,
        ]), [], 'example.com', false);
        $ids = array_column($dropins, 'id');
        $this->assertContains('wordpress_orphan_dropin', $ids);
        $this->assertSame('quarantine_wordpress_dropins', $dropins[array_search('wordpress_orphan_dropin', $ids, true)]['treat_action']);

        $urls = $analyzer->findings($okBody, $this->runtime([
            'constants' => ['WP_HOME' => 'http://old.example.com', 'WP_SITEURL' => 'http://old.example.com'],
        ]), [], 'new.example.com', false);
        $ids = array_column($urls, 'id');
        $this->assertContains('wordpress_hardcoded_urls', $ids);
        $this->assertSame('fix_wordpress_site_url', $urls[array_search('wordpress_hardcoded_urls', $ids, true)]['treat_action']);

        $abspath = $analyzer->findings($okBody, $this->runtime([
            'constants' => ['ABSPATH' => '/home/user/public_html/'],
        ]), [], 'example.com', false);
        $ids = array_column($abspath, 'id');
        $this->assertContains('wordpress_abspath_foreign', $ids);

        $clean = $analyzer->findings($okBody, $this->runtime([]), [], 'example.com', false);
        $this->assertSame([], array_filter($clean, fn ($f) => $f['severity'] === 'critical'), 'a healthy site raises no critical WordPress finding');
    }

    #[Test]
    public function an_unexplained_blank_page_offers_debug_logging_and_a_cache_purge(): void
    {
        $analyzer = new ContainerDoctorWordPressAnalyzer;
        $blank = $analyzer->classifyBody("\n__TALKSASA_HTTP__ status=200 size=0 url=http://127.0.0.1:1/ redirects=0");

        $findings = $analyzer->findings($blank, $this->runtime([]), [], 'example.com', true);
        $ids = array_column($findings, 'id');

        $this->assertContains('wordpress_blank_page', $ids);
        $this->assertContains('wordpress_page_cache_stale', $ids);
        $this->assertSame('enable_wordpress_debug_log', $findings[array_search('wordpress_blank_page', $ids, true)]['treat_action']);
        $this->assertSame('purge_wordpress_page_cache', $findings[array_search('wordpress_page_cache_stale', $ids, true)]['treat_action']);

        $checks = $analyzer->checks($blank, $this->runtime([]));
        $this->assertSame('blank', $checks['wordpress_body']);
        $this->assertTrue($checks['wordpress_loaded']);
        $this->assertNull($checks['wordpress_fatal']);
    }

    #[Test]
    public function slugs_from_treat_actions_are_validated(): void
    {
        $this->assertTrue(ContainerDoctorWordPressAnalyzer::isSafeSlug('litespeed-cache'));
        $this->assertTrue(ContainerDoctorWordPressAnalyzer::isSafeSlug('wp_rocket.2'));
        $this->assertFalse(ContainerDoctorWordPressAnalyzer::isSafeSlug('../wp-includes'));
        $this->assertFalse(ContainerDoctorWordPressAnalyzer::isSafeSlug('a b'));
        $this->assertFalse(ContainerDoctorWordPressAnalyzer::isSafeSlug(''));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function runtime(array $overrides): array
    {
        return array_merge([
            'loaded' => true,
            'fatal' => null,
            'active_plugins' => ['akismet/akismet.php'],
            'missing_plugins' => [],
            'active_theme' => 'twentytwentyfour',
            'theme_exists' => true,
            'dropins' => [],
            'constants' => ['WP_HOME' => null, 'WP_SITEURL' => null, 'WP_CACHE' => null, 'ABSPATH' => '/var/www/html/', 'WP_DEBUG' => false, 'WP_DEBUG_LOG' => null, 'WP_DEBUG_DISPLAY' => null, 'WP_CONTENT_DIR' => '/var/www/html/wp-content'],
            'prepend' => null,
            'prepend_exists' => null,
            'table_prefix' => 'wp_',
            'options_table' => true,
            'home' => 'https://example.com',
            'siteurl' => 'https://example.com',
            'ext_redis' => false,
            'ext_memcached' => false,
            'debug_log_tail' => [],
            'wp_config_exists' => true,
        ], $overrides);
    }

    private function writeFixtureWordPress(bool $withBrokenPlugin): void
    {
        $root = $this->fixtureRoot;
        File::ensureDirectoryExists($root.'/wp-content/plugins/akismet');
        File::ensureDirectoryExists($root.'/wp-content/themes/twentytwentyfour');
        File::put($root.'/wp-config.php', "<?php\ndefine('WP_HOME', 'http://old.example.com');\n");
        File::put($root.'/.user.ini', "auto_prepend_file = \"/home/olduser/wordfence-waf.php\"\n");
        File::put($root.'/wp-content/advanced-cache.php', "<?php // litespeed\n");
        File::put($root.'/.maintenance', "<?php \$upgrading = time();\n");
        File::put($root.'/wp-content/plugins/akismet/akismet.php', "<?php\n");
        File::put($root.'/wp-content/themes/twentytwentyfour/style.css', "/* theme */\n");

        $plugins = ['akismet/akismet.php', 'gone-plugin/gone-plugin.php'];
        if ($withBrokenPlugin) {
            File::ensureDirectoryExists($root.'/wp-content/plugins/broken-plugin');
            File::put($root.'/wp-content/plugins/broken-plugin/broken-plugin.php', "<?php\nthis_function_does_not_exist();\n");
            $plugins = ['broken-plugin/broken-plugin.php'];
        }

        $stub = <<<'PHP'
<?php
require __DIR__.'/wp-config.php';
define('ABSPATH', __DIR__.'/');
define('WP_PLUGIN_DIR', __DIR__.'/wp-content/plugins');
$GLOBALS['__fixture_plugins'] = %PLUGINS%;
function get_option(string $name, $default = null) {
    return match ($name) {
        'active_plugins' => $GLOBALS['__fixture_plugins'],
        'home', 'siteurl' => 'http://old.example.com',
        default => $default,
    };
}
final class FixtureTheme {
    public function get_stylesheet(): string { return 'twentytwentyfour'; }
    public function exists(): bool { return is_dir(__DIR__.'/wp-content/themes/twentytwentyfour'); }
}
function wp_get_theme(): FixtureTheme { return new FixtureTheme; }
final class FixtureWpdb {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public function prepare(string $sql, ...$args): string { return $sql; }
    public function get_var(string $sql) { return 'wp_options'; }
}
$wpdb = new FixtureWpdb;
foreach ($GLOBALS['__fixture_plugins'] as $plugin) {
    if (file_exists(WP_PLUGIN_DIR.'/'.$plugin)) { require WP_PLUGIN_DIR.'/'.$plugin; }
}
PHP;
        File::put($root.'/wp-load.php', str_replace('%PLUGINS%', var_export($plugins, true), $stub));
    }

    private function runProbe(): string
    {
        $script = str_replace("\$__root = '/var/www/html';", '$__root = '.var_export($this->fixtureRoot, true).';', (new ContainerDoctorWordPressAnalyzer)->runtimeProbeScript());
        $file = $this->fixtureRoot.'/probe.php';
        File::put($file, "<?php\n".$script);

        return (string) shell_exec(escapeshellcmd(PHP_BINARY).' -d display_errors=0 '.escapeshellarg($file).' 2>&1');
    }

    private function assertBashParses(string $command): void
    {
        $file = $this->fixtureRoot.'/cmd-'.uniqid().'.sh';
        File::put($file, $command);
        exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    private function assertPhpLints(string $script): void
    {
        $file = $this->fixtureRoot.'/lint-'.uniqid().'.php';
        File::put($file, "<?php\n".$script);
        exec(escapeshellcmd(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
