<?php

namespace App\Services\Provisioning;

use App\Models\ContainerCronJob;
use App\Models\ContainerDomain;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

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
            .' } elseif (! str_contains($text, \'DISABLE_WP_CRON\')) {'
            .'   $insert = "if (! defined(\'DISABLE_WP_CRON\')) {\\n    define(\'DISABLE_WP_CRON\', true);\\n}\\n";'
            .'   $count = 0;'
            .'   $text = preg_replace(\'/(\\/\\* TALKASA_PROXY_HTTPS \\*\\/\\n)/\', \'$1\'.$insert, $text, 1, $count);'
            .'   if ($count > 0) { $changed = true; }'
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

        if (($service->product?->containerTemplate?->slug ?? '') !== 'wordpress') {
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
            return app(ContainerCronService::class)->create($service, [
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
        $this->ensureWpConfigHardening($ssh, $containerPath, $containerName);
        $this->ensureSystemCronJob($service);
        $this->ensureNginxUploadLimits($service);
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
