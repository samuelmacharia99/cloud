<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Fresh WordPress Application Hosting: wait for core files + MySQL, then wp core install.
 */
class WordPressAppInstallationService
{
    public function __construct(
        private WordPressAdminLoginService $adminLogin,
    ) {}

    /**
     * @param  array<string, string>  $envVars
     * @return array{success: bool, skipped: bool, message: string}
     */
    public function installIfNeeded(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        array $envVars,
    ): array {
        $service->loadMissing(['product.containerTemplate', 'user', 'containerDeployment.node', 'containerDeployment.domains']);

        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'wordpress') {
            return ['success' => true, 'skipped' => true, 'message' => 'Not a WordPress template.'];
        }

        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $hostAppPath = $containerPath.'/app';
        $appService = $deployment->container_name;
        $pathArg = escapeshellarg($containerPath);
        $appArg = escapeshellarg($appService);

        $this->clearNonWordPressPlaceholders($ssh, $hostAppPath, $pathArg, $appArg);
        $this->waitForMysql($ssh, $containerPath, $envVars);
        $this->waitForWordPressCore($ssh, $hostAppPath, $pathArg, $appArg);
        $this->ensureWpCli($ssh, $containerPath, $appService);

        if ($this->isInstalled($ssh, $containerPath, $appService)) {
            return ['success' => true, 'skipped' => true, 'message' => 'WordPress is already installed.'];
        }

        $url = $this->resolveInstallUrl($service, $deployment);
        $title = $this->resolveSiteTitle($service);
        $adminUser = trim((string) ($envVars['WORDPRESS_ADMIN_USER'] ?? 'admin')) ?: 'admin';
        $adminPassword = trim((string) ($envVars['WORDPRESS_ADMIN_PASSWORD'] ?? ''));
        $adminEmail = trim((string) ($envVars['WORDPRESS_ADMIN_EMAIL'] ?? $service->user?->email ?? ''));

        if ($adminPassword === '' || $adminEmail === '') {
            throw new \RuntimeException('WordPress admin credentials are missing; cannot complete auto-install.');
        }

        $command = 'wp core install'
            .' --path=/var/www/html'
            .' --url='.escapeshellarg($url)
            .' --title='.escapeshellarg($title)
            .' --admin_user='.escapeshellarg($adminUser)
            .' --admin_password='.escapeshellarg($adminPassword)
            .' --admin_email='.escapeshellarg($adminEmail)
            .' --skip-email';

        $output = $ssh->exec(
            "cd {$pathArg} && docker compose exec -u www-data -T {$appArg} sh -lc ".escapeshellarg($command),
            (int) config('containers.wordpress_install.command_timeout_seconds', 300)
        );

        if (! $this->isInstalled($ssh, $containerPath, $appService)) {
            throw new \RuntimeException('WordPress core install did not complete. Output: '.trim($output));
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['wordpress_installed_at'] = now()->toIso8601String();
        $meta['wordpress_install_url'] = $url;
        $service->update(['service_meta' => $meta]);

        Log::info('WordPress auto-install completed', [
            'service_id' => $service->id,
            'url' => $url,
        ]);

        return [
            'success' => true,
            'skipped' => false,
            'message' => 'WordPress '.$this->installedVersion($ssh, $containerPath, $appService).' installed at '.$url,
        ];
    }

    private function resolveInstallUrl(Service $service, ContainerDeployment $deployment): string
    {
        $url = $this->adminLogin->resolvePublicBaseUrl($service)
            ?? $deployment->getAccessUrl();

        if (! filled($url)) {
            throw new \RuntimeException('No public URL available for WordPress install.');
        }

        return rtrim((string) $url, '/');
    }

    private function resolveSiteTitle(Service $service): string
    {
        $name = trim((string) ($service->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return 'WordPress Site';
    }

    private function clearNonWordPressPlaceholders(
        SSHService $ssh,
        string $hostAppPath,
        string $pathArg,
        string $appArg,
    ): void {
        $hostArg = escapeshellarg($hostAppPath);
        $hasCore = trim($ssh->exec(
            "[ -f {$hostArg}/wp-includes/version.php ] && echo yes || echo no",
            15
        ));

        if ($hasCore === 'yes') {
            return;
        }

        // Remove Talksasa placeholders so the official image entrypoint can copy latest core.
        $ssh->exec(
            "rm -f {$hostArg}/index.html {$hostArg}/.keep {$hostArg}/public/index.html 2>/dev/null || true"
            ." ; rmdir {$hostArg}/public 2>/dev/null || true",
            15
        );

        // Re-run entrypoint copy by restarting the app service once.
        try {
            $ssh->exec("cd {$pathArg} && docker compose restart {$appArg}", 120);
        } catch (\Throwable $e) {
            Log::warning('WordPress container restart after placeholder clear failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $envVars
     */
    private function waitForMysql(SSHService $ssh, string $containerPath, array $envVars): void
    {
        $pathArg = escapeshellarg($containerPath);
        $rootPassword = (string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? '');
        $timeout = max(30, (int) config('containers.wordpress_install.mysql_wait_seconds', 180));
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            try {
                $ping = "cd {$pathArg} && docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 --silent 2>/dev/null";
                if ($rootPassword !== '') {
                    $ping = "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD="
                        .escapeshellarg($rootPassword)
                        .' mysql mysqladmin ping -h 127.0.0.1 -uroot --silent 2>/dev/null';
                }

                $ssh->exec($ping.' && echo ready', 20);

                return;
            } catch (\Throwable) {
                sleep(2);
            }
        }

        throw new \RuntimeException('MySQL sidecar was not ready for WordPress install.');
    }

    private function waitForWordPressCore(
        SSHService $ssh,
        string $hostAppPath,
        string $pathArg,
        string $appArg,
    ): void {
        $hostArg = escapeshellarg($hostAppPath);
        $timeout = max(30, (int) config('containers.wordpress_install.core_wait_seconds', 180));
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $hasCore = trim($ssh->exec(
                "[ -f {$hostArg}/wp-includes/version.php ] && echo yes || echo no",
                15
            ));

            if ($hasCore === 'yes') {
                return;
            }

            sleep(2);
        }

        // Last attempt: force entrypoint by recreating the app container.
        $ssh->exec("cd {$pathArg} && docker compose up -d --force-recreate {$appArg}", 180);
        sleep(5);

        $hasCore = trim($ssh->exec(
            "[ -f {$hostArg}/wp-includes/version.php ] && echo yes || echo no",
            15
        ));

        if ($hasCore !== 'yes') {
            throw new \RuntimeException('WordPress core files did not appear in the application volume.');
        }
    }

    public function ensureWpCli(SSHService $ssh, string $containerPath, string $appService): void
    {
        $pathArg = escapeshellarg($containerPath);
        $appArg = escapeshellarg($appService);

        $check = trim($ssh->exec(
            "cd {$pathArg} && docker compose exec -T {$appArg} sh -lc "
            .escapeshellarg('command -v wp >/dev/null 2>&1 && wp --info >/dev/null 2>&1 && echo ok || echo missing'),
            45
        ));

        if (str_contains($check, 'ok')) {
            return;
        }

        $install = 'set -e; '
            .'if command -v curl >/dev/null 2>&1; then curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; '
            .'elif command -v wget >/dev/null 2>&1; then wget -qO /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; '
            .'else echo "curl/wget missing" >&2; exit 1; fi; '
            .'chmod +x /usr/local/bin/wp; '
            .'wp --allow-root --info >/dev/null';

        $ssh->exec(
            "cd {$pathArg} && docker compose exec -u 0 -T {$appArg} sh -lc ".escapeshellarg($install),
            120
        );
    }

    private function isInstalled(SSHService $ssh, string $containerPath, string $appService): bool
    {
        $pathArg = escapeshellarg($containerPath);
        $appArg = escapeshellarg($appService);

        try {
            $result = trim($ssh->exec(
                "cd {$pathArg} && docker compose exec -u www-data -T {$appArg} sh -lc "
                .escapeshellarg('wp core is-installed --path=/var/www/html && echo installed'),
                60
            ));

            return str_contains($result, 'installed');
        } catch (\Throwable) {
            return false;
        }
    }

    private function installedVersion(SSHService $ssh, string $containerPath, string $appService): string
    {
        $pathArg = escapeshellarg($containerPath);
        $appArg = escapeshellarg($appService);

        try {
            $version = trim($ssh->exec(
                "cd {$pathArg} && docker compose exec -u www-data -T {$appArg} sh -lc "
                .escapeshellarg('wp core version --path=/var/www/html'),
                30
            ));

            return $version !== '' ? $version : 'latest';
        } catch (\Throwable) {
            return 'latest';
        }
    }
}
