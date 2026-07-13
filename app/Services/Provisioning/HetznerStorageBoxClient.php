<?php

namespace App\Services\Provisioning;

use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Crypt;
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

    public function remotePathFor(string $backupFileName): string
    {
        $base = trim($this->basePath(), '/');
        $name = ltrim($backupFileName, '/');

        return $base === '' ? '/'.$name : '/'.$base.'/'.$name;
    }

    public function uploadFromLocal(string $localPath, string $remotePath): void
    {
        $sftp = $this->connect();
        $this->ensureRemoteDirectory($sftp, dirname($remotePath));

        if (! $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new Exception("Failed to upload backup to Hetzner Storage Box at {$remotePath}");
        }
    }

    public function downloadToLocal(string $remotePath, string $localPath): void
    {
        $sftp = $this->connect();
        $directory = dirname($localPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if ($sftp->get($remotePath, $localPath) === false) {
            throw new Exception("Failed to download backup from Hetzner Storage Box at {$remotePath}");
        }
    }

    public function delete(string $remotePath): void
    {
        $sftp = $this->connect();
        @$sftp->delete($remotePath);
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
        if ($this->sftp instanceof SFTP) {
            return $this->sftp;
        }

        if (! $this->isConfigured()) {
            throw new Exception('Hetzner Storage Box is not configured. Set host, username, and password in Provisioning settings.');
        }

        $sftp = new SFTP($this->host(), $this->port());
        if (! $sftp->login($this->username(), $this->password())) {
            throw new Exception('Hetzner Storage Box SFTP authentication failed.');
        }

        $this->sftp = $sftp;

        return $this->sftp;
    }

    private function ensureRemoteDirectory(SFTP $sftp, string $directory): void
    {
        if ($directory === '/' || $directory === '.' || $directory === '') {
            return;
        }

        $parts = array_filter(explode('/', trim($directory, '/')));
        $path = '';
        foreach ($parts as $part) {
            $path .= '/'.$part;
            if ($sftp->is_dir($path)) {
                continue;
            }
            @$sftp->mkdir($path);
        }
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
        return trim((string) (env('HETZNER_STORAGE_PATH') ?: Setting::getValue('hetzner_storage_path', '/backups/containers')));
    }
}
