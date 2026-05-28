<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Models\User;
use App\Models\ContainerDeployment;
use App\Models\ContainerFileAuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

class ContainerFileService
{
    private const BASE = '/opt/talksasa/containers/';
    private const APP_SUBDIR = '/app';

    public function __construct(private \App\Services\SSH\SSHService $ssh)
    {
    }

    /**
     * Resolve and guard a relative path to prevent traversal attacks
     */
    public function resolveAndGuardPath(ContainerDeployment $deployment, string $relative): string
    {
        // Normalize the path: remove double slashes, resolve . and ..
        $parts = array_filter(explode('/', trim($relative, '/')), fn ($p) => $p !== '' && $p !== '.');
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        // Build the final absolute path
        $basePath = $this->resolveBasePath($deployment);
        $absPath = $basePath . (count($resolved) > 0 ? '/' . implode('/', $resolved) : '');

        // Verify the path stays within the container directory.
        // Append '/' to both sides before the prefix check to prevent the confusion
        // where '/opt/containers/user-1' would incorrectly match '/opt/containers/user-10'.
        $realBase = rtrim($basePath, '/');
        if (strpos(rtrim($absPath, '/') . '/', $realBase . '/') !== 0) {
            throw new \InvalidArgumentException('Path traversal detected');
        }

        return $absPath;
    }

    /**
     * List directory contents
     */
    public function listDirectory(Service $service, ContainerDeployment $deployment, string $relPath, User $user, string $ip): array
    {
        $absPath = $this->resolveAndGuardPath($deployment, $relPath);
        $entries = $this->ssh->listDir($absPath);

        // Log the action
        $this->auditLog($service, $deployment, $user, 'list', $relPath, $ip);

        // Build breadcrumbs
        $breadcrumbs = [];
        $breadcrumbs[] = ['label' => 'Home', 'path' => '/'];

        $current = '';
        foreach (array_filter(explode('/', trim($relPath, '/'))) as $segment) {
            $current .= '/' . $segment;
            $breadcrumbs[] = ['label' => $segment, 'path' => $current];
        }

        return [
            'entries' => $entries,
            'path' => $relPath ?: '/',
            'breadcrumbs' => $breadcrumbs,
        ];
    }

    /**
     * Download file contents
     */
    public function download(Service $service, ContainerDeployment $deployment, string $relPath, User $user, string $ip): string
    {
        $absPath = $this->resolveAndGuardPath($deployment, $relPath);
        $content = $this->ssh->downloadFile($absPath);

        // Log the action
        $this->auditLog($service, $deployment, $user, 'download', $relPath, $ip);

        return $content;
    }

    /**
     * Upload file
     */
    public function upload(Service $service, ContainerDeployment $deployment, string $relPath, UploadedFile $file, User $user, string $ip): void
    {
        $absPath = $this->resolveAndGuardPath($deployment, $relPath);

        // Log before upload (so failed ops are still tracked)
        $this->auditLog($service, $deployment, $user, 'upload', $relPath, $ip, [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        // Upload the file
        $this->ssh->upload($file->get(), $absPath);
    }

    /**
     * Delete file or directory
     */
    public function delete(Service $service, ContainerDeployment $deployment, string $relPath, User $user, string $ip): void
    {
        $absPath = $this->resolveAndGuardPath($deployment, $relPath);

        // Log before delete (so failed ops are still tracked)
        $this->auditLog($service, $deployment, $user, 'delete', $relPath, $ip);

        // Determine if it's a directory by trying to list it
        try {
            $this->ssh->listDir($absPath);
            $this->ssh->deleteDir($absPath);
        } catch (\Exception $e) {
            // Not a directory, try as file
            $this->ssh->deleteFile($absPath);
        }
    }

    /**
     * Create directory
     */
    public function mkdir(Service $service, ContainerDeployment $deployment, string $relPath, User $user, string $ip): void
    {
        $absPath = $this->resolveAndGuardPath($deployment, $relPath);

        // Log before mkdir
        $this->auditLog($service, $deployment, $user, 'mkdir', $relPath, $ip);

        $this->ssh->mkdirp($absPath);
    }

    /**
     * Get storage usage for the container
     */
    public function getStorageUsage(ContainerDeployment $deployment): array
    {
        return Cache::remember("storage_stats_{$deployment->id}", 300, function () use ($deployment) {
            $basePath = $this->resolveBasePath($deployment);

            try {
                $output = $this->ssh->exec("du -sb " . escapeshellarg($basePath));
                $parts = explode("\t", trim($output));
                $bytes = (int) $parts[0];

                return [
                    'used_bytes' => $bytes,
                    'human' => $this->formatBytes($bytes),
                ];
            } catch (\Exception $e) {
                return [
                    'used_bytes' => 0,
                    'human' => '0 B',
                ];
            }
        });
    }

    private function resolveBasePath(ContainerDeployment $deployment): string
    {
        $basePath = self::BASE . $deployment->container_name;

        $template = $deployment->service?->product?->containerTemplate;
        $volumePaths = $template?->volume_paths;
        if (is_array($volumePaths) && array_key_exists('app_data', $volumePaths)) {
            return $basePath . self::APP_SUBDIR;
        }

        return $basePath;
    }

    /**
     * Log file operation to audit trail
     */
    private function auditLog(Service $service, ContainerDeployment $deployment, User $user, string $action, string $path, string $ip, array $metadata = []): void
    {
        ContainerFileAuditLog::create([
            'service_id' => $service->id,
            'user_id' => $user->id,
            'deployment_id' => $deployment->id,
            'action' => $action,
            'path' => $path,
            'metadata' => $metadata ?: null,
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
