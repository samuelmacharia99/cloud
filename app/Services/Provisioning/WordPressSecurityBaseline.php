<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * The secure-runtime baseline every WordPress container gets, and the probes
 * that tell Doctor when a site has drifted from it: Apache rules that stop
 * PHP running where only data belongs, wp-config constants, security keys,
 * administrators, available updates and script injection in the database.
 */
class WordPressSecurityBaseline
{
    public const CONF_VERSION = 'TALKSASA_SECURITY_CONF_V1';

    public const CONF_NAME = 'talksasa-security.conf';

    public const CONF_CONTAINER_PATH = '/etc/apache2/conf-enabled/'.self::CONF_NAME;

    public const SALT_KEYS = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];

    private const SALT_API = 'https://api.wordpress.org/secret-key/1.1/salt/';

    public const SUSPICIOUS_LOGINS = ['admin', 'wp-admin', 'wpadmin', 'administrator', 'support', 'test', 'root', 'wp', 'user', 'backup', 'dev', 'demo', 'wp-support', 'wordpress', 'sysadmin', 'webmaster'];

    /*
    |--------------------------------------------------------------------------
    | Apache configuration
    |--------------------------------------------------------------------------
    */

    public function securityConfHostPath(string $containerName): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$containerName.'/php/'.self::CONF_NAME;
    }

    public function securityConfVolumeMount(string $containerName): string
    {
        return $this->securityConfHostPath($containerName).':'.self::CONF_CONTAINER_PATH.':ro';
    }

    public function securityConfContents(bool $blockXmlrpc, string $reason = ''): string
    {
        $docroot = ContainerDoctorWordPressAnalyzer::DOCROOT;
        $noExec = ['wp-content/uploads', 'wp-content/upgrade', 'wp-content/cache', 'wp-content/languages', 'wp-content/talksasa-disabled', 'wp-content/plugins-disabled', 'wp-content/uploads/sessions'];
        $lines = [
            '# Managed by Talksasa ('.self::CONF_VERSION.'). Do not edit; Container Doctor rewrites it.',
            '# PHP never runs where only data lives; secrets, backups and logs are never served.',
            'ServerSignature Off',
            'ServerTokens Prod',
            'TraceEnable Off',
            '<Directory '.$docroot.'>',
            '    Options -Indexes',
            '</Directory>',
        ];
        foreach ($noExec as $dir) {
            $lines[] = '<Directory '.$docroot.'/'.$dir.'>';
            $lines[] = '    <FilesMatch "\.(php|phtml|phar|pht|php[0-9]|inc|suspected|cgi|pl|py|sh)$">';
            $lines[] = '        Require all denied';
            $lines[] = '    </FilesMatch>';
            $lines[] = '    <IfModule mod_php.c>';
            $lines[] = '        php_flag engine off';
            $lines[] = '    </IfModule>';
            $lines[] = '</Directory>';
        }
        $lines = array_merge($lines, [
            '<DirectoryMatch "^'.$docroot.'/wp-includes/">',
            '    <FilesMatch "\.php$">',
            '        Require all denied',
            '    </FilesMatch>',
            '</DirectoryMatch>',
            '<Directory '.$docroot.'/wp-includes>',
            '    <Files "wp-tinymce.php">',
            '        Require all granted',
            '    </Files>',
            '    <Files "ms-files.php">',
            '        Require all granted',
            '    </Files>',
            '</Directory>',
            '<Directory '.$docroot.'>',
            '    <FilesMatch "^(wp-config\.php|\.user\.ini|php\.ini|\.htaccess|\.htpasswd|readme\.html|license\.txt|wp-config-sample\.php|\.env|error_log|debug\.log|php_errorlog)$">',
            '        Require all denied',
            '    </FilesMatch>',
            '    <FilesMatch "\.(sql|sql\.gz|bak|orig|old|swp|log|save|tar|tgz|tar\.gz|7z|rar|ini|conf|yml|yaml|env)$">',
            '        Require all denied',
            '    </FilesMatch>',
            '    <FilesMatch "^wp-config\.php\.">',
            '        Require all denied',
            '    </FilesMatch>',
            '</Directory>',
        ]);
        if ($blockXmlrpc) {
            $lines[] = '# xmlrpc.php is the brute-force and pingback abuse endpoint; no plugin here needs it.';
            $lines[] = '<Files "xmlrpc.php">';
            $lines[] = '    Require all denied';
            $lines[] = '</Files>';
        } else {
            $lines[] = '# xmlrpc.php left open: '.($reason !== '' ? $reason : 'a plugin needs it').'.';
        }
        $lines = array_merge($lines, [
            '<IfModule mod_headers.c>',
            '    Header always set X-Content-Type-Options "nosniff"',
            '    Header always set X-Frame-Options "SAMEORIGIN"',
            '    Header always set Referrer-Policy "strict-origin-when-cross-origin"',
            '</IfModule>',
            '',
        ]);

        return implode("\n", $lines);
    }

    /**
     * Whether xmlrpc.php should be reachable on this site.
     *
     * @return array{block: bool, reason: string}
     */
    public function xmlrpcDecision(SSHService $ssh, string $hostAppPath): array
    {
        if (! (bool) config('containers.wordpress.block_xmlrpc', true)) {
            return ['block' => false, 'reason' => 'platform setting keeps it open'];
        }
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $probe = trim((string) $ssh->exec('for p in jetpack jetpack-protect wordpress-mobile-app; do [ -d '.$root.'/wp-content/plugins/$p ] && echo "$p"; done; true', 15));
        if ($probe !== '') {
            return ['block' => false, 'reason' => 'the '.explode("\n", $probe)[0].' plugin needs it'];
        }

        return ['block' => true, 'reason' => ''];
    }

    /**
     * Write the conf on the host, make it live in the running container, and
     * persist the bind mount on the compose file for recreates. Returns what
     * happened so the caller can report it.
     *
     * @return array{written: bool, live: bool, xmlrpc_blocked: bool, mounted: bool, error: ?string}
     */
    public function ensureSecurityConf(SSHService $ssh, string $containerName): array
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$containerName;
        $hostAppPath = $containerPath.'/app';
        $hostPath = $this->securityConfHostPath($containerName);
        $decision = $this->xmlrpcDecision($ssh, $hostAppPath);
        $contents = $this->securityConfContents($decision['block'], $decision['reason']);

        $ssh->exec('mkdir -p '.escapeshellarg(dirname($hostPath)), 15);
        $ssh->upload($contents, $hostPath);
        $result = ['written' => true, 'live' => false, 'xmlrpc_blocked' => $decision['block'], 'mounted' => false, 'error' => null];

        try {
            $result['mounted'] = $this->persistMountOnCompose($ssh, $containerPath, $containerName);
        } catch (\Throwable $e) {
            Log::warning('Security conf mount not persisted', ['container' => $containerName, 'error' => $e->getMessage()]);
        }

        // Make it live now, without waiting for a recreate: copy into the
        // container (the bind mount, when present, already shows the new file),
        // test the configuration, then reload.
        $running = trim((string) $ssh->exec('cd '.escapeshellarg($containerPath).' && docker compose ps --status running --services 2>/dev/null | grep -qx '.escapeshellarg($containerName).' && echo yes || echo no', 20));
        if ($running !== 'yes') {
            return $result;
        }
        $apply = $ssh->execWithStatus(
            'cd '.escapeshellarg($containerPath)
            .' && (docker compose exec -u 0 -T '.escapeshellarg($containerName).' sh -c '.escapeshellarg('[ -f '.self::CONF_CONTAINER_PATH.' ] && grep -q '.self::CONF_VERSION.' '.self::CONF_CONTAINER_PATH.' && echo present').' | grep -q present'
            .' || docker compose cp '.escapeshellarg($hostPath).' '.escapeshellarg($containerName.':'.self::CONF_CONTAINER_PATH).')'
            .' && docker compose exec -u 0 -T '.escapeshellarg($containerName).' sh -c '.escapeshellarg('apache2ctl -t 2>&1 && (apache2ctl graceful >/dev/null 2>&1 || kill -USR1 1 2>/dev/null || true) && echo __LIVE__'),
            60
        );
        if (str_contains($apply['output'], '__LIVE__')) {
            $result['live'] = true;

            return $result;
        }

        // A bad configuration must never leave Apache down: remove it again.
        $result['error'] = trim(mb_substr($apply['output'], 0, 300));
        $ssh->exec(
            'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u 0 -T '.escapeshellarg($containerName).' sh -c '.escapeshellarg('rm -f '.self::CONF_CONTAINER_PATH.'; apache2ctl graceful >/dev/null 2>&1 || true').'; rm -f '.escapeshellarg($hostPath),
            30
        );
        $result['written'] = false;
        Log::warning('Security conf rejected by Apache and removed', ['container' => $containerName, 'error' => $result['error']]);

        return $result;
    }

    /**
     * Add the conf bind mount to docker-compose.yml when it is missing.
     */
    public function persistMountOnCompose(SSHService $ssh, string $containerPath, string $containerName): bool
    {
        $composePath = $containerPath.'/docker-compose.yml';
        $yaml = trim((string) $ssh->exec('cat '.escapeshellarg($composePath).' 2>/dev/null', 15));
        if ($yaml === '') {
            return false;
        }
        $patched = $this->patchComposeMount($yaml, $containerName);
        if ($patched !== $yaml) {
            $ssh->upload($patched, $composePath);
        }

        return true;
    }

    public function patchComposeMount(string $yaml, string $containerName): string
    {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return $yaml;
        }
        $key = app(ContainerDeploymentService::class)->resolveComposeAppServiceKey($compose, $containerName);
        if ($key === null || ! is_array($compose['services'][$key] ?? null)) {
            return $yaml;
        }
        $mount = $this->securityConfVolumeMount($containerName);
        $volumes = (array) ($compose['services'][$key]['volumes'] ?? []);
        if (in_array($mount, $volumes, true)) {
            return $yaml;
        }
        $volumes[] = $mount;
        $compose['services'][$key]['volumes'] = array_values($volumes);

        return Yaml::dump($compose, 10, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Probes
    |--------------------------------------------------------------------------
    */

    /**
     * PHP run inside the container: keys, constants, users, injected scripts.
     *
     * @param  list<string>  $ownHosts
     * @param  list<string>  $allowDomains
     */
    public function securityProbeScript(array $ownHosts, array $allowDomains): string
    {
        $own = var_export(array_values(array_unique(array_map('strtolower', $ownHosts))), true);
        $allow = var_export(array_values(array_unique(array_map('strtolower', $allowDomains))), true);
        $keys = var_export(self::SALT_KEYS, true);

        return <<<PHP
@ini_set('display_errors', '0');
error_reporting(0);
\$__own = {$own};
\$__allow = {$allow};
\$__keys = {$keys};
\$__t = ['loaded' => false, 'salts' => ['missing' => [], 'placeholder' => [], 'short' => [], 'duplicate' => false], 'disallow_file_edit' => null, 'users_can_register' => null, 'default_role' => null, 'admins' => [], 'admin_count' => 0, 'injection' => ['options' => [], 'posts' => [], 'domains' => [], 'checked_posts' => 0, 'checked_options' => 0], 'core_version' => null];
\$__emit = function () use (&\$__t) { echo "\\nTALKSASA_WPSEC=".json_encode(\$__t)."\\n"; };
register_shutdown_function(function () use (&\$__t, \$__emit) { if (! \$__t['loaded']) { \$__emit(); } });
ob_start();
require '/var/www/html/wp-load.php';
ob_end_clean();
\$__t['loaded'] = true;
\$__t['core_version'] = isset(\$GLOBALS['wp_version']) ? (string) \$GLOBALS['wp_version'] : null;
\$__seen = [];
foreach (\$__keys as \$__k) {
    if (! defined(\$__k)) { \$__t['salts']['missing'][] = \$__k; continue; }
    \$__v = (string) constant(\$__k);
    if (stripos(\$__v, 'put your unique phrase here') !== false || \$__v === '') { \$__t['salts']['placeholder'][] = \$__k; }
    elseif (strlen(\$__v) < 32) { \$__t['salts']['short'][] = \$__k; }
    if (isset(\$__seen[\$__v])) { \$__t['salts']['duplicate'] = true; }
    \$__seen[\$__v] = true;
}
\$__t['disallow_file_edit'] = defined('DISALLOW_FILE_EDIT') ? (bool) constant('DISALLOW_FILE_EDIT') : false;
\$__t['users_can_register'] = (bool) get_option('users_can_register');
\$__t['default_role'] = (string) get_option('default_role');
foreach ((array) get_users(['role' => 'administrator', 'number' => 200, 'fields' => ['ID', 'user_login', 'user_email', 'user_registered']]) as \$__u) {
    \$__t['admins'][] = ['id' => (int) \$__u->ID, 'login' => (string) \$__u->user_login, 'email' => (string) \$__u->user_email, 'registered' => (string) \$__u->user_registered];
}
\$__t['admin_count'] = count(\$__t['admins']);
global \$wpdb;
\$__host = function (\$url) { \$h = strtolower((string) parse_url((string) \$url, PHP_URL_HOST)); return \$h !== '' ? preg_replace('/^www\\./', '', \$h) : null; };
foreach ([get_option('home'), get_option('siteurl')] as \$__u) { \$__h = \$__host(\$__u); if (\$__h) { \$__own[] = \$__h; } }
\$__own = array_values(array_unique(\$__own));
\$__allowed = function (\$domain) use (\$__own, \$__allow) {
    \$domain = strtolower(preg_replace('/^www\\./', '', \$domain));
    foreach (\$__own as \$o) { if (\$domain === \$o || str_ends_with(\$domain, '.'.\$o)) { return true; } }
    foreach (\$__allow as \$a) { if (\$domain === \$a || str_ends_with(\$domain, '.'.\$a)) { return true; } }
    return false;
};
\$__inspect = function (\$text) use (\$__allowed, \$__own) {
    \$text = (string) \$text;
    \$domains = []; \$patterns = [];
    if (preg_match_all('/<script\\b[^>]*\\bsrc\\s*=\\s*["\\']?(?:https?:)?\\/\\/([^\\/"\\'\\s>]+)/i', \$text, \$m)) {
        foreach (\$m[1] as \$d) { if (! \$__allowed(\$d)) { \$domains[] = strtolower(\$d); } }
    }
    if (preg_match_all('/<script\\b[^>]*>(.*?)<\\/script>/is', \$text, \$m)) {
        foreach (\$m[1] as \$body) {
            if (preg_match('/\\beval\\s*\\(|String\\.fromCharCode\\s*\\(|\\batob\\s*\\(|document\\.write\\s*\\(\\s*unescape|\\\\\\\\x[0-9a-f]{2}\\\\\\\\x[0-9a-f]{2}\\\\\\\\x[0-9a-f]{2}|window\\.location\\s*=\\s*["\\']https?:\\/\\/(?!' . implode('|', array_map('preg_quote', \$__own)) . ')/i', \$body)) { \$patterns[] = 'inline-script'; break; }
        }
    }
    if (preg_match('/<iframe\\b[^>]*\\b(width|height)\\s*=\\s*["\\']?0/i', \$text)) { \$patterns[] = 'hidden-iframe'; }
    return [array_values(array_unique(\$domains)), array_values(array_unique(\$patterns))];
};
\$__rows = \$wpdb->get_results("SELECT option_name, option_value FROM {\$wpdb->options} WHERE option_value LIKE '%<script%' OR option_value LIKE '%<iframe%' LIMIT 300", ARRAY_A);
foreach ((array) \$__rows as \$__r) {
    \$__t['injection']['checked_options']++;
    [\$__d, \$__p] = \$__inspect(\$__r['option_value']);
    if (\$__d !== [] || \$__p !== []) { \$__t['injection']['options'][] = ['name' => (string) \$__r['option_name'], 'domains' => \$__d, 'patterns' => \$__p]; \$__t['injection']['domains'] = array_values(array_unique(array_merge(\$__t['injection']['domains'], \$__d))); }
}
foreach (['home', 'siteurl'] as \$__o) { \$__h = \$__host(get_option(\$__o)); if (\$__h && ! \$__allowed(\$__h)) { \$__t['injection']['options'][] = ['name' => \$__o, 'domains' => [\$__h], 'patterns' => ['redirect']]; } }
\$__rows = \$wpdb->get_results("SELECT ID, post_title, post_type, post_content FROM {\$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND (post_content LIKE '%<script%' OR post_content LIKE '%<iframe%') ORDER BY post_modified DESC LIMIT 500", ARRAY_A);
foreach ((array) \$__rows as \$__r) {
    \$__t['injection']['checked_posts']++;
    [\$__d, \$__p] = \$__inspect(\$__r['post_content']);
    if (\$__d !== [] || \$__p !== []) { \$__t['injection']['posts'][] = ['id' => (int) \$__r['ID'], 'title' => mb_substr((string) \$__r['post_title'], 0, 60), 'type' => (string) \$__r['post_type'], 'domains' => \$__d, 'patterns' => \$__p]; \$__t['injection']['domains'] = array_values(array_unique(array_merge(\$__t['injection']['domains'], \$__d))); }
}
\$__emit();
PHP;
    }

    /**
     * @param  list<string>  $ownHosts
     */
    public function securityProbeCommand(string $containerPath, string $appService, array $ownHosts): string
    {
        $allow = (array) config('containers.integrity.script_domain_allowlist', []);

        return 'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u www-data -T '.escapeshellarg($appService)
            .' php -d display_errors=0 -d memory_limit=256M -r '.escapeshellarg($this->securityProbeScript($ownHosts, $allow)).' 2>/dev/null || true';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseSecurityProbe(string $output): ?array
    {
        if (preg_match_all('/TALKSASA_WPSEC=(\{.*\})/', $output, $m) === 0) {
            return null;
        }
        $decoded = json_decode(end($m[1]), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function updatesCommand(string $containerPath, string $appService): string
    {
        $path = ContainerDoctorWordPressAnalyzer::DOCROOT;
        $inner = 'wp core check-update --format=json --path='.$path.' --skip-plugins --skip-themes 2>/dev/null; echo __SEP__; '
            .'wp plugin list --update=available --format=json --fields=name,version,update_version --path='.$path.' --skip-plugins --skip-themes 2>/dev/null; echo __SEP__; '
            .'wp theme list --update=available --format=json --fields=name,version,update_version --path='.$path.' --skip-plugins --skip-themes 2>/dev/null; echo __SEP__; '
            .'wp core version --path='.$path.' --skip-plugins --skip-themes 2>/dev/null';

        return 'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u www-data -T '.escapeshellarg($appService)
            .' sh -lc '.escapeshellarg($inner).' 2>/dev/null || true';
    }

    /**
     * @return array{core: list<array{version: string, update_type: string}>, plugins: list<array{name: string, version: string, update_version: string}>, themes: list<array{name: string, version: string, update_version: string}>, current: ?string}
     */
    public function parseUpdates(string $output): array
    {
        $parts = array_map('trim', explode('__SEP__', $output));
        $decode = function (string $json): array {
            $start = strpos($json, '[');
            if ($start === false) {
                return [];
            }
            $decoded = json_decode(substr($json, $start), true);

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        };

        return [
            'core' => $decode($parts[0] ?? ''),
            'plugins' => $decode($parts[1] ?? ''),
            'themes' => $decode($parts[2] ?? ''),
            'current' => preg_match('/^\d+\.\d+(\.\d+)?$/', trim($parts[3] ?? '')) === 1 ? trim($parts[3]) : null,
        ];
    }

    /**
     * What is live in the container right now: the conf present and active, DISALLOW_FILE_EDIT.
     *
     * @return array{conf_present: bool, conf_version_ok: bool, mount_persisted: bool}
     */
    public function hostState(SSHService $ssh, string $containerPath, string $containerName): array
    {
        $out = (string) $ssh->exec(
            'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u 0 -T '.escapeshellarg($containerName).' sh -c '.escapeshellarg('[ -f '.self::CONF_CONTAINER_PATH.' ] && echo conf_present; grep -q '.self::CONF_VERSION.' '.self::CONF_CONTAINER_PATH.' 2>/dev/null && echo conf_version_ok').' 2>/dev/null; '
            .'grep -q '.escapeshellarg(self::CONF_NAME).' '.escapeshellarg($containerPath.'/docker-compose.yml').' 2>/dev/null && echo mount_persisted; true',
            30
        );

        return [
            'conf_present' => str_contains($out, 'conf_present'),
            'conf_version_ok' => str_contains($out, 'conf_version_ok'),
            'mount_persisted' => str_contains($out, 'mount_persisted'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Findings
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>|null  $sec  parsed security probe
     * @param  array<string, mixed>|null  $updates  parsed wp-cli updates
     * @param  array{conf_present: bool, conf_version_ok: bool, mount_persisted: bool}  $host
     * @return list<array<string, mixed>>
     */
    public function findings(?array $sec, ?array $updates, array $host): array
    {
        $findings = [];

        $gaps = [];
        if (! $host['conf_present'] || ! $host['conf_version_ok']) {
            $gaps[] = 'Apache rules that stop PHP running under uploads and hide wp-config, backups and logs are '.($host['conf_present'] ? 'out of date' : 'not active');
        } elseif (! $host['mount_persisted']) {
            $gaps[] = 'The Apache rules are live but not persisted on the compose file, so a recreate would drop them';
        }
        if (is_array($sec) && ($sec['loaded'] ?? false) && ($sec['disallow_file_edit'] ?? false) !== true) {
            $gaps[] = 'The theme and plugin code editor in wp-admin is enabled (DISALLOW_FILE_EDIT), the usual next step after a stolen admin login';
        }
        if ($gaps !== []) {
            $findings[] = [
                'id' => 'wordpress_hardening_gaps',
                'severity' => 'warning',
                'title' => count($gaps).' hardening item(s) missing from this WordPress runtime',
                'summary' => 'The platform baseline blocks PHP execution under uploads and other data folders, denies direct access to wp-config, .user.ini, backups and logs, blocks xmlrpc.php unless a plugin needs it, and disables the in-admin code editor. Harden applies whatever is missing without touching the site\'s files.',
                'evidence' => $gaps,
                'treat_action' => 'harden_wordpress_runtime',
                'treat_label' => 'Harden runtime',
                'manual_steps' => ['Redeploy stack also applies the baseline.'],
                'source' => 'live',
            ];
        }

        if (is_array($sec) && ($sec['loaded'] ?? false)) {
            $salts = (array) ($sec['salts'] ?? []);
            $weak = array_merge((array) ($salts['missing'] ?? []), (array) ($salts['placeholder'] ?? []), (array) ($salts['short'] ?? []));
            if ($weak !== [] || ($salts['duplicate'] ?? false)) {
                $findings[] = [
                    'id' => 'wordpress_salts_weak',
                    'severity' => 'critical',
                    'title' => 'WordPress security keys are missing, default or reused',
                    'summary' => 'The eight keys in wp-config.php sign every login cookie. Missing or default keys let anyone forge an administrator session. Rotating them writes eight fresh keys and signs everyone out once.',
                    'evidence' => array_values(array_filter([
                        ($salts['missing'] ?? []) !== [] ? 'missing: '.implode(', ', $salts['missing']) : null,
                        ($salts['placeholder'] ?? []) !== [] ? 'default placeholder: '.implode(', ', $salts['placeholder']) : null,
                        ($salts['short'] ?? []) !== [] ? 'too short: '.implode(', ', $salts['short']) : null,
                        ($salts['duplicate'] ?? false) ? 'two or more keys share the same value' : null,
                    ])),
                    'treat_action' => 'rotate_wordpress_security_keys',
                    'treat_label' => 'Rotate security keys',
                    'manual_steps' => ['Paste fresh keys from api.wordpress.org/secret-key/1.1/salt/ into wp-config.php.'],
                    'source' => 'live',
                ];
            }

            if (($sec['users_can_register'] ?? false) && ($sec['default_role'] ?? 'subscriber') === 'administrator') {
                $findings[] = [
                    'id' => 'wordpress_open_registration_admin',
                    'severity' => 'critical',
                    'title' => 'Anyone can register and becomes an administrator',
                    'summary' => 'Settings → General has "Anyone can register" on and the default role set to Administrator. Every visitor who signs up owns the site. Closing it sets the default role to Subscriber; registration itself stays as configured.',
                    'evidence' => ['users_can_register: on', 'default_role: administrator'],
                    'treat_action' => 'close_wordpress_registration',
                    'treat_label' => 'Set default role to Subscriber',
                    'manual_steps' => ['wp-admin → Settings → General → New User Default Role.'],
                    'source' => 'live',
                ];
            }

            $flagged = $this->suspiciousAdmins((array) ($sec['admins'] ?? []));
            if ($flagged !== []) {
                $findings[] = [
                    'id' => 'wordpress_admin_review',
                    'severity' => 'warning',
                    'title' => count($flagged).' administrator account(s) worth a look',
                    'summary' => 'Attackers add an administrator to keep access after malware is removed. These accounts were created recently or use a name attackers pick; Doctor never deletes users, so confirm each one is yours in wp-admin → Users.',
                    'evidence' => array_map(fn ($a) => $a['login'].' <'.$a['email'].'> registered '.$a['registered'].' · '.$a['why'], array_slice($flagged, 0, 10)),
                    'manual_steps' => [
                        'Delete any administrator you do not recognise and attribute their content to your own account.',
                        'Then rotate security keys from Doctor so old sessions end.',
                    ],
                    'source' => 'live',
                ];
            }

            $inj = (array) ($sec['injection'] ?? []);
            $options = (array) ($inj['options'] ?? []);
            $posts = (array) ($inj['posts'] ?? []);
            if ($options !== [] || $posts !== []) {
                $evidence = [];
                foreach (array_slice($options, 0, 6) as $o) {
                    $evidence[] = 'option '.$o['name'].' → '.implode(', ', array_merge($o['domains'] ?? [], $o['patterns'] ?? []));
                }
                foreach (array_slice($posts, 0, 6) as $p) {
                    $evidence[] = $p['type'].' #'.$p['id'].' "'.$p['title'].'" → '.implode(', ', array_merge($p['domains'] ?? [], $p['patterns'] ?? []));
                }
                if (count($options) + count($posts) > 12) {
                    $evidence[] = '… and '.(count($options) + count($posts) - 12).' more';
                }
                $findings[] = [
                    'id' => 'wordpress_script_injection',
                    'severity' => 'critical',
                    'title' => 'Injected scripts found in '.count($posts).' post(s) and '.count($options).' option(s)',
                    'summary' => 'Scripts loading from domains this site does not use'.(($inj['domains'] ?? []) !== [] ? ' ('.implode(', ', array_slice($inj['domains'], 0, 5)).')' : '').', or obfuscated inline scripts, sit in the database. Visitors get redirected or served ads. Cleaning removes only those script tags, after saving the original rows into an incident folder.',
                    'evidence' => $evidence,
                    'treat_action' => 'strip_wordpress_script_injection',
                    'treat_label' => 'Remove injected scripts',
                    'manual_steps' => ['If a listed domain is one you added on purpose, add it to the allow list and run Doctor again.'],
                    'source' => 'live',
                ];
            }
        }

        if (is_array($updates)) {
            $core = (array) ($updates['core'] ?? []);
            $plugins = (array) ($updates['plugins'] ?? []);
            $themes = (array) ($updates['themes'] ?? []);
            $major = array_filter($core, fn ($c) => ($c['update_type'] ?? '') === 'major');
            if ($core !== []) {
                $target = (string) ($core[0]['version'] ?? '');
                $findings[] = [
                    'id' => 'wordpress_core_update_available',
                    'severity' => $major !== [] ? 'warning' : 'info',
                    'title' => 'WordPress '.$target.' is available (installed '.($updates['current'] ?? '?').')',
                    'summary' => 'Security fixes ship in core releases. Update runs the upgrade and the database update, then boots the site once to confirm it still renders. Plugin and theme versions are recorded first so a rollback target is known.',
                    'evidence' => array_map(fn ($c) => ($c['update_type'] ?? 'update').' → '.($c['version'] ?? ''), array_slice($core, 0, 3)),
                    'treat_action' => 'update_wordpress_core',
                    'treat_label' => 'Update WordPress to '.$target,
                    'manual_steps' => ['In Terminal: wp core update && wp core update-db'],
                    'source' => 'live',
                ];
            }
            if ($plugins !== [] || $themes !== []) {
                $findings[] = [
                    'id' => 'wordpress_updates_available',
                    'severity' => count($plugins) >= 5 ? 'warning' : 'info',
                    'title' => count($plugins).' plugin(s) and '.count($themes).' theme(s) have updates',
                    'summary' => 'Out-of-date plugins are how most WordPress sites get compromised. Update all applies every available update, then boots the site once; a plugin that breaks the site is disabled and named.',
                    'evidence' => array_merge(
                        array_map(fn ($p) => 'plugin '.$p['name'].' '.$p['version'].' → '.$p['update_version'], array_slice($plugins, 0, 10)),
                        array_map(fn ($t) => 'theme '.$t['name'].' '.$t['version'].' → '.$t['update_version'], array_slice($themes, 0, 5)),
                    ),
                    'treat_action' => 'update_wordpress_extensions',
                    'treat_label' => 'Update all',
                    'manual_steps' => ['In Terminal: wp plugin update --all && wp theme update --all'],
                    'source' => 'live',
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  list<array{id: int, login: string, email: string, registered: string}>  $admins
     * @return list<array{login: string, email: string, registered: string, why: string}>
     */
    public function suspiciousAdmins(array $admins): array
    {
        $flagged = [];
        $recent = now()->subDays(30);
        foreach ($admins as $admin) {
            $login = strtolower((string) ($admin['login'] ?? ''));
            $why = [];
            $registered = (string) ($admin['registered'] ?? '');
            try {
                if ($registered !== '' && Carbon::parse($registered)->greaterThan($recent)) {
                    $why[] = 'created in the last 30 days';
                }
            } catch (\Throwable) {
                // unparseable date: leave it
            }
            if (in_array($login, self::SUSPICIOUS_LOGINS, true)) {
                $why[] = 'login name attackers commonly pick';
            } elseif (preg_match('/^[a-z0-9]{6,12}$/', $login) === 1 && preg_match('/[0-9]/', $login) === 1 && preg_match('/[aeiou]/', $login) !== 1) {
                $why[] = 'random-looking login';
            }
            $email = strtolower((string) ($admin['email'] ?? ''));
            if ($email === '' || preg_match('/@(example\.com|localhost|mail\.ru|yandex\.ru|tempmail|10minutemail)/', $email) === 1) {
                $why[] = 'throwaway or empty e-mail';
            }
            if ($why !== []) {
                $flagged[] = ['login' => (string) $admin['login'], 'email' => $email, 'registered' => $registered, 'why' => implode(', ', $why)];
            }
        }

        return $flagged;
    }

    /*
    |--------------------------------------------------------------------------
    | Script injection clean-up
    |--------------------------------------------------------------------------
    */

    /**
     * PHP run inside the container. Argument 1 is JSON:
     * {options: [names], posts: [ids], domains: [flagged domains]}.
     * Prints TALKSASA_WPSEC_BACKUP=<json of the original values> before any
     * write, then TALKSASA_WPSEC_CLEANED=<json counts>.
     */
    public function stripInjectionScript(): string
    {
        return <<<'PHP'
@ini_set('display_errors', '0');
error_reporting(0);
$in = json_decode((string) ($argv[1] ?? '{}'), true);
if (! is_array($in)) { echo "BAD_INPUT\n"; exit(1); }
$domains = array_values(array_unique(array_map('strtolower', (array) ($in['domains'] ?? []))));
ob_start();
require '/var/www/html/wp-load.php';
ob_end_clean();
global $wpdb;
$hostOf = function ($src) { $h = strtolower((string) parse_url(str_starts_with($src, '//') ? 'http:'.$src : $src, PHP_URL_HOST)); return preg_replace('/^www\./', '', $h); };
$flagged = function ($domain) use ($domains) { $domain = preg_replace('/^www\./', '', strtolower($domain)); foreach ($domains as $d) { if ($domain === $d || str_ends_with($domain, '.'.$d)) { return true; } } return false; };
$clean = function ($text) use ($hostOf, $flagged, &$removed) {
    $text = (string) $text;
    $text = preg_replace_callback('/<script\b[^>]*\bsrc\s*=\s*["\']?([^"\'\s>]+)[^>]*>\s*<\/script>/i', function ($m) use ($hostOf, $flagged, &$removed) {
        if ($flagged($hostOf($m[1]))) { $removed++; return ''; }
        return $m[0];
    }, $text);
    $text = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/is', function ($m) use (&$removed) {
        if (preg_match('/\beval\s*\(|String\.fromCharCode\s*\(|\batob\s*\(|document\.write\s*\(\s*unescape|\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}/i', $m[1])) { $removed++; return ''; }
        return $m[0];
    }, $text);
    $text = preg_replace_callback('/<iframe\b[^>]*\b(?:width|height)\s*=\s*["\']?0[^>]*>.*?<\/iframe>/is', function ($m) use (&$removed) { $removed++; return ''; }, $text);
    return $text;
};
$walk = function ($value) use (&$walk, $clean) {
    if (is_string($value)) { return $clean($value); }
    if (is_array($value)) { foreach ($value as $k => $v) { $value[$k] = $walk($v); } return $value; }
    if (is_object($value)) { foreach (get_object_vars($value) as $k => $v) { $value->$k = $walk($v); } return $value; }
    return $value;
};
$backup = ['options' => [], 'posts' => []];
$counts = ['options' => 0, 'posts' => 0, 'tags' => 0];
$removed = 0;
$targets = ['options' => [], 'posts' => []];
foreach ((array) ($in['options'] ?? []) as $name) {
    if (in_array($name, ['home', 'siteurl'], true)) { continue; }
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ($raw === null) { continue; }
    $backup['options'][$name] = $raw;
    $targets['options'][$name] = $raw;
}
foreach ((array) ($in['posts'] ?? []) as $id) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT ID, post_content FROM {$wpdb->posts} WHERE ID = %d", (int) $id), ARRAY_A);
    if (! $row) { continue; }
    $backup['posts'][(int) $row['ID']] = $row['post_content'];
    $targets['posts'][(int) $row['ID']] = $row['post_content'];
}
echo "TALKSASA_WPSEC_BACKUP=".json_encode($backup)."\n";
foreach ($targets['options'] as $name => $raw) {
    $before = $removed;
    $value = maybe_unserialize($raw);
    $cleaned = $walk($value);
    if ($removed > $before) {
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($cleaned)], ['option_name' => $name]);
        wp_cache_delete($name, 'options'); wp_cache_delete('alloptions', 'options');
        $counts['options']++;
    }
}
foreach ($targets['posts'] as $id => $content) {
    $before = $removed;
    $cleaned = $clean($content);
    if ($removed > $before) {
        $wpdb->update($wpdb->posts, ['post_content' => $cleaned], ['ID' => $id]);
        clean_post_cache($id);
        $counts['posts']++;
    }
}
$counts['tags'] = $removed;
echo "TALKSASA_WPSEC_CLEANED=".json_encode($counts)."\n";
PHP;
    }

    /**
     * @return array{backup: array<string, mixed>|null, cleaned: array{options: int, posts: int, tags: int}|null, raw: string}
     */
    public function parseStripOutput(string $output): array
    {
        $backup = preg_match('/TALKSASA_WPSEC_BACKUP=(\{.*\})/', $output, $m) === 1 ? json_decode($m[1], true) : null;
        $cleaned = preg_match('/TALKSASA_WPSEC_CLEANED=(\{.*\})/', $output, $m) === 1 ? json_decode($m[1], true) : null;

        return ['backup' => is_array($backup) ? $backup : null, 'cleaned' => is_array($cleaned) ? $cleaned : null, 'raw' => $output];
    }

    /*
    |--------------------------------------------------------------------------
    | Security keys
    |--------------------------------------------------------------------------
    */

    /**
     * Eight fresh keys, from WordPress.org when reachable, else generated here.
     *
     * @return array<string, string>
     */
    public function freshSalts(): array
    {
        $salts = [];
        try {
            $response = Http::timeout(15)->connectTimeout(8)->get(self::SALT_API);
            if ($response->ok()) {
                foreach (self::SALT_KEYS as $key) {
                    if (preg_match("/define\('".$key."',\s*'((?:[^'\\\\]|\\\\.)+)'\);/", $response->body(), $m) === 1 && strlen($m[1]) >= 32) {
                        $salts[$key] = $m[1];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('WordPress salt API unreachable; generating locally', ['error' => $e->getMessage()]);
        }
        foreach (self::SALT_KEYS as $key) {
            if (! isset($salts[$key])) {
                $salts[$key] = Str::random(64);
            }
        }

        return $salts;
    }

    /**
     * PHP that replaces (or inserts) the eight defines and keeps the file parseable.
     */
    public function rotateSaltsScript(): string
    {
        return <<<'PHP'
$cfg = '/var/www/html/wp-config.php';
$salts = json_decode((string) ($argv[1] ?? '{}'), true);
if (! is_file($cfg) || ! is_array($salts) || count($salts) !== 8) { echo "BAD_INPUT\n"; exit(1); }
$text = file_get_contents($cfg);
$orig = $text;
$missing = [];
foreach ($salts as $name => $value) {
    $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    $line = "define('".$name."', '".$escaped."');";
    $count = 0;
    $text = preg_replace("/define\s*\(\s*['\"]".$name."['\"]\s*,\s*(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*\)\s*;/", $line, $text, 1, $count);
    if ($count === 0) { $missing[] = $line; }
}
if ($missing !== []) {
    $block = "/* TALKASA_SECURITY_KEYS */\n".implode("\n", $missing)."\n";
    $count = 0;
    $text = preg_replace('/<\?php\b/', "<?php\n".$block, $text, 1, $count);
    if ($count === 0) { $text = "<?php\n".$block.$text; }
}
$tmp = $cfg.'.talksasa-salts';
file_put_contents($tmp, $text);
exec('php -l '.escapeshellarg($tmp).' 2>&1', $out, $code);
if ($code !== 0) { @unlink($tmp); echo "SYNTAX ".implode(' ', $out)."\n"; exit(2); }
rename($tmp, $cfg);
echo "ROTATED ".count($salts)." replaced=".(8 - count($missing))." inserted=".count($missing)."\n";
PHP;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function rotateSalts(SSHService $ssh, ContainerDeployment $deployment): array
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $salts = $this->freshSalts();
        $result = $ssh->execWithStatus(
            'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -T '.escapeshellarg($deployment->container_name)
            .' php -r '.escapeshellarg($this->rotateSaltsScript()).' '.escapeshellarg((string) json_encode($salts)),
            60
        );
        if (! str_contains($result['output'], 'ROTATED')) {
            return ['success' => false, 'message' => 'Security keys were not rotated: '.trim(mb_substr($result['output'], 0, 200))];
        }

        return ['success' => true, 'message' => 'Rotated all eight security keys; every WordPress session, including yours, was signed out once.'];
    }

    /*
    |--------------------------------------------------------------------------
    | Apply the baseline (deploy, convert, Doctor)
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<string> console lines describing what was applied
     */
    public function apply(SSHService $ssh, string $containerName): array
    {
        $lines = [];
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$containerName;

        try {
            $conf = $this->ensureSecurityConf($ssh, $containerName);
            if ($conf['written']) {
                $lines[] = 'Apache hardening '.($conf['live'] ? 'active' : 'written; active after the next restart').': no PHP under uploads, wp-config and backups not served, xmlrpc.php '.($conf['xmlrpc_blocked'] ? 'blocked' : 'left open for a plugin that needs it');
            } else {
                $lines[] = 'Apache hardening not applied: '.($conf['error'] ?? 'unknown error');
            }
        } catch (\Throwable $e) {
            $lines[] = 'Apache hardening skipped: '.$e->getMessage();
        }

        try {
            app(WordPressContainerHardeningService::class)->ensureWpConfigHardening($ssh, $containerPath, $containerName);
            $lines[] = 'wp-config: in-admin code editor disabled (DISALLOW_FILE_EDIT), minor core updates on, cron via the platform';
        } catch (\Throwable $e) {
            $lines[] = 'wp-config hardening skipped: '.$e->getMessage();
        }

        return $lines;
    }
}
