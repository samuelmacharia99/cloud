<?php

namespace App\Services\Provisioning;

use App\Models\ContainerCronJob;
use App\Models\ContainerDomain;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Production hardening for official wordpress:* Apache images.
 */
class WordPressContainerHardeningService
{
    public const WP_CRON_JOB_NAME = 'WordPress system cron';

    public const WP_CRON_COMMAND = 'php /var/www/html/wp-cron.php';

    public const WP_CRON_SCHEDULE = '*/5 * * * *';

    public function uploadMaxMegabytes(): int
    {
        return max(8, (int) config('security.container_file_upload.max_size_mb', 100));
    }

    public function uploadsIniContents(): string
    {
        $mb = $this->uploadMaxMegabytes();

        return implode("\n", [
            '; Managed by Talksasa — keep PHP limits aligned with nginx client_max_body_size',
            "upload_max_filesize = {$mb}M",
            "post_max_size = {$mb}M",
            'memory_limit = 512M',
            'max_execution_time = 300',
            'max_input_time = 300',
            'max_file_uploads = 50',
            '',
        ]);
    }

    /**
     * How many requests Apache may run at once, from the plan.
     *
     * The image ships mpm_prefork with a default ceiling in the hundreds. With
     * mod_php each of those workers can claim the PHP memory limit, so on a
     * small plan a traffic spike is how a node runs out of memory. Budgeting
     * 64 MB per worker is deliberately generous for WordPress; the page cache
     * in front means most anonymous traffic never reaches Apache at all.
     */
    public function maxRequestWorkers(?int $planMemoryMb = null): int
    {
        if ($planMemoryMb === null || $planMemoryMb <= 0) {
            return 16;
        }

        $appMemoryMb = (int) floor($planMemoryMb * (1 - ContainerElasticResourceService::DATABASE_SHARE));

        return max(8, min(64, (int) floor($appMemoryMb / 64)));
    }

    public function apacheWorkersConfContents(?int $planMemoryMb = null): string
    {
        $workers = $this->maxRequestWorkers($planMemoryMb);

        return implode("\n", [
            '# Managed by Talksasa — sized from the plan this site is on.',
            '<IfModule mpm_prefork_module>',
            '    StartServers 2',
            '    MinSpareServers 2',
            "    MaxSpareServers {$workers}",
            "    MaxRequestWorkers {$workers}",
            '    MaxConnectionsPerChild 500',
            '</IfModule>',
            '',
        ]);
    }

    public function apacheWorkersHostPath(string $containerName): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$containerName.'/php/talksasa-workers.conf';
    }

    public function apacheWorkersVolumeMount(string $containerName): string
    {
        return $this->apacheWorkersHostPath($containerName).':/etc/apache2/conf-enabled/talksasa-workers.conf:ro';
    }

    /**
     * Write the worker ceiling on the host so compose can bind-mount it.
     */
    public function ensureApacheWorkersFile(SSHService $ssh, string $containerName, ?int $planMemoryMb = null): void
    {
        $hostPath = $this->apacheWorkersHostPath($containerName);
        $ssh->exec('mkdir -p '.escapeshellarg(dirname($hostPath)), 15);
        $ssh->upload($this->apacheWorkersConfContents($planMemoryMb), $hostPath);
    }

    public function uploadsIniHostPath(string $containerName): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$containerName.'/php/uploads.ini';
    }

    public function uploadsIniVolumeMount(string $containerName): string
    {
        return $this->uploadsIniHostPath($containerName).':/usr/local/etc/php/conf.d/uploads.ini:ro';
    }

    /**
     * Write uploads.ini on the container host so compose can bind-mount it.
     */
    public function ensureUploadsIniFile(SSHService $ssh, string $containerName): void
    {
        $hostPath = $this->uploadsIniHostPath($containerName);
        $dir = dirname($hostPath);
        $ssh->exec('mkdir -p '.escapeshellarg($dir), 15);
        $ssh->upload($this->uploadsIniContents(), $hostPath);
    }

    /**
     * Inject HTTPS-behind-proxy + DISABLE_WP_CRON (+ session path) into wp-config.php.
     */
    public function ensureWpConfigHardening(
        SSHService $ssh,
        string $containerPath,
        string $appService
    ): void {
        $snippet = <<<'SNIP'
/* TALKASA_PROXY_HTTPS */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (! defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}
if (! defined('WP_AUTO_UPDATE_CORE')) {
    define('WP_AUTO_UPDATE_CORE', 'minor');
}
$sessionDir = '/var/www/html/wp-content/uploads/sessions';
if (is_dir($sessionDir) || @mkdir($sessionDir, 0775, true)) {
    @ini_set('session.save_path', $sessionDir);
} else {
    @ini_set('session.save_path', '/tmp');
}

SNIP;

        $php = '$cfg = \'/var/www/html/wp-config.php\';'
            .' if (! is_file($cfg)) { fwrite(STDERR, "wp-config.php missing\\n"); exit(2); }'
            .' $text = file_get_contents($cfg);'
            .' $snippet = '.var_export($snippet, true).';'
            .' $changed = false;'
            .' if (! str_contains($text, \'TALKASA_PROXY_HTTPS\')) {'
            .'   if (preg_match(\'/<\?php\\b/\', $text)) {'
            .'     $text = preg_replace(\'/<\?php\\b/\', "<?php\\n".$snippet, $text, 1);'
            .'   } else {'
            .'     $text = "<?php\\n".$snippet.$text;'
            .'   }'
            .'   $changed = true;'
            .' } else {'
            // Back-fill each constant independently on a file hardened by an
            // older deploy. One elseif per constant meant every new one needed
            // its own special case and only the first ever landed.
            .'   $constants = ['
            .'     "DISABLE_WP_CRON" => "if (! defined(\'DISABLE_WP_CRON\')) {\\n    define(\'DISABLE_WP_CRON\', true);\\n}\\n",'
            .'     "WP_AUTO_UPDATE_CORE" => "if (! defined(\'WP_AUTO_UPDATE_CORE\')) {\\n    define(\'WP_AUTO_UPDATE_CORE\', \'minor\');\\n}\\n",'
            .'   ];'
            .'   foreach ($constants as $name => $insert) {'
            .'     if (str_contains($text, $name)) { continue; }'
            .'     $count = 0;'
            .'     $text = preg_replace(\'/(\\/\\* TALKASA_PROXY_HTTPS \\*\\/\\n)/\', \'$1\'.$insert, $text, 1, $count);'
            .'     if ($count > 0) { $changed = true; }'
            .'   }'
            .' }'
            .' if ($changed) { file_put_contents($cfg, $text); }'
            .' exit(0);';

        try {
            $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T '.escapeshellarg($appService)
                .' php -r '.escapeshellarg($php),
                60
            );
        } catch (\Throwable $e) {
            // wp-config may not exist until first successful WordPress boot.
            Log::warning('WordPress wp-config hardening skipped', [
                'container_path' => $containerPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure a platform-managed real cron job runs wp-cron.php (HTTP wp-cron disabled above).
     */
    public function ensureSystemCronJob(Service $service): ?ContainerCronJob
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment');

        if (! $service->isWordPressContainer()) {
            return null;
        }

        if (! $service->containerDeployment) {
            return null;
        }

        $existing = ContainerCronJob::query()
            ->where('service_id', $service->id)
            ->where(function ($query) {
                $query->where('name', self::WP_CRON_JOB_NAME)
                    ->orWhere('command', self::WP_CRON_COMMAND);
            })
            ->first();

        if ($existing) {
            $updates = [];
            if ($existing->command !== self::WP_CRON_COMMAND) {
                $updates['command'] = self::WP_CRON_COMMAND;
            }
            if ($existing->schedule !== self::WP_CRON_SCHEDULE) {
                $updates['schedule'] = self::WP_CRON_SCHEDULE;
            }
            if (! $existing->enabled) {
                $updates['enabled'] = true;
            }
            if (! $existing->is_system) {
                $updates['is_system'] = true;
            }
            if ($existing->paused_by_system) {
                $updates['paused_by_system'] = false;
            }
            if ($updates !== []) {
                $cron = app(ContainerCronService::class);
                $updates['next_run_at'] = $cron->calculateNextRun(
                    $updates['schedule'] ?? $existing->schedule
                );
                $existing->update($updates);
            }

            return $existing->fresh();
        }

        try {
            return app(ContainerCronService::class)->createSystem($service, [
                'name' => self::WP_CRON_JOB_NAME,
                'schedule' => self::WP_CRON_SCHEDULE,
                'command' => self::WP_CRON_COMMAND,
                'enabled' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create WordPress system cron job', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Apply host ini + in-container wp-config + cron for a live WordPress deployment.
     */
    public function hardenDeployedStack(
        SSHService $ssh,
        Service $service,
        string $containerName,
        string $containerPath
    ): void {
        $this->ensureUploadsIniFile($ssh, $containerName);
        $this->ensureApacheWorkersFile(
            $ssh,
            $containerName,
            (int) ($service->containerDeployment?->memory_limit_mb ?: 0),
        );
        $this->ensureWpConfigHardening($ssh, $containerPath, $containerName);
        $hostAppPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$containerName.'/app';
        $this->wrapHtaccessOnHost($ssh, $hostAppPath);
        $this->persistApacheModulesOnCompose($ssh, $containerPath, $containerName);
        try {
            $this->enableApacheModulesInContainer($ssh, $containerPath, $containerName);
        } catch (\Throwable $e) {
            Log::warning('WordPress Apache module enable skipped', [
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }
        $this->ensureWritableFilesystem($ssh, $hostAppPath, $containerPath, $containerName);
        $this->ensureSystemCronJob($service);
        $this->ensureNginxUploadLimits($service);
    }

    /**
     * Official wordpress:* + bind mounts often leave core/files owned by root.
     * Apache runs as www-data (uid 33) and cannot upload media or install plugins
     * until /var/www/html (especially wp-content) is writable.
     */
    public function ensureWritableFilesystem(
        SSHService $ssh,
        string $hostAppPath,
        string $containerPath,
        string $appService
    ): void {
        try {
            $ssh->exec($this->buildHostPermissionsCommand($hostAppPath), 300);
        } catch (\Throwable $e) {
            try {
                $ssh->exec(
                    'cd '.escapeshellarg($containerPath)
                    .' && docker compose exec -u 0 -T '.escapeshellarg($appService)
                    .' sh -lc '.escapeshellarg($this->inContainerPermissionsScript()),
                    300
                );
            } catch (\Throwable $containerError) {
                Log::warning('WordPress filesystem permission normalize failed', [
                    'host_app_path' => $hostAppPath,
                    'host_error' => $e->getMessage(),
                    'container_error' => $containerError->getMessage(),
                ]);

                return;
            }

            Log::warning('Host WordPress permission normalize failed; applied via container', [
                'host_app_path' => $hostAppPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Make bind-mounted WordPress files readable/writable by Apache (www-data / uid 33).
     */
    public function buildHostPermissionsCommand(string $hostAppPath): string
    {
        $path = escapeshellarg(rtrim($hostAppPath, '/'));

        return 'if [ -d '.$path.' ]; then'
            .'  mkdir -p '.$path.'/wp-content/uploads'
            .' '.$path.'/wp-content/plugins'
            .' '.$path.'/wp-content/themes'
            .' '.$path.'/wp-content/upgrade'
            .' '.$path.'/wp-content/mu-plugins'
            .' '.$path.'/wp-content/uploads/sessions'
            .'  && chown -R 33:33 '.$path
            .'  && chmod -R u+rwX,g+rX,o+rX '.$path
            .'  && if [ -f '.$path.'/wp-config.php ]; then chmod 640 '.$path.'/wp-config.php; fi'
            .'  && if [ -d '.$path.'/wp-content ]; then chmod -R ug+rwX '.$path.'/wp-content; fi'
            .'  ; fi';
    }

    public function inContainerPermissionsScript(): string
    {
        return 'mkdir -p /var/www/html/wp-content/uploads'
            .' /var/www/html/wp-content/plugins'
            .' /var/www/html/wp-content/themes'
            .' /var/www/html/wp-content/upgrade'
            .' /var/www/html/wp-content/mu-plugins'
            .' /var/www/html/wp-content/uploads/sessions'
            .' && chown -R www-data:www-data /var/www/html'
            .' && find /var/www/html -type d -exec chmod 755 {} +'
            .' && find /var/www/html -type f -exec chmod 644 {} +'
            .' && chmod 640 /var/www/html/wp-config.php 2>/dev/null || true'
            .' && find /var/www/html/wp-content -type d -exec chmod 775 {} + 2>/dev/null || true'
            .' && find /var/www/html/wp-content -type f -exec chmod 664 {} + 2>/dev/null || true';
    }

    /**
     * Official wordpress:apache does not enable mod_headers. DirectAdmin .htaccess
     * `Header always set …` then 500s every request (Apache "Invalid command Header").
     *
     * @return list<string>
     */
    public function apacheForegroundWithModulesCommand(): array
    {
        return [
            'bash',
            '-lc',
            'a2enmod headers rewrite expires >/dev/null 2>&1 || true; exec apache2-foreground',
        ];
    }

    /**
     * Wrap bare Header/Expires lines so Apache can start even if a module is missing.
     */
    public function wrapHtaccessOptionalApacheDirectives(string $htaccess): string
    {
        $htaccess = $this->wrapHtaccessDirectiveRuns($htaccess, 'Header', 'mod_headers.c');
        $htaccess = $this->wrapHtaccessDirectiveRuns($htaccess, 'ExpiresActive', 'mod_expires.c');
        $htaccess = $this->wrapHtaccessDirectiveRuns($htaccess, 'ExpiresDefault', 'mod_expires.c');
        $htaccess = $this->wrapHtaccessDirectiveRuns($htaccess, 'ExpiresByType', 'mod_expires.c');

        return $htaccess;
    }

    public function patchComposeApacheModuleCommand(string $yaml, string $serviceName): string
    {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return $yaml;
        }

        $key = app(ContainerDeploymentService::class)->resolveComposeAppServiceKey($compose, $serviceName);
        if ($key === null || ! is_array($compose['services'][$key] ?? null)) {
            return $yaml;
        }

        $command = $compose['services'][$key]['command'] ?? null;
        if (is_array($command) && ($command[0] ?? '') === 'talksasa-php-server') {
            return $yaml;
        }
        if (is_string($command) && str_contains($command, 'talksasa-php-server')) {
            return $yaml;
        }

        $joined = is_array($command) ? implode(' ', $command) : (string) $command;
        if (str_contains($joined, 'a2enmod headers')) {
            return $yaml;
        }

        $compose['services'][$key]['command'] = $this->apacheForegroundWithModulesCommand();

        return Yaml::dump($compose, 10, 2);
    }

    public function enableApacheModulesInContainer(
        SSHService $ssh,
        string $containerPath,
        string $appService
    ): void {
        $enable = 'a2enmod headers rewrite expires >/dev/null 2>&1 || true; '
            .'apache2ctl graceful >/dev/null 2>&1 || kill -USR1 1 2>/dev/null || true';
        $ssh->exec(
            'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u 0 -T '.escapeshellarg($appService)
            .' bash -lc '.escapeshellarg($enable),
            30
        );
    }

    public function persistApacheModulesOnCompose(
        SSHService $ssh,
        string $containerPath,
        string $containerName
    ): void {
        $composePath = $containerPath.'/docker-compose.yml';
        try {
            $yaml = trim((string) $ssh->exec('cat '.escapeshellarg($composePath), 15));
        } catch (\Throwable) {
            return;
        }
        if ($yaml === '') {
            return;
        }

        $patched = $this->patchComposeApacheModuleCommand($yaml, $containerName);
        if ($patched === $yaml) {
            return;
        }

        $ssh->upload($patched, $composePath);
    }

    public function wrapHtaccessOnHost(SSHService $ssh, string $hostAppPath): bool
    {
        $path = rtrim($hostAppPath, '/').'/.htaccess';
        try {
            $exists = trim($ssh->exec('test -f '.escapeshellarg($path).' && echo yes || echo no', 10));
            if ($exists !== 'yes') {
                return false;
            }
            $raw = (string) $ssh->exec('cat '.escapeshellarg($path), 20);
        } catch (\Throwable) {
            return false;
        }

        $updated = $this->wrapHtaccessOptionalApacheDirectives($raw);
        if ($updated === $raw) {
            return false;
        }

        $ssh->upload($updated, $path);

        return true;
    }

    private function wrapHtaccessDirectiveRuns(string $text, string $directive, string $module): string
    {
        if (! preg_match('/^\s*'.preg_quote($directive, '/').'\b/mi', $text)) {
            return $text;
        }

        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $out = [];
        $inModule = false;
        $pending = [];
        $moduleOpen = '/<IfModule\s+'.preg_quote($module, '/').'\s*>/i';

        $flush = function () use (&$out, &$pending, $module): void {
            if ($pending === []) {
                return;
            }
            $out[] = '<IfModule '.$module.'>';
            foreach ($pending as $line) {
                $out[] = $line;
            }
            $out[] = '</IfModule>';
            $pending = [];
        };

        foreach ($lines as $line) {
            if (preg_match($moduleOpen, $line) === 1) {
                $flush();
                $inModule = true;
                $out[] = $line;

                continue;
            }
            if ($inModule && preg_match('/<\s*\/IfModule\s*>/i', $line) === 1) {
                $inModule = false;
                $out[] = $line;

                continue;
            }
            if (! $inModule && preg_match('/^\s*'.preg_quote($directive, '/').'\b/i', $line) === 1) {
                $pending[] = $line;

                continue;
            }
            $flush();
            $out[] = $line;
        }
        $flush();

        $joined = implode("\n", $out);
        if (str_ends_with($text, "\n") && ! str_ends_with($joined, "\n")) {
            $joined .= "\n";
        }

        return $joined;
    }

    /**
     * Legacy nginx vhosts default to 1m and return HTML 413 on media uploads.
     */
    public function ensureNginxUploadLimits(Service $service): void
    {
        $service->loadMissing('containerDeployment.domains');

        $domains = $service->containerDeployment?->domains
            ?? ContainerDomain::query()
                ->whereHas('deployment', fn ($q) => $q->where('service_id', $service->id))
                ->get();

        if (! $domains || $domains->isEmpty()) {
            return;
        }

        $nginx = app(NginxProxyService::class);

        foreach ($domains as $domain) {
            try {
                $nginx->ensureUploadLimit($domain);
            } catch (\Throwable $e) {
                Log::warning('WordPress nginx upload-limit refresh failed', [
                    'service_id' => $service->id,
                    'domain' => $domain->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
