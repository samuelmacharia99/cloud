<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Services\SSH\SSHService;
use RuntimeException;

class WordPressAdminLoginService
{
    private const TOKEN_TTL_SECONDS = 120;

    private const MU_PLUGIN_RELATIVE = 'wp-content/mu-plugins/talksasa-admin-sso.php';

    private const TOKEN_RELATIVE = 'wp-content/uploads/.talksasa-admin-sso.json';

    /**
     * Ensure the SSO mu-plugin is present, mint a one-time token, and return the login URL.
     */
    public function createLoginUrl(Service $service): string
    {
        $service->loadMissing([
            'product.containerTemplate',
            'containerDeployment.node',
            'containerDeployment.domains',
        ]);

        if (! $this->isWordPressContainer($service)) {
            throw new RuntimeException('This service is not a WordPress App Hosting container.');
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->isRunning()) {
            throw new RuntimeException('WordPress container must be running to open the admin dashboard.');
        }

        if (! $deployment->node) {
            throw new RuntimeException('Container host is not configured.');
        }

        $baseUrl = $this->resolvePublicBaseUrl($service);
        if ($baseUrl === null) {
            throw new RuntimeException('No public URL is available for this WordPress site yet.');
        }

        $containerPath = '/opt/talksasa/containers/'.$deployment->container_name;
        $hostAppPath = $containerPath.'/app';
        $appService = $deployment->container_name;

        $ssh = SSHService::forNode($deployment->node);

        try {
            $this->ensureMuPlugin($ssh, $hostAppPath);
            $userId = $this->resolveAdministratorUserId($ssh, $containerPath, $appService, $deployment->env_values ?? []);
            $token = bin2hex(random_bytes(32));
            $payload = json_encode([
                'token' => $token,
                'user_id' => $userId,
                'expires_at' => time() + self::TOKEN_TTL_SECONDS,
                'issued_at' => time(),
            ], JSON_THROW_ON_ERROR);

            $ssh->mkdirp($hostAppPath.'/wp-content/uploads');
            $ssh->upload($payload, $hostAppPath.'/'.self::TOKEN_RELATIVE);
            $ssh->exec(
                'chown 33:33 '.escapeshellarg($hostAppPath.'/'.self::TOKEN_RELATIVE)
                .' && chmod 640 '.escapeshellarg($hostAppPath.'/'.self::TOKEN_RELATIVE),
                15
            );
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
        return <<<'PHP'
<?php
/**
 * Plugin Name: Talksasa Admin SSO
 * Description: One-time admin login links issued by the Talksasa customer portal.
 */

add_action('plugins_loaded', static function (): void {
    if (empty($_GET['talksasa_admin_sso'])) {
        return;
    }

    $token = preg_replace('/[^a-f0-9]/', '', (string) $_GET['talksasa_admin_sso']);
    $file = WP_CONTENT_DIR.'/uploads/.talksasa-admin-sso.json';

    if ($token === '' || ! is_readable($file)) {
        status_header(403);
        exit('Invalid or expired login link.');
    }

    $raw = (string) file_get_contents($file);
    @unlink($file);
    $payload = json_decode($raw, true);

    if (! is_array($payload)
        || ! hash_equals((string) ($payload['token'] ?? ''), $token)
        || (int) ($payload['expires_at'] ?? 0) < time()
    ) {
        status_header(403);
        exit('Invalid or expired login link.');
    }

    $userId = (int) ($payload['user_id'] ?? 0);
    $user = $userId > 0 ? get_user_by('id', $userId) : false;
    if (! $user || ! user_can($user, 'manage_options')) {
        status_header(403);
        exit('Administrator account not found.');
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    nocache_headers();
    wp_safe_redirect(admin_url());
    exit;
}, 0);
PHP;
    }

    private function ensureMuPlugin(SSHService $ssh, string $hostAppPath): void
    {
        $ssh->mkdirp($hostAppPath.'/wp-content/mu-plugins');
        $remotePath = $hostAppPath.'/'.self::MU_PLUGIN_RELATIVE;
        $ssh->upload($this->muPluginContents(), $remotePath);
        $ssh->exec(
            'chown 33:33 '.escapeshellarg($remotePath)
            .' && chmod 644 '.escapeshellarg($remotePath),
            15
        );
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

        $php = <<<PHP
require '/var/www/html/wp-load.php';
\$preferred = '{$preferredLogin}';
\$user = get_user_by('login', \$preferred);
if (\$user && user_can(\$user, 'manage_options')) {
    echo (int) \$user->ID;
    exit;
}
\$users = get_users([
    'role' => 'administrator',
    'number' => 1,
    'orderby' => 'ID',
    'order' => 'ASC',
]);
if (! empty(\$users[0])) {
    echo (int) \$users[0]->ID;
    exit;
}
fwrite(STDERR, "No WordPress administrator found\\n");
exit(1);
PHP;

        $output = trim($ssh->exec(
            "cd {$containerPath} && docker compose exec -T {$appService} php -r ".escapeshellarg($php),
            60
        ));

        if (! ctype_digit($output) || (int) $output < 1) {
            throw new RuntimeException('Could not resolve a WordPress administrator account.');
        }

        return (int) $output;
    }
}
