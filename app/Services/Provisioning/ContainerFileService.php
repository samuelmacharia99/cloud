<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerFileAuditLog;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use App\Services\Terminal\ContainerDockerExecUserResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ContainerFileService
{
    private const BASE = '/opt/talksasa/containers/';

    private const APP_SUBDIR = '/app';

    /** @var list<string> */
    private const BLOCKED_VIEW_EXTENSIONS = [
        'zip', 'gz', 'tar', 'tgz', 'bz2', 'xz', '7z', 'rar',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'avif',
        'pdf', 'exe', 'dll', 'so', 'dylib', 'bin', 'dat',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'mp3', 'mp4', 'avi', 'mov', 'mkv', 'webm', 'wav', 'flac',
        'pyc', 'class', 'jar', 'war', 'deb', 'rpm',
    ];

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
        $tempDirName = $this->tempDirName();
        $entries = array_values(array_filter(
            $this->ssh->listDir($absPath),
            fn (array $entry) => ($entry['name'] ?? '') !== $tempDirName,
        ));
        $entries = array_map(function (array $entry) {
            if (($entry['type'] ?? '') === 'dir') {
                $entry['viewable'] = false;
                $entry['editable'] = false;
                $entry['archive'] = false;

                return $entry;
            }

            $fileRel = '/'.ltrim((string) ($entry['name'] ?? ''), '/');
            $size = max(0, (int) ($entry['size'] ?? 0));
            $entry['viewable'] = $this->canViewFile($fileRel, $size);
            $entry['editable'] = $this->canEditFile($fileRel, $size);
            $entry['archive'] = ContainerArchiveCommands::kind((string) ($entry['name'] ?? '')) !== null;

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
     * @return array{path: string, content: string, size: int, editable: bool, read_only: bool, language: string}
     */
    public function readTextFile(Service $service, ContainerDeployment $deployment, string $relPath, User $user, string $ip): array
    {
        if (! $this->isViewableRelativePath($relPath)) {
            throw new \InvalidArgumentException('This file type cannot be opened in the browser.');
        }

        $absPath = $this->resolveAndGuardPath($deployment, $relPath);
        $this->assertRegularFile($absPath);

        $size = $this->fileSizeBytes($absPath);
        $maxViewBytes = $this->maxViewBytes();
        if ($size > $maxViewBytes) {
            throw new \InvalidArgumentException('File is too large to view (max '.$this->formatBytes($maxViewBytes).').');
        }

        $content = $this->ssh->downloadFile($absPath);
        if (str_contains($content, "\0")) {
            throw new \InvalidArgumentException('Binary files cannot be opened in the browser.');
        }

        $editable = $this->canEditFile($relPath, $size);

        $this->auditLog($service, $deployment, $user, 'read', $relPath, $ip, [
            'size' => $size,
            'read_only' => ! $editable,
        ]);

        return [
            'path' => $relPath,
            'content' => $content,
            'size' => $size,
            'editable' => $editable,
            'read_only' => ! $editable,
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

        $maxBytes = $this->maxEditBytes();
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
        return $this->matchesExtensionList($relPath, $this->editableExtensions());
    }

    public function isViewableRelativePath(string $relPath): bool
    {
        if ($this->isEditableRelativePath($relPath)) {
            return true;
        }

        $name = basename(trim($relPath, '/'));
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '') {
            return false;
        }

        return ! in_array($extension, self::BLOCKED_VIEW_EXTENSIONS, true);
    }

    public function canEditFile(string $relPath, int $size): bool
    {
        return $this->isEditableRelativePath($relPath) && $size <= $this->maxEditBytes();
    }

    public function canViewFile(string $relPath, int $size): bool
    {
        return $this->isViewableRelativePath($relPath) && $size <= $this->maxViewBytes();
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
     * Rename a file or directory (same parent directory).
     *
     * @return array{path: string}
     */
    public function rename(
        Service $service,
        ContainerDeployment $deployment,
        string $relPath,
        string $newName,
        User $user,
        string $ip
    ): array {
        $newName = trim($newName);
        if ($newName === '' || $newName === '.' || $newName === '..' || str_contains($newName, '/') || str_contains($newName, "\0")) {
            throw new \InvalidArgumentException('Invalid name.');
        }

        $absPath = $this->resolveAndGuardPath($deployment, $relPath);
        $parentRel = dirname($relPath);
        if ($parentRel === '.') {
            $parentRel = '/';
        }
        $newRelPath = $this->joinRelativePath($parentRel, $newName);
        $newAbsPath = $this->resolveAndGuardPath($deployment, $newRelPath);

        if (basename($relPath) === $newName) {
            return ['path' => $relPath];
        }

        if (! $this->pathExists($absPath)) {
            throw new \InvalidArgumentException('Path not found.');
        }

        if ($this->pathExists($newAbsPath)) {
            throw new \InvalidArgumentException('A file or folder with that name already exists.');
        }

        $this->auditLog($service, $deployment, $user, 'rename', $relPath, $ip, [
            'new_path' => $newRelPath,
            'new_name' => $newName,
        ]);

        $this->ssh->rename($absPath, $newAbsPath);

        return ['path' => $newRelPath];
    }

    /**
     * Create an empty text file.
     */
    public function createEmptyFile(Service $service, ContainerDeployment $deployment, string $relPath, User $user, string $ip): void
    {
        $name = basename(trim($relPath, '/'));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid file name.');
        }

        $absPath = $this->resolveAndGuardPath($deployment, $relPath);

        if ($this->pathExists($absPath)) {
            throw new \InvalidArgumentException('A file or folder with that name already exists.');
        }

        $this->auditLog($service, $deployment, $user, 'create', $relPath, $ip);

        $this->ssh->upload('', $absPath);
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
        if (is_array($volumePaths) && (
            array_key_exists('app_data', $volumePaths)
            || array_key_exists('wp_data', $volumePaths)
        )) {
            return $basePath.self::APP_SUBDIR;
        }

        if (($template?->slug ?? '') === 'wordpress') {
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

    private function maxEditBytes(): int
    {
        return max(1, (int) config('containers.file_editor.max_bytes', 524288));
    }

    private function maxViewBytes(): int
    {
        $viewMax = (int) config('containers.file_editor.view_max_bytes', 2097152);

        return max($this->maxEditBytes(), $viewMax);
    }

    /**
     * @return list<string>
     */
    private function editableExtensions(): array
    {
        $extensions = config('containers.file_editor.editable_extensions', []);

        return is_array($extensions) ? $extensions : [];
    }

    /**
     * @param  list<string>  $extensions
     */
    private function matchesExtensionList(string $relPath, array $extensions): bool
    {
        if ($extensions === []) {
            return false;
        }

        $name = basename(trim($relPath, '/'));
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, $extensions, true)) {
            return true;
        }

        if (str_starts_with($name, '.')) {
            $segment = strtolower(substr($name, 1));

            return in_array($segment, $extensions, true);
        }

        return in_array(strtolower($name), $extensions, true);
    }

    private function joinRelativePath(string $parent, string $name): string
    {
        if ($parent === '/' || $parent === '') {
            return '/'.$name;
        }

        return rtrim($parent, '/').'/'.$name;
    }

    private function pathExists(string $absPath): bool
    {
        $pathArg = escapeshellarg($absPath);
        $result = trim($this->ssh->exec("[ -e {$pathArg} ] && echo yes || echo no", 10));

        return $result === 'yes';
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk operations, streamed uploads, archives
    |--------------------------------------------------------------------------
    */

    /**
     * Delete many paths in one SSH session. Every path is guarded; the app
     * root itself is refused. Returns what was removed and what was not.
     *
     * @param  list<string>  $relPaths
     * @return array{deleted: list<string>, failed: array<string, string>}
     */
    public function batchDelete(Service $service, ContainerDeployment $deployment, array $relPaths, User $user, string $ip): array
    {
        $base = rtrim($this->resolveBasePath($deployment), '/');
        $targets = [];
        $failed = [];

        foreach (array_values(array_unique($relPaths)) as $relPath) {
            try {
                $abs = rtrim($this->resolveAndGuardPath($deployment, $relPath), '/');
            } catch (\InvalidArgumentException $e) {
                $failed[$relPath] = $e->getMessage();

                continue;
            }
            if ($abs === $base || $abs === '') {
                $failed[$relPath] = 'The application root cannot be deleted.';

                continue;
            }
            $targets[$relPath] = $abs;
        }

        if ($targets === []) {
            return ['deleted' => [], 'failed' => $failed];
        }

        $this->auditLog($service, $deployment, $user, 'batch_delete', (string) array_key_first($targets), $ip, [
            'paths' => array_keys($targets),
            'count' => count($targets),
        ]);

        $deleted = [];
        foreach (array_chunk($targets, 50, true) as $chunk) {
            $args = implode(' ', array_map('escapeshellarg', array_values($chunk)));
            $result = $this->ssh->execWithStatus('rm -rf -- '.$args, 300);
            if ($result['status'] === 0) {
                $deleted = array_merge($deleted, array_keys($chunk));

                continue;
            }
            // Find out which ones survived rather than failing the whole chunk.
            foreach ($chunk as $relPath => $abs) {
                if ($this->pathExists($abs)) {
                    $failed[$relPath] = $result['output'] !== '' ? $result['output'] : 'Could not delete.';
                } else {
                    $deleted[] = $relPath;
                }
            }
        }

        return ['deleted' => array_values($deleted), 'failed' => $failed];
    }

    /**
     * Move or copy paths into an existing directory. Names that already exist
     * at the destination are reported as conflicts and left untouched, and a
     * directory is never moved into itself.
     *
     * @param  list<string>  $relPaths
     * @return array{done: list<string>, conflicts: list<string>, failed: array<string, string>, destination: string}
     */
    public function moveOrCopy(
        Service $service,
        ContainerDeployment $deployment,
        array $relPaths,
        string $destRel,
        bool $copy,
        User $user,
        string $ip,
    ): array {
        $destAbs = rtrim($this->resolveAndGuardPath($deployment, $destRel), '/');
        if (! $this->isDirectory($destAbs)) {
            throw new \InvalidArgumentException('Destination folder does not exist.');
        }
        $destRelNormalized = '/'.trim($destRel, '/');
        if ($destRelNormalized === '//') {
            $destRelNormalized = '/';
        }

        $done = [];
        $conflicts = [];
        $failed = [];
        $base = rtrim($this->resolveBasePath($deployment), '/');

        foreach (array_values(array_unique($relPaths)) as $relPath) {
            try {
                $srcAbs = rtrim($this->resolveAndGuardPath($deployment, $relPath), '/');
            } catch (\InvalidArgumentException $e) {
                $failed[$relPath] = $e->getMessage();

                continue;
            }
            $name = basename($srcAbs);
            if ($srcAbs === $base || $name === '' || $name === '.' || $name === '..') {
                $failed[$relPath] = 'The application root cannot be moved.';

                continue;
            }
            if ($destAbs === $srcAbs || str_starts_with($destAbs.'/', $srcAbs.'/')) {
                $failed[$relPath] = 'A folder cannot be moved into itself.';

                continue;
            }
            if (dirname($srcAbs) === $destAbs) {
                $failed[$relPath] = 'Already in that folder.';

                continue;
            }
            $targetAbs = $destAbs.'/'.$name;
            if ($this->pathExists($targetAbs)) {
                $conflicts[] = $relPath;

                continue;
            }

            $command = ($copy ? 'cp -a --' : 'mv -n --').' '.escapeshellarg($srcAbs).' '.escapeshellarg($targetAbs);
            $result = $this->ssh->execWithStatus($command, 600);
            if ($result['status'] === 0) {
                $done[] = $relPath;
            } else {
                $failed[$relPath] = $result['output'] !== '' ? $result['output'] : ($copy ? 'Copy failed.' : 'Move failed.');
            }
        }

        if ($done !== []) {
            $this->auditLog($service, $deployment, $user, $copy ? 'copy' : 'move', $done[0], $ip, [
                'paths' => $done,
                'destination' => $destRelNormalized,
                'count' => count($done),
            ]);
        }

        return [
            'done' => $done,
            'conflicts' => $conflicts,
            'failed' => $failed,
            'destination' => $destRelNormalized,
        ];
    }

    /**
     * Refuse writes that would exceed the plan's disk allowance or the host's
     * free space. Usage is measured fresh, not from the 5-minute cache.
     */
    public function assertHasRoom(ContainerDeployment $deployment, int $bytesNeeded): void
    {
        if ($bytesNeeded <= 0) {
            return;
        }

        $base = $this->resolveBasePath($deployment);
        $free = $this->ssh->execWithStatus('df -B1 --output=avail '.escapeshellarg($base).' | tail -n 1', 20);
        $hostFree = (int) trim($free['output']);
        if ($free['status'] === 0 && $hostFree > 0 && $bytesNeeded > $hostFree) {
            throw new \InvalidArgumentException(sprintf(
                'Not enough space on the host: this needs %s but only %s is free.',
                $this->formatBytes($bytesNeeded),
                $this->formatBytes($hostFree),
            ));
        }

        $planBytes = $this->planDiskBytes($deployment);
        if ($planBytes === null) {
            return;
        }

        $usage = $this->ssh->execWithStatus('du -sb '.escapeshellarg($base).' | cut -f1', 60);
        $used = (int) trim($usage['output']);
        if ($used + $bytesNeeded > $planBytes) {
            throw new \InvalidArgumentException(sprintf(
                'Not enough space on your plan: this needs %s but only %s of %s is left. Delete files or upgrade the plan.',
                $this->formatBytes($bytesNeeded),
                $this->formatBytes(max(0, $planBytes - $used)),
                $this->formatBytes($planBytes),
            ));
        }
    }

    /**
     * The plan's disk allowance for this deployment in bytes, scaled by the
     * service's share of a shared package; null when the plan sets none.
     */
    public function planDiskBytes(ContainerDeployment $deployment): ?int
    {
        $service = $deployment->service;
        $product = $service?->product;
        if (! $product || ! method_exists($product, 'getIncludedContainerLimits')) {
            return null;
        }

        $limits = $product->getIncludedContainerLimits($product->containerTemplate, $deployment);
        $diskGb = (float) ($limits['disk_gb'] ?? 0);
        if ($diskGb <= 0) {
            return null;
        }

        $share = (float) ($service->service_meta['resource_share']['memory'] ?? $service->service_meta['resource_share']['cpu'] ?? 1.0);
        if ($share <= 0 || $share > 1) {
            $share = 1.0;
        }

        return (int) floor($diskGb * $share * 1024 * 1024 * 1024);
    }

    /**
     * Upload from the request's temp file straight over SFTP instead of
     * reading the whole file into memory. Returns the relative path written.
     */
    public function uploadStreamed(Service $service, ContainerDeployment $deployment, string $relDir, UploadedFile $file, User $user, string $ip): string
    {
        $name = $this->safeName($file->getClientOriginalName());
        $relPath = $this->joinRelativePath('/'.trim($relDir, '/'), $name);
        $absPath = $this->resolveAndGuardPath($deployment, $relPath);

        $this->assertHasRoom($deployment, (int) $file->getSize());

        $this->auditLog($service, $deployment, $user, 'upload', $relPath, $ip, [
            'filename' => $name,
            'size' => $file->getSize(),
        ]);

        $this->ssh->uploadFromLocal((string) $file->getRealPath(), $absPath, null, 900);
        $this->applyOwnership($deployment, $absPath);

        return $relPath;
    }

    /**
     * Scratch directory next to the app mount, never inside it. Created 0700.
     */
    public function tempDir(ContainerDeployment $deployment): string
    {
        $dir = self::BASE.$deployment->container_name.'/'.$this->tempDirName();
        $this->ssh->exec('mkdir -p '.escapeshellarg($dir).' && chmod 700 '.escapeshellarg($dir), 20);

        return $dir;
    }

    public function pruneTempDir(ContainerDeployment $deployment): void
    {
        $dir = self::BASE.$deployment->container_name.'/'.$this->tempDirName();
        $minutes = max(1, (int) config('containers.file_manager.operation_ttl_minutes', 60));
        try {
            $this->ssh->exec(ContainerArchiveCommands::pruneOlderThan($dir, $minutes), 60);
        } catch (\Throwable) {
            // Best effort; a stale scratch file is not worth failing the operation.
        }
    }

    /**
     * Validate an archive on the node without extracting it.
     *
     * @return array{kind: string, bytes: int, count: int}
     */
    public function inspectArchive(ContainerDeployment $deployment, string $relPath): array
    {
        $kind = ContainerArchiveCommands::kind($relPath);
        if ($kind === null) {
            throw new \InvalidArgumentException('Only .zip, .tar, .tar.gz and .tgz archives can be extracted.');
        }
        $absPath = $this->resolveAndGuardPath($deployment, $relPath);
        $this->assertRegularFile($absPath);
        $this->assertToolsFor($kind);

        $result = $this->ssh->execWithStatus(
            ContainerArchiveCommands::inspect($absPath, $kind, $this->maxExtractBytes()),
            600,
        );
        $parsed = $this->parseArchiveResult($result, $kind);

        return ['kind' => $kind, 'bytes' => $parsed['bytes'], 'count' => $parsed['count']];
    }

    /**
     * Validate, extract into a folder, fix ownership, optionally delete the archive.
     *
     * @param  (callable(int, string): void)|null  $progress
     * @return array{bytes: int, count: int, destination: string}
     */
    public function extractArchive(
        Service $service,
        ContainerDeployment $deployment,
        string $relPath,
        string $destRel,
        bool $deleteArchive,
        User $user,
        string $ip,
        ?callable $progress = null,
    ): array {
        $report = static function (int $percent, string $label) use ($progress): void {
            if ($progress) {
                $progress($percent, $label);
            }
        };

        $report(5, 'Checking the archive');
        $inspection = $this->inspectArchive($deployment, $relPath);
        $kind = $inspection['kind'];
        $absPath = $this->resolveAndGuardPath($deployment, $relPath);
        $destAbs = rtrim($this->resolveAndGuardPath($deployment, $destRel), '/');
        $destRelNormalized = '/'.trim($destRel, '/');
        if ($destRelNormalized === '//') {
            $destRelNormalized = '/';
        }

        $report(15, sprintf('Archive is safe: %d file(s), %s uncompressed. Checking space', $inspection['count'], $this->formatBytes($inspection['bytes'])));
        $this->assertHasRoom($deployment, $inspection['bytes']);

        $this->auditLog($service, $deployment, $user, 'extract', $relPath, $ip, [
            'destination' => $destRelNormalized,
            'bytes' => $inspection['bytes'],
            'count' => $inspection['count'],
            'delete_archive' => $deleteArchive,
        ]);

        $report(30, 'Extracting '.basename($relPath).' into '.$destRelNormalized);
        $result = $this->ssh->execWithStatus(
            ContainerArchiveCommands::extract($absPath, $kind, $destAbs, $this->maxExtractBytes()),
            1500,
        );
        $this->parseArchiveResult($result, $kind);

        $report(85, 'Fixing file ownership');
        $this->applyOwnership($deployment, $destAbs);

        if ($deleteArchive) {
            $report(95, 'Removing the archive');
            $this->ssh->deleteFile($absPath);
        }
        Cache::forget("storage_stats_{$deployment->id}");

        return [
            'bytes' => $inspection['bytes'],
            'count' => $inspection['count'],
            'destination' => $destRelNormalized,
        ];
    }

    /**
     * Pack the selection into a zip in the scratch directory.
     *
     * @param  list<string>  $relPaths
     * @param  (callable(int, string): void)|null  $progress
     * @return array{remote_path: string, bytes: int, name: string}
     */
    public function buildArchive(
        Service $service,
        ContainerDeployment $deployment,
        array $relPaths,
        User $user,
        string $ip,
        ?callable $progress = null,
    ): array {
        $report = static function (int $percent, string $label) use ($progress): void {
            if ($progress) {
                $progress($percent, $label);
            }
        };

        $base = rtrim($this->resolveBasePath($deployment), '/');
        $rels = [];
        $abs = [];
        foreach (array_values(array_unique($relPaths)) as $relPath) {
            $absPath = rtrim($this->resolveAndGuardPath($deployment, $relPath), '/');
            if ($absPath === $base) {
                throw new \InvalidArgumentException('Select files or folders inside the application, not the root.');
            }
            $rels[] = ltrim(substr($absPath, strlen($base)), '/');
            $abs[] = $absPath;
        }
        if ($rels === []) {
            throw new \InvalidArgumentException('Select at least one item.');
        }

        $report(5, 'Measuring the selection');
        $measure = $this->ssh->execWithStatus(ContainerArchiveCommands::measure($abs), 300);
        $bytes = (int) trim($measure['output']);
        $cap = $this->maxArchiveDownloadBytes();
        if ($bytes > $cap) {
            throw new \InvalidArgumentException(sprintf(
                'The selection is %s, above the %s limit for zip downloads. Download folders separately or use a backup.',
                $this->formatBytes($bytes),
                $this->formatBytes($cap),
            ));
        }
        $this->assertHasRoom($deployment, $bytes);

        $this->pruneTempDir($deployment);
        $usePython = $this->pythonAvailable();
        $name = (count($rels) === 1 ? basename($rels[0]) : 'selection').'-'.now()->format('Ymd-His');
        $name .= $usePython ? '.zip' : '.tar.gz';
        $outFile = $this->tempDir($deployment).'/'.Str::lower(Str::random(12)).'-'.$name;

        $this->auditLog($service, $deployment, $user, 'archive', $relPaths[0], $ip, [
            'paths' => $relPaths,
            'bytes' => $bytes,
            'name' => $name,
        ]);

        $report(25, sprintf('Packing %d item(s), %s', count($rels), $this->formatBytes($bytes)));
        $command = $usePython
            ? ContainerArchiveCommands::buildZip($base, $rels, $outFile)
            : ContainerArchiveCommands::buildTarGz($base, $rels, $outFile);
        $result = $this->ssh->execWithStatus($command, 1500);
        if ($result['status'] !== 0) {
            @$this->ssh->deleteFile($outFile);
            throw new \RuntimeException('Packing failed: '.($result['output'] !== '' ? $result['output'] : 'status '.$result['status']));
        }

        return [
            'remote_path' => $outFile,
            'bytes' => $this->fileSizeBytes($outFile),
            'name' => $name,
        ];
    }

    /**
     * Pull a built archive from the scratch directory to a local temp file and
     * remove the remote copy. Only paths inside the scratch directory are served.
     */
    public function fetchArchiveToLocal(ContainerDeployment $deployment, string $remotePath): string
    {
        $scratch = self::BASE.$deployment->container_name.'/'.$this->tempDirName().'/';
        if (! str_starts_with($remotePath, $scratch) || str_contains($remotePath, '..')) {
            throw new \InvalidArgumentException('Archive is not in the scratch directory.');
        }

        $local = storage_path('app/file-manager/'.$deployment->id.'-'.basename($remotePath));
        if (! is_dir(dirname($local))) {
            mkdir(dirname($local), 0755, true);
        }
        $this->ssh->downloadToLocal($remotePath, $local, null, 900);
        @$this->ssh->deleteFile($remotePath);

        return $local;
    }

    /**
     * uid:gid the container's app files should belong to, or null to leave as is.
     */
    public function containerOwner(ContainerDeployment $deployment): ?string
    {
        $slug = $deployment->service?->product?->containerTemplate?->slug;
        if (! is_string($slug) || $slug === '') {
            $slug = preg_match('/-(laravel|php|wordpress|nodejs|python|ruby|go)$/', (string) $deployment->container_name, $m) === 1
                ? $m[1]
                : null;
        }

        return ContainerDockerExecUserResolver::execUser($slug) === 'www-data' ? '33:33' : null;
    }

    private function applyOwnership(ContainerDeployment $deployment, string $absPath): void
    {
        $owner = $this->containerOwner($deployment);
        if ($owner === null) {
            return;
        }
        try {
            $this->ssh->exec(ContainerArchiveCommands::chownTree($absPath, $owner), 300);
        } catch (\Throwable $e) {
            \Log::warning('File manager could not fix ownership', [
                'deployment_id' => $deployment->id,
                'path' => $absPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{output: string, status: int}  $result
     * @return array{bytes: int, count: int}
     */
    private function parseArchiveResult(array $result, string $kind): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $result['output'])), fn ($l) => $l !== ''));
        $last = $lines === [] ? '' : end($lines);
        $coded = null;
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/^(OK|UNSAFE|SYMLINK|TOO_LARGE|BAD_ARCHIVE)\b/', $line) === 1) {
                $coded = $line;
                break;
            }
        }

        if ($result['status'] === 0 && $coded !== null && str_starts_with($coded, 'OK')) {
            $parts = preg_split('/\s+/', $coded);

            return ['bytes' => (int) ($parts[1] ?? 0), 'count' => (int) ($parts[2] ?? 0)];
        }

        $label = $kind === ContainerArchiveCommands::KIND_ZIP ? 'zip' : 'tar';
        $detail = $coded ?? $last;
        $message = match (true) {
            str_starts_with((string) $detail, 'UNSAFE') => 'The archive contains an entry that would write outside the destination ('.trim(substr($detail, 6)).'). Rebuild it without absolute or "../" paths.',
            str_starts_with((string) $detail, 'SYMLINK') => 'The archive contains a symbolic link ('.trim(substr($detail, 7)).'), which is not allowed.',
            str_starts_with((string) $detail, 'TOO_LARGE') => 'The archive would extract to more than the '.$this->formatBytes($this->maxExtractBytes()).' limit.',
            str_starts_with((string) $detail, 'BAD_ARCHIVE') => 'The file is not a valid '.$label.' archive ('.trim(substr($detail, 11)).').',
            default => 'Could not process the '.$label.' archive: '.($detail !== '' ? $detail : 'exit status '.$result['status']),
        };

        throw new \InvalidArgumentException($message);
    }

    private function assertToolsFor(string $kind): void
    {
        if ($kind === ContainerArchiveCommands::KIND_ZIP && ! $this->pythonAvailable()) {
            throw new \InvalidArgumentException('This node cannot extract zip files (python3 is missing). Upload a .tar.gz instead or ask support to install python3.');
        }
    }

    private function pythonAvailable(): bool
    {
        try {
            return trim($this->ssh->exec(ContainerArchiveCommands::pythonAvailable(), 10)) === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    private function isDirectory(string $absPath): bool
    {
        $result = trim($this->ssh->exec('[ -d '.escapeshellarg($absPath).' ] && echo yes || echo no', 10));

        return $result === 'yes';
    }

    private function safeName(string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid file name.');
        }

        return mb_substr($name, 0, 255);
    }

    private function tempDirName(): string
    {
        $name = trim((string) config('containers.file_manager.temp_dir', '.file-manager-tmp'), '/');

        return $name !== '' && ! str_contains($name, '/') ? $name : '.file-manager-tmp';
    }

    private function maxExtractBytes(): int
    {
        return max(1, (int) config('containers.file_manager.max_extract_mb', 2048)) * 1024 * 1024;
    }

    private function maxArchiveDownloadBytes(): int
    {
        return max(1, (int) config('containers.file_manager.max_archive_download_mb', 500)) * 1024 * 1024;
    }
}
