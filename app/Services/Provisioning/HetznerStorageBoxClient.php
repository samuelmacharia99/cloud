<?php

namespace App\Services\Provisioning;

use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

class HetznerStorageBoxClient
{
    private ?SFTP $sftp = null;

    public function driver(): string
    {
        $driver = (string) (env('BACKUP_STORAGE_DRIVER') ?: Setting::getValue('backup_storage_driver', 'node'));

        return in_array($driver, ['node', 'hetzner'], true) ? $driver : 'node';
    }

    public function usesHetzner(): bool
    {
        return $this->driver() === 'hetzner';
    }

    public function isConfigured(): bool
    {
        return $this->host() !== ''
            && $this->username() !== ''
            && $this->password() !== '';
    }

    /**
     * Path relative to the Storage Box SFTP home.
     * Leading slashes break many sub-accounts (jail has no /backups at filesystem root).
     */
    public function remotePathFor(string $backupFileName): string
    {
        $base = trim($this->basePath(), '/');
        $name = ltrim($backupFileName, '/');

        return $base === '' ? $name : $base.'/'.$name;
    }

    public function uploadFromLocal(string $localPath, string $remotePath): void
    {
        if (! is_readable($localPath)) {
            throw new Exception("Local backup file is not readable: {$localPath}");
        }

        $size = (int) filesize($localPath);
        if ($size <= 0) {
            throw new Exception("Local backup file is empty: {$localPath}");
        }

        $remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
        $attempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->disconnect();
                $sftp = $this->connect();
                $sftp->setTimeout(7200);

                $directory = dirname($remotePath);
                if ($directory !== '.' && $directory !== '') {
                    $this->ensureRemoteDirectory($sftp, $directory);
                }

                Log::info('Uploading backup to Hetzner Storage Box', [
                    'attempt' => $attempt,
                    'remote_path' => $remotePath,
                    'bytes' => $size,
                    'pwd' => @$sftp->pwd() ?: null,
                ]);

                if (! $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
                    throw new Exception($this->formatSftpFailure('upload', $remotePath, $sftp));
                }

                $remoteSize = $sftp->filesize($remotePath);
                if ($remoteSize === false || (int) $remoteSize !== $size) {
                    throw new Exception(
                        "Hetzner upload size mismatch for {$remotePath}: local={$size} remote="
                        .($remoteSize === false ? 'missing' : (string) $remoteSize)
                    );
                }

                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('Hetzner Storage Box upload attempt failed', [
                    'attempt' => $attempt,
                    'remote_path' => $remotePath,
                    'error' => $e->getMessage(),
                ]);
                $this->disconnect();

                if ($attempt < $attempts) {
                    sleep(2 * $attempt);
                }
            }
        }

        throw new Exception(
            'Failed to upload backup to Hetzner Storage Box at '.$remotePath
            .': '.($lastError?->getMessage() ?? 'unknown error'),
            0,
            $lastError instanceof Exception ? $lastError : null
        );
    }

    public function downloadToLocal(string $remotePath, string $localPath): void
    {
        $remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
        $sftp = $this->connect();
        $sftp->setTimeout(7200);

        $directory = dirname($localPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if ($sftp->get($remotePath, $localPath) === false) {
            throw new Exception($this->formatSftpFailure('download', $remotePath, $sftp));
        }
    }

    public function delete(string $remotePath): void
    {
        $remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
        $sftp = $this->connect();
        @$sftp->delete($remotePath);
    }

    /**
     * Lightweight connectivity + directory probe used by admin settings.
     *
     * @return array{ok: bool, message: string, pwd?: string, base_path?: string}
     */
    public function testConnection(): array
    {
        try {
            if (! $this->isConfigured()) {
                return ['ok' => false, 'message' => 'Host, username, and password are required.'];
            }

            $this->disconnect();
            $sftp = $this->connect();
            $pwd = (string) ($sftp->pwd() ?: '/');
            $base = trim($this->basePath(), '/');

            if ($base !== '') {
                $this->ensureRemoteDirectory($sftp, $base);
            }

            $probe = ($base === '' ? '' : $base.'/').'.talksasa-probe-'.bin2hex(random_bytes(4));
            if (! $sftp->put($probe, "ok\n")) {
                throw new Exception($this->formatSftpFailure('probe write', $probe, $sftp));
            }
            @$sftp->delete($probe);
            $this->disconnect();

            return [
                'ok' => true,
                'message' => 'Connected and can write under the configured base path.',
                'pwd' => $pwd,
                'base_path' => $base === '' ? '(home)' : $base,
            ];
        } catch (\Throwable $e) {
            $this->disconnect();

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function ensureBaseDirectoryExists(): void
    {
        $base = trim($this->basePath(), '/');
        if ($base === '') {
            return;
        }

        $sftp = $this->connect();
        $this->ensureRemoteDirectory($sftp, $base);
    }

    public function remoteFilesize(string $remotePath): int
    {
        $remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
        $sftp = $this->connect();
        $size = $sftp->filesize($remotePath);
        if ($size === false) {
            throw new Exception($this->formatSftpFailure('stat', $remotePath, $sftp));
        }

        return (int) $size;
    }

    /**
     * @return array{host: string, port: int, username: string, password: string}
     */
    public function connectionConfig(): array
    {
        return [
            'host' => $this->host(),
            'port' => $this->port(),
            'username' => $this->username(),
            'password' => $this->password(),
        ];
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, base_path: string, driver: string, uses_hetzner: bool, configured: bool}
     */
    public function configurationSummary(): array
    {
        return [
            'host' => $this->host(),
            'port' => $this->port(),
            'username' => $this->username(),
            'password' => $this->password(),
            'base_path' => trim($this->basePath(), '/'),
            'driver' => $this->driver(),
            'uses_hetzner' => $this->usesHetzner(),
            'configured' => $this->isConfigured(),
        ];
    }

    /**
     * Live quota from the Storage Box (cached ~10 minutes).
     *
     * @return array<string, mixed>
     */
    public function diskUsage(bool $fresh = false): array
    {
        if (! $this->isConfigured()) {
            return [
                'available' => false,
                'error' => 'Storage Box is not configured.',
            ];
        }

        $cacheKey = 'hetzner_storage_box.disk_usage';

        if (! $fresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $usage = $this->fetchDiskUsageViaSsh();
            Cache::put($cacheKey, $usage, now()->addMinutes(10));

            return $usage;
        } catch (\Throwable $e) {
            Log::warning('Failed to read Hetzner Storage Box disk usage', [
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function clearDiskUsageCache(): void
    {
        Cache::forget('hetzner_storage_box.disk_usage');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDiskUsageViaSsh(): array
    {
        $ssh = new SSH2($this->host(), $this->port());
        $ssh->setTimeout(30);

        if (! $ssh->login($this->username(), $this->password())) {
            throw new Exception(
                'Could not log in over SSH on port '.$this->port()
                .'. Enable SSH support for the Storage Box in Hetzner Console.'
            );
        }

        $dfOutput = trim((string) $ssh->exec('df -h /home 2>/dev/null || df -h'));
        $parsed = $this->parseDfOutput($dfOutput);

        $base = trim($this->basePath(), '/');
        $backupPathHuman = null;
        $backupPathBytes = null;

        if ($base !== '') {
            $duOutput = trim((string) $ssh->exec('du -sb '.escapeshellarg($base).' 2>/dev/null | awk \'{print $1}\''));
            if ($duOutput !== '' && ctype_digit($duOutput)) {
                $backupPathBytes = (int) $duOutput;
                $backupPathHuman = $this->formatBytes($backupPathBytes);
            }
        }

        $version = trim((string) $ssh->exec('version 2>/dev/null || true'));

        return [
            'available' => true,
            'filesystem' => $parsed['filesystem'] ?? null,
            'mount_point' => $parsed['mount'] ?? '/home',
            'total_human' => $parsed['size'] ?? null,
            'used_human' => $parsed['used'] ?? null,
            'available_human' => $parsed['avail'] ?? null,
            'capacity_percent' => isset($parsed['capacity_percent']) ? (int) $parsed['capacity_percent'] : null,
            'total_bytes' => $this->parseHumanSize($parsed['size'] ?? ''),
            'used_bytes' => $this->parseHumanSize($parsed['used'] ?? ''),
            'available_bytes' => $this->parseHumanSize($parsed['avail'] ?? ''),
            'backup_path_human' => $backupPathHuman,
            'backup_path_bytes' => $backupPathBytes,
            'provider' => 'Hetzner Storage Box',
            'access' => 'SFTP/SSH port '.$this->port(),
            'server_version' => $version !== '' ? $version : null,
            'raw_df' => $dfOutput,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string|int|null>
     */
    private function parseDfOutput(string $output): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $output) ?: [])));
        $dataLine = null;

        foreach (array_reverse($lines) as $line) {
            if (preg_match('/\d+%/', $line) === 1) {
                $dataLine = $line;
                break;
            }
        }

        if ($dataLine === null) {
            throw new Exception('Could not parse Storage Box disk usage output.');
        }

        $parts = preg_split('/\s+/', $dataLine) ?: [];
        if (count($parts) < 5) {
            throw new Exception('Unexpected df output from Storage Box.');
        }

        return [
            'filesystem' => (string) $parts[0],
            'size' => (string) $parts[1],
            'used' => (string) $parts[2],
            'avail' => (string) $parts[3],
            'capacity_percent' => (int) rtrim((string) $parts[4], '%'),
            'mount' => (string) ($parts[5] ?? '/home'),
        ];
    }

    private function parseHumanSize(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (! preg_match('/^([\d.]+)\s*([KMGTPE]?)i?B?$/i', $value, $matches)) {
            return null;
        }

        $number = (float) $matches[1];
        $unit = strtoupper($matches[2] ?: '');

        $multiplier = match ($unit) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            'T' => 1024 ** 4,
            'P' => 1024 ** 5,
            'E' => 1024 ** 6,
            default => 1,
        };

        return (int) round($number * $multiplier);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 4) {
            return round($bytes / (1024 ** 4), 2).' TB';
        }
        if ($bytes >= 1024 ** 3) {
            return round($bytes / (1024 ** 3), 2).' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return round($bytes / (1024 ** 2), 2).' MB';
        }

        return round($bytes / 1024, 2).' KB';
    }

    public function disconnect(): void
    {
        if ($this->sftp) {
            @$this->sftp->disconnect();
            $this->sftp = null;
        }
    }

    private function connect(): SFTP
    {
        if ($this->sftp instanceof SFTP && $this->sftp->isConnected()) {
            return $this->sftp;
        }

        if (! $this->isConfigured()) {
            throw new Exception('Hetzner Storage Box is not configured. Set host, username, and password in Provisioning settings.');
        }

        $sftp = new SFTP($this->host(), $this->port());
        $sftp->setTimeout(60);

        if (! $sftp->login($this->username(), $this->password())) {
            throw new Exception(
                'Hetzner Storage Box SFTP authentication failed for '.$this->username().'@'.$this->host().':'.$this->port()
                .'. Check username/password and that external access uses port 23.'
            );
        }

        $this->sftp = $sftp;

        return $this->sftp;
    }

    private function ensureRemoteDirectory(SFTP $sftp, string $directory): void
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory === '' || $directory === '.') {
            return;
        }

        $path = '';
        foreach (explode('/', $directory) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $path = $path === '' ? $part : $path.'/'.$part;
            if ($sftp->is_dir($path)) {
                continue;
            }
            if (! $sftp->mkdir($path)) {
                // Race: another process may have created it.
                if ($sftp->is_dir($path)) {
                    continue;
                }

                throw new Exception($this->formatSftpFailure('mkdir', $path, $sftp));
            }
        }
    }

    private function formatSftpFailure(string $action, string $path, SFTP $sftp): string
    {
        $errors = [];
        if (method_exists($sftp, 'getLastSFTPError')) {
            $sftpError = $sftp->getLastSFTPError();
            if (is_string($sftpError) && $sftpError !== '') {
                $errors[] = $sftpError;
            }
        }
        if (method_exists($sftp, 'getErrors')) {
            foreach ((array) $sftp->getErrors() as $error) {
                if (is_string($error) && $error !== '') {
                    $errors[] = $error;
                }
            }
        }

        $detail = $errors === [] ? 'unknown SFTP error' : implode('; ', array_unique($errors));

        return "Hetzner Storage Box {$action} failed for {$path}: {$detail}";
    }

    public function host(): string
    {
        return trim((string) (env('HETZNER_STORAGE_HOST') ?: Setting::getValue('hetzner_storage_host', '')));
    }

    public function port(): int
    {
        $port = env('HETZNER_STORAGE_PORT') ?: Setting::getValue('hetzner_storage_port', '23');

        return max(1, (int) $port);
    }

    public function username(): string
    {
        return trim((string) (env('HETZNER_STORAGE_USERNAME') ?: Setting::getValue('hetzner_storage_username', '')));
    }

    private function password(): string
    {
        $raw = (string) (env('HETZNER_STORAGE_PASSWORD') ?: Setting::getValue('hetzner_storage_password', ''));
        if ($raw === '') {
            return '';
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return $raw;
        }
    }

    public function basePath(): string
    {
        return trim((string) (env('HETZNER_STORAGE_PATH') ?: Setting::getValue('hetzner_storage_path', 'backups/containers')));
    }
}
