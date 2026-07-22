<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Services\SSH\SSHService;
use RuntimeException;

class WordPressAdminLoginService
{
    private const TOKEN_TTL_SECONDS = 180;

    private const MU_PLUGIN_RELATIVE = 'wp-content/mu-plugins/talksasa-admin-sso.php';

    private const TOKEN_RELATIVE = 'wp-content/uploads/.talksasa-admin-sso.json';

    private const MU_PLUGIN_VERSION = 'TALKASA_SSO_V3';

    /**
     * Ensure the SSO mu-plugin is present, mint a one-time token, and return the login URL.
     */
    public function createLoginUrl(Service $service): string
    {
        // SSH + docker compose + wp-load can exceed the default 30s web timeout.
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        @ini_set('max_execution_time', '180');

        $service->loadMissing([
            'product.containerTemplate',
            'containerDeployment.node',
            'containerDeployment.domains',
        ]);

        if (! $this->isWordPressContainer($service)) {
            throw new RuntimeException('This service is not a WordPress Application Hosting container.');
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->isRunning()) {
            throw new RuntimeException('WordPress container must be running to open the admin dashboard.');
        }

        if (! $deployment->node) {
            throw new RuntimeException('Container host is not configured.');
        }

        $containerPath = '/opt/talksasa/containers/'.$deployment->container_name;
        $hostAppPath = $containerPath.'/app';
        $appService = $deployment->container_name;

        $ssh = SSHService::forNode($deployment->node);

        try {
            $this->ensureMuPlugin($ssh, $hostAppPath);
            $this->ensureProxyHttpsAwareness($ssh, $containerPath, $appService);

            // Prefer WordPress's own home URL so the auth cookie matches the site visitors use.
            // Platform domain/port URLs often diverge after DA→container converts.
            $baseUrl = $this->resolveWordPressHomeUrl($ssh, $containerPath, $appService)
                ?? $this->resolvePublicBaseUrl($service);

            if ($baseUrl === null) {
                throw new RuntimeException('No public URL is available for this WordPress site yet.');
            }

            $userId = $this->resolveAdministratorUserId(
                $ssh,
                $containerPath,
                $appService,
                $deployment->env_values ?? []
            );

            $token = bin2hex(random_bytes(32));
            $payload = json_encode([
                'token' => $token,
                'user_id' => $userId,
                'expires_at' => time() + self::TOKEN_TTL_SECONDS,
                'issued_at' => time(),
            ], JSON_THROW_ON_ERROR);

            $tokenPath = $hostAppPath.'/'.self::TOKEN_RELATIVE;
            $ssh->mkdirp($hostAppPath.'/wp-content/uploads');
            $ssh->upload($payload, $tokenPath);
            $ssh->exec(
                'chown 33:33 '.escapeshellarg($tokenPath)
                .' && chmod 640 '.escapeshellarg($tokenPath),
                15
            );

            // Drop any stale cached SSO user id so future fixes always re-resolve.
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            if (isset($meta['wordpress_sso'])) {
                unset($meta['wordpress_sso']);
                $service->update(['service_meta' => $meta]);
            }
        } finally {
            $ssh->disconnect();
        }

        return rtrim($baseUrl, '/').'/wp-login.php?talksasa_admin_sso='.$token;
    }

    public function isWordPressContainer(Service $service): bool
    {
        $service->loadMissing('product.containerTemplate');

        return $service->product?->type === 'container_hosting'
            && ($service->product?->containerTemplate?->slug ?? '') === 'wordpress';
    }

    public function resolvePublicBaseUrl(Service $service): ?string
    {
        $service->loadMissing(['containerDeployment.node', 'containerDeployment.domains']);
        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return null;
        }

        $domain = $deployment->relationLoaded('domains')
            ? $deployment->domains->first(fn ($d) => ($d->status ?? null) === 'active')
            : $deployment->primaryDomain();
        if ($domain) {
            $scheme = $domain->ssl_enabled ? 'https' : 'http';

            return $scheme.'://'.$domain->domain;
        }

        return $deployment->getAccessUrl();
    }

    public function muPluginContents(): string
    {
        $version = self::MU_PLUGIN_VERSION;

        return <<<PHP
<?php
/**
 * Plugin Name: Talksasa Admin SSO
 * Description: One-time admin login links issued by the Talksasa customer portal.
 * {$version}
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Run as early as possible on wp-login / front-end so the query string is not lost
 * to canonical redirects before we can authenticate.
 */
\$talksasa_admin_sso_handler = static function (): void {
    if (empty(\$_GET['talksasa_admin_sso'])) {
        return;
    }

    \$token = preg_replace('/[^a-f0-9]/', '', (string) \$_GET['talksasa_admin_sso']);
    \$file = WP_CONTENT_DIR . '/uploads/.talksasa-admin-sso.json';

    if (\$token === '' || ! is_readable(\$file)) {
        return; // Fall through to normal login form (token missing/unreadable).
    }

    \$raw = (string) file_get_contents(\$file);
    \$payload = json_decode(\$raw, true);

    if (! is_array(\$payload)
        || ! hash_equals((string) (\$payload['token'] ?? ''), \$token)
        || (int) (\$payload['expires_at'] ?? 0) < time()
    ) {
        @unlink(\$file);
        return;
    }

    \$userId = (int) (\$payload['user_id'] ?? 0);
    \$user = \$userId > 0 ? get_user_by('id', \$userId) : false;
    if (! \$user || ! user_can(\$user, 'manage_options')) {
        @unlink(\$file);
        return;
    }

    // Consume token only after we have a valid admin — retries keep working if cookie set fails.
    @unlink(\$file);

    wp_set_current_user(\$user->ID);
    wp_set_auth_cookie(\$user->ID, true, is_ssl());
    nocache_headers();

    \$target = admin_url('/');
    if (! headers_sent()) {
        wp_safe_redirect(\$target);
        exit;
    }

    echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url='
        . esc_url(\$target)
        . '"></head><body>Signing you in… <a href="'
        . esc_url(\$target)
        . '">Continue to dashboard</a></body></html>';
    exit;
};

add_action('login_init', \$talksasa_admin_sso_handler, 0);
add_action('init', \$talksasa_admin_sso_handler, 0);
PHP;
    }

    private function ensureMuPlugin(SSHService $ssh, string $hostAppPath): void
    {
        $remotePath = $hostAppPath.'/'.self::MU_PLUGIN_RELATIVE;
        $remoteArg = escapeshellarg($remotePath);
        $version = self::MU_PLUGIN_VERSION;

        try {
            $exists = trim($ssh->exec(
                '[ -f '.$remoteArg.' ] && grep -q '.escapeshellarg($version).' '.$remoteArg.' && echo yes || echo no',
                15
            ));
            if ($exists === 'yes') {
                return;
            }
        } catch (\Throwable) {
            // Fall through and (re)install.
        }

        $ssh->mkdirp($hostAppPath.'/wp-content/mu-plugins');
        $ssh->upload($this->muPluginContents(), $remotePath);
        $ssh->exec(
            'chown 33:33 '.$remoteArg.' && chmod 644 '.$remoteArg,
            15
        );
    }

    private function ensureProxyHttpsAwareness(SSHService $ssh, string $containerPath, string $appService): void
    {
        $snippet = <<<'SNIP'
/* TALKASA_PROXY_HTTPS */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

SNIP;

        $php = '$cfg = \'/var/www/html/wp-config.php\';'
            .' if (! is_file($cfg)) { exit(0); }'
            .' $text = file_get_contents($cfg);'
            .' if (str_contains($text, \'TALKASA_PROXY_HTTPS\')) { exit(0); }'
            .' $snippet = '.var_export($snippet, true).';'
            .' if (preg_match(\'/<\?php\\b/\', $text)) {'
            .'   $text = preg_replace(\'/<\?php\\b/\', "<?php\\n".$snippet, $text, 1);'
            .' } else {'
            .'   $text = "<?php\\n".$snippet.$text;'
            .' }'
            .' file_put_contents($cfg, $text);';

        try {
            $ssh->exec(
                "cd {$containerPath} && docker compose exec -T {$appService} php -r ".escapeshellarg($php),
                60
            );
        } catch (\Throwable) {
            // Non-fatal — SSO may still work on plain HTTP access URLs.
        }
    }

    /**
     * Read home/siteurl from the live WordPress container so SSO cookies match.
     */
    private function resolveWordPressHomeUrl(SSHService $ssh, string $containerPath, string $appService): ?string
    {
        $php = <<<'PHP'
@ini_set('display_errors', '0');
error_reporting(0);
require '/var/www/html/wp-load.php';
$home = (string) get_option('home');
$site = (string) get_option('siteurl');
$url = $home !== '' ? $home : $site;
echo 'TALKASA_WP_HOME='.$url;
PHP;

        try {
            $output = $ssh->exec(
                "cd {$containerPath} && docker compose exec -T {$appService} php -d display_errors=0 -r ".escapeshellarg($php),
                60
            );
        } catch (\Throwable) {
            return null;
        }

        if (preg_match('#TALKASA_WP_HOME=(https?://\S+)#', $output, $m) !== 1) {
            return null;
        }

        $url = rtrim($m[1], "/ \t\r\n");
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $envValues
     */
    private function resolveAdministratorUserId(
        SSHService $ssh,
        string $containerPath,
        string $appService,
        array $envValues
    ): int {
        $preferredLogin = trim((string) ($envValues['WORDPRESS_ADMIN_USER'] ?? 'admin'));
        $preferredLogin = preg_replace('/[^a-zA-Z0-9._@-]/', '', $preferredLogin) ?: 'admin';
        $preferredEmail = trim((string) ($envValues['WORDPRESS_ADMIN_EMAIL'] ?? ''));
        $preferredEmail = filter_var($preferredEmail, FILTER_VALIDATE_EMAIL) ? $preferredEmail : '';

        $php = <<<PHP
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);
require '/var/www/html/wp-load.php';
\$emit = static function (int \$id): void {
    if (\$id > 0) {
        echo 'TALKASA_WP_ADMIN_ID=' . \$id;
        exit(0);
    }
};
\$preferred = '{$preferredLogin}';
\$preferredEmail = '{$preferredEmail}';
\$user = get_user_by('login', \$preferred);
if (\$user && user_can(\$user, 'manage_options')) {
    \$emit((int) \$user->ID);
}
if (\$preferredEmail !== '') {
    \$user = get_user_by('email', \$preferredEmail);
    if (\$user && user_can(\$user, 'manage_options')) {
        \$emit((int) \$user->ID);
    }
}
\$users = get_users([
    'role' => 'administrator',
    'number' => 1,
    'orderby' => 'ID',
    'order' => 'ASC',
    'fields' => 'ID',
]);
if (! empty(\$users[0])) {
    \$emit((int) \$users[0]);
}
global \$wpdb;
\$metaKey = \$wpdb->get_blog_prefix() . 'capabilities';
\$userId = (int) \$wpdb->get_var(\$wpdb->prepare(
    "SELECT user_id FROM {\$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY user_id ASC LIMIT 1",
    \$metaKey,
    '%administrator%'
));
if (\$userId > 0) {
    \$emit(\$userId);
}
\$capsUsers = get_users([
    'capability' => 'manage_options',
    'number' => 1,
    'orderby' => 'ID',
    'order' => 'ASC',
    'fields' => 'ID',
]);
if (! empty(\$capsUsers[0])) {
    \$emit((int) \$capsUsers[0]);
}
echo 'TALKASA_WP_ADMIN_ID=0';
exit(0);
PHP;

        try {
            $output = $ssh->exec(
                "cd {$containerPath} && docker compose exec -T {$appService} php -d display_errors=0 -r ".escapeshellarg($php),
                60
            );
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Could not resolve a WordPress administrator account: '.$e->getMessage(),
                0,
                $e
            );
        }

        $userId = $this->parseAdministratorIdFromOutput($output);
        if ($userId > 0) {
            return $userId;
        }

        throw new RuntimeException(
            'Could not resolve a WordPress administrator account.'
            .' Ensure the site has at least one administrator user, then try again.'
        );
    }

    public function parseAdministratorIdFromOutput(string $output): int
    {
        if (preg_match('/TALKASA_WP_ADMIN_ID=(\d+)/', $output, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
