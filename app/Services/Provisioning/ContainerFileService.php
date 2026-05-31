<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerFileAuditLog;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

class ContainerFileService
{
    private const BASE = '/opt/talksasa/containers/';

    private const APP_SUBDIR = '/app';

    public function __construct(private SSHService $ssh) {}

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
        $absPath = $basePath.(count($resolved) > 0 ? '/'.implode('/', $resolved) : '');

        // Verify the path stays within the container directory.
        // Append '/' to both sides before the prefix check to prevent the confusion
        // where '/opt/containers/user-1' would incorrectly match '/opt/containers/user-10'.
        $realBase = rtrim($basePath, '/');
        if (strpos(rtrim($absPath, '/').'/', $realBase.'/') !== 0) {
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
        $entries = array_map(function (array $entry) {
            if (($entry['type'] ?? '') === 'dir') {
                $entry['editable'] = false;

                return $entry;
            }

            $entry['editable'] = $this->isEditableRelativePath('/'.ltrim((string) ($entry['name'] ?? ''), '/'));

            return $entry;
        }, $entries);

        // Log the action
        $this->auditLog($service, $deployment, $user, 'list', $relPath, $ip);

        // Build breadcrumbs
        $breadcrumbs = [];
        $breadcrumbs[] = ['label' => 'Home', 'path' => '/'];

        $current = '';
        foreach (array_filter(explode('/', trim($relPath, '/'))) as $segment) {
            $current .= '/'.$segment;
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
     * @return array{path: string, content: string, size: int, editable: bool, language: string}
     */
    public function readTextFile(Service $service, ContainerDeployment $deployment, string $relPath, User $user, string $ip): array
    {
        if (! $this->isEditableRelativePath($relPath)) {
            throw new \InvalidArgumentException('This file type cannot be edited in the browser.');
        }

        $absPath = $this->resolveAndGuardPath($deployment, $relPath);
        $this->assertRegularFile($absPath);

        $size = $this->fileSizeBytes($absPath);
        $maxBytes = (int) config('containers.file_editor.max_bytes', 524288);
        if ($size > $maxBytes) {
            throw new \InvalidArgumentException('File is too large to edit (max '.$this->formatBytes($maxBytes).').');
        }

        $content = $this->ssh->downloadFile($absPath);
        if (str_contains($content, "\0")) {
            throw new \InvalidArgumentException('Binary files cannot be edited in the browser.');
        }

        $this->auditLog($service, $deployment, $user, 'read', $relPath, $ip, [
            'size' => $size,
        ]);

        return [
            'path' => $relPath,
            'content' => $content,
            'size' => $size,
            'editable' => true,
            'language' => $this->detectLanguage($relPath),
        ];
    }

    public function writeTextFile(
        Service $service,
        ContainerDeployment $deployment,
        string $relPath,
        string $content,
        User $user,
        string $ip
    ): void {
        if (! $this->isEditableRelativePath($relPath)) {
            throw new \InvalidArgumentException('This file type cannot be edited in the browser.');
        }

        $maxBytes = (int) config('containers.file_editor.max_bytes', 524288);
        if (strlen($content) > $maxBytes) {
            throw new \InvalidArgumentException('File content exceeds the maximum editable size.');
        }

        if (str_contains($content, "\0")) {
            throw new \InvalidArgumentException('Binary content cannot be saved from the editor.');
        }

        $absPath = $this->resolveAndGuardPath($deployment, $relPath);
        $this->assertRegularFile($absPath);

        $this->auditLog($service, $deployment, $user, 'edit', $relPath, $ip, [
            'size' => strlen($content),
        ]);

        $this->ssh->upload($content, $absPath);
    }

    public function isEditableRelativePath(string $relPath): bool
    {
        $name = basename(trim($relPath, '/'));
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        $editableExtensions = config('containers.file_editor.editable_extensions', []);
        if (! is_array($editableExtensions) || $editableExtensions === []) {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, $editableExtensions, true)) {
            return true;
        }

        if (str_starts_with($name, '.')) {
            $segment = strtolower(substr($name, 1));

            return in_array($segment, $editableExtensions, true);
        }

        return in_array(strtolower($name), $editableExtensions, true);
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
                $output = $this->ssh->exec('du -sb '.escapeshellarg($basePath));
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
        $basePath = self::BASE.$deployment->container_name;

        // Prefer app mount directory if it exists on disk, even for legacy rows
        // where template linkage may be missing/inconsistent.
        $appPath = $basePath.self::APP_SUBDIR;
        try {
            $exists = trim($this->ssh->exec('[ -d '.escapeshellarg($appPath).' ] && echo yes || echo no'));
            if ($exists === 'yes') {
                return $appPath;
            }
        } catch (\Throwable $e) {
            // Ignore probe failures and fall back to template-based resolution.
        }

        $template = $deployment->service?->product?->containerTemplate;
        $volumePaths = $template?->volume_paths;
        if (is_array($volumePaths) && array_key_exists('app_data', $volumePaths)) {
            return $basePath.self::APP_SUBDIR;
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

        return round($bytes, 2).' '.$units[$pow];
    }

    private function assertRegularFile(string $absPath): void
    {
        $pathArg = escapeshellarg($absPath);
        $result = trim($this->ssh->exec("[ -f {$pathArg} ] && echo file || echo missing", 10));
        if ($result !== 'file') {
            throw new \InvalidArgumentException('Path is not a regular file.');
        }
    }

    private function fileSizeBytes(string $absPath): int
    {
        $pathArg = escapeshellarg($absPath);
        $output = trim($this->ssh->exec("stat -c%s {$pathArg} 2>/dev/null || stat -f%z {$pathArg}", 10));

        return max(0, (int) $output);
    }

    private function detectLanguage(string $relPath): string
    {
        $extension = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));

        return match ($extension) {
            'php', 'blade' => 'php',
            'js', 'jsx', 'ts', 'tsx', 'vue' => 'javascript',
            'css', 'scss' => 'css',
            'json' => 'json',
            'xml', 'html', 'htm' => 'html',
            'yml', 'yaml' => 'yaml',
            'sql' => 'sql',
            'env', 'example', 'ini', 'conf' => 'plaintext',
            default => 'plaintext',
        };
    }
}
