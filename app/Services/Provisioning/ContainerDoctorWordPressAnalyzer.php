<?php

namespace App\Services\Provisioning;

/**
 * WordPress runtime checks for the Container Doctor.
 *
 * The HTTP chip only sees a status code, and a white screen is usually a 200
 * with nothing in it. This analyzer reads the body, loads WordPress inside the
 * container with a fatal catcher, and inspects the files a DirectAdmin site
 * drags along (prepend files, cache drop-ins, hard-coded constants), then turns
 * each cause into a finding with a one-click repair. Every probe is a command
 * string plus a parser so tests can run the parsers on canned output.
 */
class ContainerDoctorWordPressAnalyzer
{
    public const DOCROOT = '/var/www/html';

    public const DISABLED_PLUGINS_DIR = 'wp-content/plugins-disabled';

    public const DISABLED_DROPINS_DIR = 'wp-content/talksasa-disabled';

    public const BODY_OK = 'ok';

    public const BODY_BLANK = 'blank';

    public const BODY_FATAL = 'fatal_in_body';

    public const BODY_DB_ERROR = 'db_error';

    public const BODY_INSTALL = 'install_redirect';

    public const BODY_MAINTENANCE = 'maintenance';

    public const BODY_REDIRECT_LOOP = 'redirect_loop';

    public const BODY_UNREACHABLE = 'unreachable';

    private const TRAILER = '__TALKSASA_HTTP__';

    /** Drop-ins whose backend rarely exists inside a stock container. */
    private const CACHE_DROPINS = ['advanced-cache.php', 'object-cache.php'];

    /*
    |--------------------------------------------------------------------------
    | Probes
    |--------------------------------------------------------------------------
    */

    /**
     * Fetch the homepage on the node's loopback port, following redirects,
     * with a trailer that carries status, size and the final URL.
     */
    public function bodyProbeCommand(string $url, ?string $host): string
    {
        $hostArg = trim((string) $host) !== '' ? '-H '.escapeshellarg('Host: '.trim((string) $host)).' ' : '';

        return 'curl -sL --max-time 15 --max-redirs 5 -A '.escapeshellarg('Talksasa-Doctor/1.0').' '.$hostArg
            .'-w '.escapeshellarg("\n".self::TRAILER.' status=%{http_code} size=%{size_download} url=%{url_effective} redirects=%{num_redirects}')
            .' '.escapeshellarg(rtrim($url, '/').'/').' 2>/dev/null | head -c 300000 || true';
    }

    /**
     * @return array{class: string, status: ?int, size: int, url: string, redirects: int, snippet: string}
     */
    public function classifyBody(string $raw): array
    {
        $result = ['class' => self::BODY_UNREACHABLE, 'status' => null, 'size' => 0, 'url' => '', 'redirects' => 0, 'snippet' => ''];
        $position = strrpos($raw, self::TRAILER);
        if ($position === false) {
            return $result;
        }

        $trailer = substr($raw, $position);
        $body = substr($raw, 0, $position);
        if (preg_match('/status=(\d{3})/', $trailer, $m) === 1) {
            $result['status'] = (int) $m[1];
        }
        if (preg_match('/size=(\d+)/', $trailer, $m) === 1) {
            $result['size'] = (int) $m[1];
        }
        if (preg_match('/url=(\S+)/', $trailer, $m) === 1) {
            $result['url'] = $m[1];
        }
        if (preg_match('/redirects=(\d+)/', $trailer, $m) === 1) {
            $result['redirects'] = (int) $m[1];
        }
        $text = trim(strip_tags($body));
        $result['snippet'] = mb_substr(preg_replace('/\s+/', ' ', $text) ?? '', 0, 200);

        if ($result['status'] === null || $result['status'] === 0) {
            return $result;
        }

        $lower = strtolower($body);
        $result['class'] = match (true) {
            preg_match('/fatal error|parse error|uncaught (error|exception|typeerror)/i', $body) === 1 => self::BODY_FATAL,
            str_contains($lower, 'error establishing a database connection') => self::BODY_DB_ERROR,
            str_contains($result['url'], 'wp-admin/install.php') || str_contains($lower, 'wp-admin/install.php') => self::BODY_INSTALL,
            str_contains($lower, 'briefly unavailable for scheduled maintenance') => self::BODY_MAINTENANCE,
            $result['redirects'] >= 5 => self::BODY_REDIRECT_LOOP,
            $result['status'] >= 200 && $result['status'] < 400 && $this->looksBlank($body, $result['size']) => self::BODY_BLANK,
            default => self::BODY_OK,
        };

        return $result;
    }

    public function looksBlank(string $body, int $size): bool
    {
        if ($size < 256) {
            return true;
        }
        $lower = strtolower($body);

        return ! str_contains($lower, '<html') && ! str_contains($lower, '<body') && ! str_contains($lower, '<!doctype');
    }

    /**
     * Load WordPress with a fatal catcher and report the runtime shape.
     */
    public function runtimeProbeCommand(string $containerPath, string $appService): string
    {
        return 'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u www-data -T '.escapeshellarg($appService)
            .' php -d display_errors=0 -d memory_limit=256M -r '.escapeshellarg($this->runtimeProbeScript()).' 2>/dev/null || true';
    }

    public function runtimeProbeScript(): string
    {
        return <<<'PHP'
@ini_set('display_errors', '0');
error_reporting(E_ALL);
$__root = '/var/www/html';
$__t = ['loaded' => false, 'fatal' => null, 'active_plugins' => [], 'missing_plugins' => [], 'active_theme' => null, 'theme_exists' => null, 'dropins' => [], 'constants' => [], 'prepend' => null, 'prepend_exists' => null, 'table_prefix' => null, 'options_table' => null, 'home' => null, 'siteurl' => null, 'ext_redis' => extension_loaded('redis'), 'ext_memcached' => class_exists('Memcached'), 'debug_log_tail' => [], 'wp_config_exists' => is_file($__root.'/wp-config.php')];
$__emit = function () use (&$__t): void { echo "\nTALKSASA_WPRUNTIME=".json_encode($__t)."\n"; };
register_shutdown_function(function () use (&$__t, $__emit): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        $__t['fatal'] = ['message' => $e['message'], 'file' => $e['file'], 'line' => $e['line']];
    }
    if (! $__t['loaded']) { $__emit(); }
});
foreach (['.user.ini', 'php.ini', '.htaccess'] as $__f) {
    $__p = $__root.'/'.$__f;
    if (! is_file($__p)) { continue; }
    $__c = @file_get_contents($__p);
    if ($__c !== false && preg_match('/auto_prepend_file\s*[=\s]\s*["\']?([^"\'\s;]+)/i', $__c, $__m) === 1) {
        $__t['prepend'] = ['file' => $__f, 'value' => $__m[1]];
        $__t['prepend_exists'] = is_file($__m[1]) || is_file($__root.'/'.ltrim($__m[1], '/'));
        break;
    }
}
foreach (['advanced-cache.php', 'object-cache.php', 'db.php', 'sunrise.php', 'db-error.php', 'maintenance.php'] as $__d) {
    if (is_file($__root.'/wp-content/'.$__d)) { $__t['dropins'][] = $__d; }
}
if (is_file($__root.'/.maintenance')) { $__t['dropins'][] = '.maintenance'; }
foreach ([$__root.'/wp-content/debug.log', '/tmp/talksasa-wp-debug.log'] as $__dl) {
    if (is_file($__dl)) {
        $__lines = @file($__dl, FILE_IGNORE_NEW_LINES);
        if ($__lines) { $__t['debug_log_tail'] = array_slice($__lines, -20); }
        break;
    }
}
if (! $__t['wp_config_exists']) { $__t['loaded'] = true; $__emit(); exit(0); }
ob_start();
require $__root.'/wp-load.php';
ob_end_clean();
$__t['loaded'] = true;
foreach (['WP_HOME', 'WP_SITEURL', 'WP_CACHE', 'ABSPATH', 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'WP_CONTENT_DIR'] as $__c) {
    $__t['constants'][$__c] = defined($__c) ? constant($__c) : null;
}
global $wpdb;
$__t['table_prefix'] = $wpdb->prefix;
$__t['options_table'] = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->options));
$__t['home'] = (string) get_option('home');
$__t['siteurl'] = (string) get_option('siteurl');
foreach ((array) get_option('active_plugins', []) as $__pl) {
    $__t['active_plugins'][] = $__pl;
    if (! file_exists(WP_PLUGIN_DIR.'/'.$__pl)) { $__t['missing_plugins'][] = $__pl; }
}
$__theme = wp_get_theme();
$__t['active_theme'] = $__theme->get_stylesheet();
$__t['theme_exists'] = $__theme->exists();
$__emit();
PHP;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseRuntimeProbe(string $output): ?array
    {
        if (preg_match_all('/TALKSASA_WPRUNTIME=(\{.*\})/', $output, $matches) === 0) {
            return null;
        }
        $decoded = json_decode(end($matches[1]), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * PHP fatals in the container log, whatever the HTTP status is.
     */
    public function fatalLogCommand(string $containerName): string
    {
        return 'docker logs --tail 400 '.escapeshellarg($containerName).' 2>&1 | grep -E '
            .escapeshellarg('PHP (Fatal|Parse) error|Uncaught (Error|Exception|TypeError)').' | tail -n 6 || true';
    }

    /*
    |--------------------------------------------------------------------------
    | Findings
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{class: string, status: ?int, size: int, url: string, redirects: int, snippet: string}  $body
     * @param  array<string, mixed>|null  $runtime
     * @param  list<string>  $fatalLines
     * @return list<array<string, mixed>>
     */
    public function findings(array $body, ?array $runtime, array $fatalLines, ?string $servedHost, bool $pageCacheOn): array
    {
        $findings = [];
        $bodyClass = (string) ($body['class'] ?? self::BODY_UNREACHABLE);
        $siteBroken = in_array($bodyClass, [self::BODY_BLANK, self::BODY_FATAL, self::BODY_DB_ERROR, self::BODY_INSTALL, self::BODY_MAINTENANCE, self::BODY_REDIRECT_LOOP], true);
        $fatal = is_array($runtime['fatal'] ?? null) ? $runtime['fatal'] : null;
        $explained = false;

        if ($fatal) {
            $findings[] = $this->fatalFinding($fatal, $bodyClass);
            $explained = true;
        }

        $prepend = is_array($runtime['prepend'] ?? null) ? $runtime['prepend'] : null;
        if ($prepend && ($runtime['prepend_exists'] ?? null) === false) {
            $findings[] = [
                'id' => 'wordpress_stale_prepend',
                'severity' => 'critical',
                'title' => 'PHP is told to load a file that no longer exists before every request',
                'summary' => 'An auto_prepend_file setting (usually Wordfence\'s firewall) still points at the old hosting path. '
                    .'PHP cannot load it, so every page fails before WordPress starts. Removing the setting restores the site; '
                    .'Wordfence rebuilds it if the plugin is re-enabled.',
                'evidence' => [$prepend['file'].': auto_prepend_file = '.$prepend['value'].' (missing)'],
                'treat_action' => 'strip_wordpress_php_prepend',
                'treat_label' => 'Remove the stale prepend',
                'manual_steps' => ['Delete the auto_prepend_file line from '.$prepend['file'].' in the file manager.'],
                'source' => 'live',
            ];
            $explained = true;
        }

        $dropins = is_array($runtime['dropins'] ?? null) ? $runtime['dropins'] : [];
        if (in_array('.maintenance', $dropins, true)) {
            $findings[] = [
                'id' => 'wordpress_maintenance_lock',
                'severity' => 'critical',
                'title' => 'A leftover .maintenance file keeps the site in maintenance mode',
                'summary' => 'WordPress writes .maintenance during an update and removes it when done. One was left behind, so every visitor sees "Briefly unavailable".',
                'evidence' => ['/var/www/html/.maintenance exists'],
                'treat_action' => 'quarantine_wordpress_dropins',
                'treat_label' => 'Remove the maintenance lock',
                'manual_steps' => ['Delete .maintenance in the site root from the file manager.'],
                'source' => 'live',
            ];
            $explained = true;
        }

        $orphanDropins = $this->orphanDropins($dropins, $runtime ?? []);
        if ($orphanDropins !== []) {
            $findings[] = [
                'id' => 'wordpress_orphan_dropin',
                'severity' => $siteBroken ? 'critical' : 'warning',
                'title' => 'Cache drop-ins from the old server are still loaded',
                'summary' => 'wp-content contains '.implode(' and ', $orphanDropins).', which WordPress loads before any plugin. '
                    .'They were written by a caching plugin for the previous server (LiteSpeed, Redis, Memcached) and their backend '
                    .'does not exist here, so they can blank the site or serve stale pages. Quarantining moves them aside; the '
                    .'caching plugin can be reconfigured for this host afterwards.',
                'evidence' => array_map(fn ($d) => 'wp-content/'.$d, $orphanDropins),
                'treat_action' => 'quarantine_wordpress_dropins',
                'treat_label' => 'Quarantine the drop-ins',
                'manual_steps' => ['Move '.implode(', ', $orphanDropins).' out of wp-content, then deactivate the old caching plugin.'],
                'source' => 'live',
            ];
            $explained = $explained || $siteBroken;
        }

        $missingPlugins = is_array($runtime['missing_plugins'] ?? null) ? $runtime['missing_plugins'] : [];
        if ($missingPlugins !== []) {
            $findings[] = [
                'id' => 'wordpress_missing_plugin_dirs',
                'severity' => 'warning',
                'title' => count($missingPlugins).' active plugin(s) have no files on disk',
                'summary' => 'WordPress lists these plugins as active but their folders are gone, so it logs an error on every load and any feature they provided is missing.',
                'evidence' => array_slice($missingPlugins, 0, 8),
                'treat_action' => 'deactivate_missing_wordpress_plugins',
                'treat_label' => 'Deactivate missing plugins',
                'manual_steps' => ['In Terminal: wp plugin deactivate <slug> for each, or reinstall the plugin.'],
                'source' => 'live',
            ];
        }

        if (is_array($runtime) && ($runtime['theme_exists'] ?? null) === false && ! $fatal) {
            $findings[] = [
                'id' => 'wordpress_theme_missing',
                'severity' => 'critical',
                'title' => 'The active theme "'.(string) ($runtime['active_theme'] ?? '').'" is not installed',
                'summary' => 'WordPress cannot render pages without its theme folder. Switching to a bundled default theme brings the site back while the theme is reinstalled.',
                'evidence' => ['active theme: '.(string) ($runtime['active_theme'] ?? '')],
                'treat_action' => 'switch_wordpress_theme_default',
                'treat_label' => 'Switch to a default theme',
                'manual_steps' => ['Upload the theme folder to wp-content/themes, or activate another theme in Terminal: wp theme activate <slug>.'],
                'source' => 'live',
            ];
            $explained = true;
        }

        $constants = is_array($runtime['constants'] ?? null) ? $runtime['constants'] : [];
        $abspath = (string) ($constants['ABSPATH'] ?? '');
        if ($abspath !== '' && rtrim($abspath, '/') !== self::DOCROOT) {
            $findings[] = [
                'id' => 'wordpress_abspath_foreign',
                'severity' => 'critical',
                'title' => 'wp-config.php defines ABSPATH as a path from the old server',
                'summary' => 'ABSPATH is '.$abspath.' but WordPress lives in '.self::DOCROOT.' here. Includes resolve to a missing directory and the site fails to load.',
                'evidence' => ['ABSPATH = '.$abspath],
                'treat_action' => 'fix_wordpress_abspath',
                'treat_label' => 'Fix ABSPATH',
                'manual_steps' => ['Edit wp-config.php and set define(\'ABSPATH\', __DIR__ . \'/\');'],
                'source' => 'live',
            ];
            $explained = true;
        }

        $hardcoded = $this->hardcodedUrlMismatch($constants, $servedHost);
        if ($hardcoded !== null) {
            $findings[] = [
                'id' => 'wordpress_hardcoded_urls',
                'severity' => $siteBroken ? 'critical' : 'warning',
                'title' => 'wp-config.php hard-codes the site address to '.$hardcoded,
                'summary' => 'WP_HOME / WP_SITEURL constants override the address stored in the database, so every link, redirect and asset '
                    .'points at '.$hardcoded.' instead of '.$servedHost.'. A visitor is bounced to the old address or into a redirect loop.',
                'evidence' => array_values(array_filter([
                    isset($constants['WP_HOME']) ? 'WP_HOME = '.$constants['WP_HOME'] : null,
                    isset($constants['WP_SITEURL']) ? 'WP_SITEURL = '.$constants['WP_SITEURL'] : null,
                    'served as: '.$servedHost,
                ])),
                'treat_action' => 'fix_wordpress_site_url',
                'treat_label' => 'Fix site URLs',
                'manual_steps' => ['Remove the WP_HOME and WP_SITEURL defines from wp-config.php, then set Settings → General to the live address.'],
                'source' => 'live',
            ];
            $explained = $explained || $siteBroken;
        }

        if ($bodyClass === self::BODY_DB_ERROR) {
            $findings[] = [
                'id' => 'wordpress_db_error_page',
                'severity' => 'critical',
                'title' => 'Visitors see "Error establishing a database connection"',
                'summary' => 'WordPress cannot reach its database with the credentials in wp-config.php.',
                'evidence' => ['homepage body: '.($body['snippet'] ?: 'database error page')],
                'treat_action' => 'sync_database_credentials',
                'treat_label' => 'Repair database credentials',
                'manual_steps' => ['Compare DB_HOST/DB_NAME/DB_USER/DB_PASSWORD in wp-config.php with the Database tab.'],
                'source' => 'live',
            ];
            $explained = true;
        }

        if ($bodyClass === self::BODY_INSTALL) {
            $prefix = (string) ($runtime['table_prefix'] ?? '');
            $findings[] = [
                'id' => 'wordpress_install_redirect',
                'severity' => 'critical',
                'title' => 'WordPress offers a fresh install instead of the site',
                'summary' => 'The homepage redirects to wp-admin/install.php, which means WordPress finds no options table for its table prefix. '
                    .'Either the database import did not land or $table_prefix in wp-config.php does not match the imported tables.',
                'evidence' => array_values(array_filter([
                    $prefix !== '' ? 'table prefix: '.$prefix : null,
                    ($runtime['options_table'] ?? null) === false ? 'no '.$prefix.'options table' : null,
                ])),
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'Open the Database tab and check which prefix the imported tables use.',
                    'Set $table_prefix in wp-config.php to match, or re-import the database dump.',
                ],
                'source' => 'live',
            ];
            $explained = true;
        }

        if ($bodyClass === self::BODY_REDIRECT_LOOP && $hardcoded === null) {
            $findings[] = [
                'id' => 'wordpress_redirect_loop',
                'severity' => 'critical',
                'title' => 'The homepage redirects endlessly',
                'summary' => 'After five redirects the site still had not answered. That is usually an https/http mismatch between the stored site address and the proxy.',
                'evidence' => ['final URL: '.(string) ($body['url'] ?? ''), 'redirects: '.(int) ($body['redirects'] ?? 0)],
                'treat_action' => 'fix_wordpress_site_url',
                'treat_label' => 'Fix site URLs',
                'manual_steps' => ['Set home and siteurl to the https address the site is served on.'],
                'source' => 'live',
            ];
            $explained = true;
        }

        if ($fatalLines !== [] && ! $fatal) {
            $findings[] = [
                'id' => 'wordpress_php_fatal_logged',
                'severity' => $siteBroken ? 'critical' : 'warning',
                'title' => 'PHP fatal errors in the container log',
                'summary' => 'Apache logged PHP fatals for recent requests. The site loaded in the Doctor probe, so the fault is in a page, plugin hook or request the probe did not take.',
                'evidence' => array_map(fn ($l) => mb_substr(trim($l), 0, 240), array_slice($fatalLines, -4)),
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => ['Open Logs and find the "PHP Fatal error" line; the file path names the plugin or theme.'],
                'source' => 'live',
            ];
        }

        if (($bodyClass === self::BODY_BLANK || $bodyClass === self::BODY_FATAL) && ! $explained) {
            $findings[] = [
                'id' => 'wordpress_blank_page',
                'severity' => 'critical',
                'title' => $bodyClass === self::BODY_FATAL ? 'The homepage prints a PHP error' : 'The homepage is blank',
                'summary' => 'The container answers HTTP '.(int) ($body['status'] ?? 0).' with '.(int) ($body['size'] ?? 0).' bytes'
                    .($bodyClass === self::BODY_FATAL ? ' and a PHP error in the body.' : ' and no HTML, which is the white screen a visitor sees.')
                    .' WordPress loaded cleanly in the Doctor probe, so the fault happens while rendering the page: a theme template, '
                    .'a page builder, or a plugin that exits early.',
                'evidence' => array_values(array_filter([
                    'HTTP '.(int) ($body['status'] ?? 0).' · '.(int) ($body['size'] ?? 0).' bytes',
                    ($body['snippet'] ?? '') !== '' ? 'body: '.$body['snippet'] : null,
                ])),
                'treat_action' => 'enable_wordpress_debug_log',
                'treat_label' => 'Turn on debug logging',
                'manual_steps' => [
                    'Turn on debug logging, reload the site once, then run Doctor again: the failing file appears under evidence.',
                    'Rule out the theme: in Terminal, wp theme activate twentytwentyfour.',
                ],
                'source' => 'live',
            ];
        }

        $debugTail = is_array($runtime['debug_log_tail'] ?? null) ? $runtime['debug_log_tail'] : [];
        $debugFatals = array_values(array_filter($debugTail, fn ($l) => preg_match('/PHP (Fatal|Parse) error|Uncaught/i', (string) $l) === 1));
        if ($debugFatals !== []) {
            $findings[] = [
                'id' => 'wordpress_debug_log_fatal',
                'severity' => $siteBroken ? 'critical' : 'warning',
                'title' => 'debug.log records PHP fatal errors',
                'summary' => 'The most recent fatal in wp-content/debug.log names the failing file.',
                'evidence' => array_map(fn ($l) => mb_substr((string) $l, 0, 240), array_slice($debugFatals, -3)),
                'treat_action' => $this->treatmentForFatalLine((string) end($debugFatals)),
                'treat_label' => $this->treatmentForFatalLine((string) end($debugFatals)) ? 'Disable the failing plugin' : null,
                'manual_steps' => ['Open the file named in the error from the file manager.'],
                'source' => 'live',
            ];
        }

        if ($siteBroken && $pageCacheOn) {
            $findings[] = [
                'id' => 'wordpress_page_cache_stale',
                'severity' => 'info',
                'title' => 'The page cache may keep serving the broken page for up to a minute',
                'summary' => 'nginx caches successful pages for 60 seconds and keeps serving a stale copy while the app errors. After a repair, purge it so visitors see the fix at once.',
                'evidence' => [],
                'treat_action' => 'purge_wordpress_page_cache',
                'treat_label' => 'Purge page cache',
                'manual_steps' => [],
                'source' => 'live',
            ];
        }

        if ($siteBroken && is_array($runtime) && ! ($constants['WP_DEBUG_LOG'] ?? false)) {
            $findings[] = [
                'id' => 'wordpress_debug_logging_off',
                'severity' => 'info',
                'title' => 'Debug logging is off',
                'summary' => 'With WP_DEBUG_LOG on, WordPress writes every PHP error with its file and line to a log Doctor reads on the next scan. It stays out of the browser.',
                'evidence' => [],
                'treat_action' => 'enable_wordpress_debug_log',
                'treat_label' => 'Turn on debug logging',
                'manual_steps' => [],
                'source' => 'live',
            ];
        }

        return $findings;
    }

    /**
     * Chips for the live-check row.
     *
     * @param  array{class: string, status: ?int, size: int}  $body
     * @param  array<string, mixed>|null  $runtime
     * @return array<string, mixed>
     */
    public function checks(array $body, ?array $runtime): array
    {
        $fatal = is_array($runtime['fatal'] ?? null) ? $runtime['fatal'] : null;

        return [
            'wordpress_body' => $body['class'] ?? self::BODY_UNREACHABLE,
            'wordpress_body_size' => (int) ($body['size'] ?? 0),
            'wordpress_fatal' => $fatal ? $this->shortFatal($fatal) : null,
            'wordpress_loaded' => is_array($runtime) ? (bool) ($runtime['loaded'] ?? false) : null,
        ];
    }

    /**
     * Which plugin or theme a fatal's file path belongs to.
     *
     * @return array{kind: string, slug: string}|null
     */
    public function ownerOfPath(string $file): ?array
    {
        if (preg_match('#/wp-content/plugins/([^/]+)/#', $file, $m) === 1) {
            return ['kind' => 'plugin', 'slug' => $m[1]];
        }
        if (preg_match('#/wp-content/plugins/([^/]+)\.php$#', $file, $m) === 1) {
            return ['kind' => 'plugin', 'slug' => $m[1]];
        }
        if (preg_match('#/wp-content/themes/([^/]+)/#', $file, $m) === 1) {
            return ['kind' => 'theme', 'slug' => $m[1]];
        }
        if (preg_match('#/wp-content/mu-plugins/([^/]+)#', $file, $m) === 1) {
            return ['kind' => 'mu-plugin', 'slug' => $m[1]];
        }

        return null;
    }

    public static function isSafeSlug(string $slug): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}$/', $slug) === 1 && ! str_contains($slug, '..');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{message: string, file: string, line: int}  $fatal
     * @return array<string, mixed>
     */
    private function fatalFinding(array $fatal, string $bodyClass): array
    {
        $owner = $this->ownerOfPath((string) ($fatal['file'] ?? ''));
        $short = $this->shortFatal($fatal);
        $where = basename((string) ($fatal['file'] ?? '')).':'.(int) ($fatal['line'] ?? 0);

        if ($owner && $owner['kind'] === 'plugin') {
            return [
                'id' => 'wordpress_plugin_fatal',
                'severity' => 'critical',
                'title' => 'Plugin "'.$owner['slug'].'" crashes WordPress on load',
                'summary' => 'Loading WordPress dies inside the plugin, so every page is blank. Disabling it moves its folder to '
                    .self::DISABLED_PLUGINS_DIR.' where it can be restored from the file manager after an update.',
                'evidence' => [$short, 'at '.$where],
                'treat_action' => 'disable_wordpress_plugin:'.$owner['slug'],
                'treat_label' => 'Disable '.$owner['slug'],
                'manual_steps' => ['Rename wp-content/plugins/'.$owner['slug'].' from the file manager, then update or replace the plugin.'],
                'source' => 'live',
            ];
        }

        if ($owner && $owner['kind'] === 'theme') {
            return [
                'id' => 'wordpress_theme_fatal',
                'severity' => 'critical',
                'title' => 'Theme "'.$owner['slug'].'" crashes WordPress on load',
                'summary' => 'Loading WordPress dies inside the theme. Switching to a bundled default theme brings the site back while the theme is fixed.',
                'evidence' => [$short, 'at '.$where],
                'treat_action' => 'switch_wordpress_theme_default',
                'treat_label' => 'Switch to a default theme',
                'manual_steps' => ['In Terminal: wp theme activate twentytwentyfour, then repair or update '.$owner['slug'].'.'],
                'source' => 'live',
            ];
        }

        return [
            'id' => 'wordpress_php_fatal',
            'severity' => 'critical',
            'title' => 'WordPress dies with a PHP fatal error',
            'summary' => 'Loading WordPress stops with a fatal error outside any plugin or theme: '.$short,
            'evidence' => [$short, 'at '.$where, 'body: '.$bodyClass],
            'treat_action' => null,
            'treat_label' => null,
            'manual_steps' => ['Open the file named above in the file manager; a missing PHP extension or a corrupted core file is the usual cause.', 'Doctor can restore core files if the integrity check flags them.'],
            'source' => 'live',
        ];
    }

    /**
     * @param  array{message?: string, file?: string, line?: int}  $fatal
     */
    private function shortFatal(array $fatal): string
    {
        $message = preg_replace('/\s+/', ' ', (string) ($fatal['message'] ?? 'fatal error')) ?? '';

        return mb_substr(trim($message), 0, 220);
    }

    private function treatmentForFatalLine(string $line): ?string
    {
        $owner = $this->ownerOfPath($line);

        return $owner && $owner['kind'] === 'plugin' && self::isSafeSlug($owner['slug'])
            ? 'disable_wordpress_plugin:'.$owner['slug']
            : null;
    }

    /**
     * @param  list<string>  $dropins
     * @param  array<string, mixed>  $runtime
     * @return list<string>
     */
    private function orphanDropins(array $dropins, array $runtime): array
    {
        $orphans = [];
        foreach ($dropins as $dropin) {
            if (! in_array($dropin, self::CACHE_DROPINS, true)) {
                continue;
            }
            if ($dropin === 'object-cache.php' && (($runtime['ext_redis'] ?? false) || ($runtime['ext_memcached'] ?? false))) {
                continue;
            }
            $orphans[] = $dropin;
        }

        return $orphans;
    }

    /**
     * @param  array<string, mixed>  $constants
     */
    private function hardcodedUrlMismatch(array $constants, ?string $servedHost): ?string
    {
        $served = strtolower(trim((string) $servedHost));
        if ($served === '') {
            return null;
        }
        foreach (['WP_HOME', 'WP_SITEURL'] as $name) {
            $value = $constants[$name] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $host = strtolower((string) parse_url($value, PHP_URL_HOST));
            if ($host !== '' && $host !== $served && 'www.'.$host !== $served && $host !== 'www.'.$served) {
                return $value;
            }
        }

        return null;
    }
}
