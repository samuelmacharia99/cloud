<?php

namespace App\Services\Provisioning;

use App\Jobs\SendTelegramMonitorAlertJob;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A security incident on a container: the offending files are zipped into an
 * incident folder beside the application directory, the originals are deleted,
 * and a manifest records what happened. The zip stays, so anything can be put
 * back, and nothing under the incident folder is reachable over the web or
 * from the customer's file manager.
 */
class ContainerIncidentService
{
    public const TRIGGER_DOCTOR = 'doctor';

    public const TRIGGER_CONVERT = 'convert';

    public const TRIGGER_NIGHTLY = 'nightly';

    public const KIND_MALWARE = 'malware';

    public const KIND_EXPOSED = 'exposed';

    public const KIND_CORE = 'core';

    public const KIND_INJECTION = 'injection';

    public const ARCHIVE_NAME = 'quarantine.zip';

    public const STORAGE_ZIP = 'zip';

    /** Files moved into <incident>/files/ with their paths kept; for archives too large to re-zip. */
    public const STORAGE_FILES = 'files';

    public const FILES_DIR = 'files';

    public function incidentsDir(ContainerDeployment $deployment): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/'
            .trim((string) config('containers.integrity.incidents_dir', 'incidents'), '/');
    }

    public function hostAppPath(ContainerDeployment $deployment): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
    }

    public function newIncidentId(): string
    {
        return now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
    }

    /**
     * Archive the given files, verify the archive, delete the originals, and
     * record the incident on the service.
     *
     * @param  list<array{path: string, reasons: list<string>, size?: int, mtime?: int}>  $files
     * @param  list<string>  $protectedPaths  paths that must never be deleted (core files are restored instead)
     * @return array{id: string, dir: string, archive: string, removed: list<string>, skipped: list<string>, bytes: int}
     */
    public function open(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        string $trigger,
        string $kind,
        array $files,
        array $protectedPaths = [],
        array $extra = [],
        bool $deleteOriginals = true,
        string $storage = self::STORAGE_ZIP,
    ): array {
        $id = $this->newIncidentId();
        $dir = $this->incidentsDir($deployment).'/'.$id;
        $archive = $dir.'/'.self::ARCHIVE_NAME;
        $hostAppPath = $this->hostAppPath($deployment);

        $paths = [];
        $skipped = [];
        $byPath = [];
        foreach ($files as $file) {
            $path = $this->cleanRelativePath((string) ($file['path'] ?? ''));
            if ($path === null) {
                continue;
            }
            $byPath[$path] = $file;
            if (in_array($path, $protectedPaths, true)) {
                $skipped[] = $path;

                continue;
            }
            $paths[] = $path;
        }
        $paths = array_values(array_unique($paths));

        $result = ['id' => $id, 'dir' => $dir, 'archive' => $archive, 'removed' => [], 'skipped' => $skipped, 'bytes' => 0];
        if ($paths === []) {
            return $result;
        }

        $ssh->exec('mkdir -p '.escapeshellarg($dir).' && chmod 700 '.escapeshellarg($this->incidentsDir($deployment)).' '.escapeshellarg($dir), 20);
        $hashes = $this->hashes($ssh, $hostAppPath, $paths);

        if ($storage === self::STORAGE_FILES) {
            $bytes = (int) trim((string) $ssh->exec('cd '.escapeshellarg($hostAppPath).' && du -cb '.implode(' ', array_map('escapeshellarg', $paths)).' 2>/dev/null | tail -n 1 | cut -f1', 120));
            $removed = $this->moveOriginals($ssh, $hostAppPath, $dir.'/'.self::FILES_DIR, $paths);
            if ($removed === [] && $paths !== []) {
                $ssh->exec('rm -rf '.escapeshellarg($dir), 20);
                throw new \RuntimeException('None of the files could be moved into the incident folder; nothing was changed.');
            }
        } else {
            $build = $ssh->execWithStatus(ContainerArchiveCommands::buildZip($hostAppPath, $paths, $archive), 900);
            $expected = $this->countRegularFiles($ssh, $hostAppPath, $paths);
            if ($build['status'] !== 0 || preg_match('/^OK (\d+)$/m', $build['output'], $m) !== 1 || (int) $m[1] < $expected) {
                $ssh->exec('rm -rf '.escapeshellarg($dir), 20);
                throw new \RuntimeException('The incident archive could not be built ('.trim(mb_substr($build['output'], 0, 200)).'); nothing was deleted.');
            }
            $bytes = (int) trim((string) $ssh->exec('stat -c %s '.escapeshellarg($archive).' 2>/dev/null || echo 0', 15));
            $removed = $deleteOriginals ? $this->deleteOriginals($ssh, $hostAppPath, $paths) : [];
        }

        $manifest = [
            'id' => $id,
            'service_id' => (int) $service->id,
            'container' => $deployment->container_name,
            'host' => $deployment->probeHostHeader(),
            'trigger' => $trigger,
            'kind' => $kind,
            'operator_user_id' => auth()->id(),
            'opened_at' => now()->toIso8601String(),
            'app_path' => $hostAppPath,
            'storage' => $storage,
            'archive' => $storage === self::STORAGE_ZIP ? self::ARCHIVE_NAME : self::FILES_DIR.'/',
            'archive_bytes' => $bytes,
            'files' => array_map(fn ($p) => [
                'path' => $p,
                'reasons' => array_values((array) ($byPath[$p]['reasons'] ?? [])),
                'size' => (int) ($byPath[$p]['size'] ?? 0),
                'mtime' => (int) ($byPath[$p]['mtime'] ?? 0),
                'sha256' => $hashes[$p] ?? null,
                'removed' => in_array($p, $removed, true),
            ], $paths),
            'skipped_protected' => $skipped,
        ] + $extra;

        try {
            $ssh->upload((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $dir.'/manifest.json');
            $ssh->upload($this->report($manifest), $dir.'/report.txt');
            $ssh->exec('find '.escapeshellarg($dir).' -maxdepth 1 -type f -exec chmod 600 {} + 2>/dev/null; true', 15);
        } catch (\Throwable $e) {
            Log::warning('Incident manifest not written', ['incident' => $id, 'error' => $e->getMessage()]);
        }

        $this->record($service, [
            'id' => $id,
            'dir' => $dir,
            'trigger' => $trigger,
            'kind' => $kind,
            'opened_at' => $manifest['opened_at'],
            'files' => $deleteOriginals ? count($removed) : count($paths),
            'archived_only' => ! $deleteOriginals,
            'storage' => $storage,
            'bytes' => $bytes,
            'reasons' => $this->reasonSummary(array_map(fn ($p) => $byPath[$p], $paths)),
            'restored_at' => null,
            'paths' => array_slice($removed, 0, 20),
        ]);

        $result['removed'] = $removed;
        $result['bytes'] = $bytes;

        return $result;
    }

    /**
     * Put an incident's files back exactly where they were.
     *
     * @return array{restored: int, message: string}
     */
    public function restore(SSHService $ssh, Service $service, ContainerDeployment $deployment, string $incidentId): array
    {
        if (preg_match('/^\d{8}-\d{6}-[a-z0-9]{6}$/', $incidentId) !== 1) {
            throw new \InvalidArgumentException('That incident id is not valid.');
        }
        $dir = $this->incidentsDir($deployment).'/'.$incidentId;
        $archive = $dir.'/'.self::ARCHIVE_NAME;
        $filesDir = $dir.'/'.self::FILES_DIR;
        $cap = max(1, (int) config('containers.file_manager.max_extract_mb', 2048)) * 1024 * 1024;

        $hasFiles = trim((string) $ssh->exec('[ -d '.escapeshellarg($filesDir).' ] && echo yes || echo no', 15)) === 'yes';
        if ($hasFiles) {
            $result = $ssh->execWithStatus(
                'n=$(find '.escapeshellarg($filesDir).' -type f | wc -l); cp -a '.escapeshellarg($filesDir.'/.').' '.escapeshellarg($this->hostAppPath($deployment).'/')
                .' && rm -rf '.escapeshellarg($filesDir).' && echo "OK 0 $n"',
                900
            );
        } else {
            $result = $ssh->execWithStatus(ContainerArchiveCommands::extract($archive, ContainerArchiveCommands::KIND_ZIP, $this->hostAppPath($deployment), $cap), 900);
        }
        if (preg_match('/^OK (\d+) (\d+)$/m', $result['output'], $m) !== 1) {
            throw new \RuntimeException('Restore failed: '.trim(mb_substr($result['output'], 0, 200)));
        }
        $count = (int) $m[2];
        $ssh->exec(ContainerArchiveCommands::chownTree($this->hostAppPath($deployment), '33:33'), 120);

        $this->updateRecord($service, $incidentId, ['restored_at' => now()->toIso8601String()]);

        return ['restored' => $count, 'message' => 'Restored '.$count.' file(s) from incident '.$incidentId.' into the site.'];
    }

    /**
     * Copy an incident's archive into the file-manager scratch directory so the
     * existing download path can serve it. Returns the remote path.
     */
    public function stageForDownload(SSHService $ssh, ContainerDeployment $deployment, string $incidentId): string
    {
        if (preg_match('/^\d{8}-\d{6}-[a-z0-9]{6}$/', $incidentId) !== 1) {
            throw new \InvalidArgumentException('That incident id is not valid.');
        }
        $dir = $this->incidentsDir($deployment).'/'.$incidentId;
        $archive = $dir.'/'.self::ARCHIVE_NAME;
        $filesDir = $dir.'/'.self::FILES_DIR;
        $scratch = $this->scratchDir($deployment);
        $target = $scratch.'/incident-'.$incidentId.'.zip';

        $hasFiles = trim((string) $ssh->exec('[ -d '.escapeshellarg($filesDir).' ] && echo yes || echo no', 15)) === 'yes';
        if ($hasFiles) {
            $cap = max(1, (int) config('containers.file_manager.max_archive_download_mb', 500)) * 1024 * 1024;
            $bytes = (int) trim((string) $ssh->exec('du -sb '.escapeshellarg($filesDir).' 2>/dev/null | cut -f1', 120));
            if ($bytes > $cap) {
                throw new \InvalidArgumentException('This incident holds '.DirectAdminMailPullProgress::formatBytes($bytes).', more than the download limit; the files are kept on the node under '.$filesDir.'.');
            }
            $list = array_values(array_filter(array_map('trim', explode("\n", (string) $ssh->exec('cd '.escapeshellarg($filesDir).' && find . -mindepth 1 -maxdepth 1 -printf "%P\n"', 60)))));
            $ssh->exec('mkdir -p '.escapeshellarg($scratch).' && chmod 700 '.escapeshellarg($scratch), 15);
            $built = $ssh->execWithStatus(ContainerArchiveCommands::buildZip($filesDir, $list, $target), 900);
            if (! str_contains($built['output'], 'OK')) {
                throw new \RuntimeException('The incident files could not be zipped for download.');
            }

            return $target;
        }

        $out = $ssh->execWithStatus(
            'mkdir -p '.escapeshellarg($scratch).' && chmod 700 '.escapeshellarg($scratch)
            .' && [ -s '.escapeshellarg($archive).' ] && cp -f '.escapeshellarg($archive).' '.escapeshellarg($target).' && echo staged',
            60
        );
        if (! str_contains($out['output'], 'staged')) {
            throw new \RuntimeException('The incident archive is no longer on the node.');
        }

        return $target;
    }

    /**
     * The file-manager scratch directory the download route is allowed to read from.
     */
    public function scratchDir(ContainerDeployment $deployment): string
    {
        $scratchName = trim((string) config('containers.file_manager.temp_dir', '.file-manager-tmp'), '/');

        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/'.$scratchName;
    }

    /**
     * Remove incident folders older than the retention window.
     */
    public function prune(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $days = max(7, (int) config('containers.integrity.incident_retention_days', 90));
        $ssh->exec(ContainerArchiveCommands::pruneOlderThan($this->incidentsDir($deployment), $days * 1440), 60);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function incidentsFor(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $list = (array) ($meta['security_incidents'] ?? []);

        return array_values(array_filter($list, 'is_array'));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    public function cleanRelativePath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = ltrim($path, '/');
        if ($path === '' || $path === '.' || str_starts_with($path, './')) {
            $path = preg_replace('#^\./#', '', $path) ?? '';
        }
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '') {
                return null;
            }
        }

        return $path;
    }

    /**
     * @param  list<string>  $paths
     */
    private function countRegularFiles(SSHService $ssh, string $hostAppPath, array $paths): int
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $args = implode(' ', array_map('escapeshellarg', $paths));
        $out = (string) $ssh->exec('cd '.$root.' && n=0; for p in '.$args.'; do if [ -d "$p" ] && [ ! -L "$p" ]; then n=$((n + $(find "$p" -type f | wc -l))); elif [ -f "$p" ] && [ ! -L "$p" ]; then n=$((n+1)); fi; done; echo "$n"', 120);

        return (int) trim($out);
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function hashes(SSHService $ssh, string $hostAppPath, array $paths): array
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $args = implode(' ', array_map('escapeshellarg', $paths));
        $out = (string) $ssh->exec('cd '.$root.' && for p in '.$args.'; do if [ -f "$p" ] && [ ! -L "$p" ]; then sha256sum "$p"; fi; done 2>/dev/null; true', 300);
        $hashes = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/', trim($line), $m) === 1) {
                $hashes[trim($m[2])] = $m[1];
            }
        }

        return $hashes;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function deleteOriginals(SSHService $ssh, string $hostAppPath, array $paths): array
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $args = implode(' ', array_map('escapeshellarg', $paths));
        $out = (string) $ssh->exec(
            'cd '.$root.' && for p in '.$args.'; do case "$p" in /*|..*|*/..*|*/../*) continue;; esac; '
            .'if [ -L "$p" ]; then rm -f "$p" && echo "removed $p"; '
            .'elif [ -d "$p" ]; then rm -rf "$p" && echo "removed $p"; '
            .'elif [ -e "$p" ]; then rm -f "$p" && echo "removed $p"; fi; done; true',
            300
        );
        $removed = [];
        foreach (explode("\n", $out) as $line) {
            if (str_starts_with($line, 'removed ')) {
                $removed[] = substr($line, 8);
            }
        }

        return $removed;
    }

    /**
     * Move each path into the incident's files/ directory, keeping its relative path.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function moveOriginals(SSHService $ssh, string $hostAppPath, string $filesDir, array $paths): array
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $args = implode(' ', array_map('escapeshellarg', $paths));
        $out = (string) $ssh->exec(
            'cd '.$root.' && dest='.escapeshellarg($filesDir).'; mkdir -p "$dest" && chmod 700 "$dest"; for p in '.$args.'; do case "$p" in /*|..*|*/..*|*/../*) continue;; esac; '
            .'if [ -e "$p" ] || [ -L "$p" ]; then mkdir -p "$dest/$(dirname "$p")" && mv "$p" "$dest/$p" && echo "removed $p"; fi; done; true',
            600
        );
        $removed = [];
        foreach (explode("\n", $out) as $line) {
            if (str_starts_with($line, 'removed ')) {
                $removed[] = substr($line, 8);
            }
        }

        return $removed;
    }

    /**
     * Whether the incident can be handed to the browser as one zip.
     *
     * @param  array<string, mixed>  $row
     */
    public function downloadable(array $row): bool
    {
        if (($row['storage'] ?? self::STORAGE_ZIP) === self::STORAGE_ZIP) {
            return true;
        }
        $cap = max(1, (int) config('containers.file_manager.max_archive_download_mb', 500)) * 1024 * 1024;

        return (int) ($row['bytes'] ?? 0) <= $cap && empty($row['restored_at']);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function report(array $manifest): string
    {
        $lines = [
            'Talksasa security incident '.$manifest['id'],
            'Service #'.$manifest['service_id'].' · container '.$manifest['container'].' · host '.($manifest['host'] ?: 'n/a'),
            'Opened '.$manifest['opened_at'].' by '.$manifest['trigger'].' ('.$manifest['kind'].')',
            '',
            ($manifest['storage'] ?? self::STORAGE_ZIP) === self::STORAGE_FILES
                ? 'The files below were moved into '.self::FILES_DIR.'/ with their paths preserved, out of '.$manifest['app_path'].'.'
                : 'The files below were zipped into '.self::ARCHIVE_NAME.' with their paths preserved, then removed from '.$manifest['app_path'].'.',
            'To put a file back, copy it to the same relative path under the application directory and chown it to www-data (33:33).',
            '',
        ];
        foreach ($manifest['files'] as $file) {
            $lines[] = sprintf('%s  [%s]  %s  sha256=%s', $file['path'], implode(', ', $file['reasons']), $file['removed'] ? 'removed' : 'NOT removed', $file['sha256'] ?? 'n/a');
        }
        if ($manifest['skipped_protected'] !== []) {
            $lines[] = '';
            $lines[] = 'Skipped (WordPress core files are restored from the official release, never deleted): '.implode(', ', $manifest['skipped_protected']);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array{path: string, reasons: list<string>}>  $files
     * @return array<string, int>
     */
    private function reasonSummary(array $files): array
    {
        $summary = [];
        foreach ($files as $file) {
            foreach ((array) ($file['reasons'] ?? []) as $reason) {
                $summary[$reason] = ($summary[$reason] ?? 0) + 1;
            }
        }
        arsort($summary);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function record(Service $service, array $row): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $list = array_values(array_filter((array) ($meta['security_incidents'] ?? []), 'is_array'));
        array_unshift($list, $row);
        $meta['security_incidents'] = array_slice($list, 0, 50);
        $service->forceFill(['service_meta' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateRecord(Service $service, string $incidentId, array $changes): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $list = array_values(array_filter((array) ($meta['security_incidents'] ?? []), 'is_array'));
        foreach ($list as $i => $row) {
            if (($row['id'] ?? null) === $incidentId) {
                $list[$i] = array_merge($row, $changes);
            }
        }
        $meta['security_incidents'] = $list;
        $service->forceFill(['service_meta' => $meta])->save();
    }

    /**
     * Tell the operators. Never throws.
     *
     * @param  list<string>  $paths
     */
    public function alert(Service $service, ContainerDeployment $deployment, string $incidentId, string $trigger, array $paths, string $extra = ''): void
    {
        if (! (bool) config('containers.integrity.alert', true)) {
            return;
        }
        try {
            SendTelegramMonitorAlertJob::dispatch(
                'security',
                'Malware removed from '.($deployment->probeHostHeader() ?: $deployment->container_name),
                [
                    'service' => '#'.$service->id.' '.$service->name,
                    'incident' => $incidentId,
                    'trigger' => $trigger,
                    'files' => count($paths),
                    'first' => implode(', ', array_slice($paths, 0, 5)),
                ],
                trim('Archive kept under the incident folder on the node; restore from Container Doctor if needed. '.$extra),
            );
        } catch (\Throwable $e) {
            Log::warning('Incident alert not dispatched', ['incident' => $incidentId, 'error' => $e->getMessage()]);
        }
    }
}
