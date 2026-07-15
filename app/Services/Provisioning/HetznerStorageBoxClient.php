<?php

namespace App\Services\Provisioning;

use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SFTP;

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
            $lastError instanceof \Exception ? $lastError : null
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

    private function host(): string
    {
        return trim((string) (env('HETZNER_STORAGE_HOST') ?: Setting::getValue('hetzner_storage_host', '')));
    }

    private function port(): int
    {
        $port = env('HETZNER_STORAGE_PORT') ?: Setting::getValue('hetzner_storage_port', '23');

        return max(1, (int) $port);
    }

    private function username(): string
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

    private function basePath(): string
    {
        return trim((string) (env('HETZNER_STORAGE_PATH') ?: Setting::getValue('hetzner_storage_path', 'backups/containers')));
    }
}
