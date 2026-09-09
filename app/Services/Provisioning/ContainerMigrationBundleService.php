<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;
use Throwable;

class ContainerMigrationBundleService
{
    public const BASE_PATH = '/opt/talksasa/containers';

    public const MIGRATION_PATH = '/opt/talksasa/migrations';

    private const UTILITY_IMAGE = 'alpine:3.20';

    private const TIMEOUT = 1800;

    /**
     * @return array{source_bytes: int, source_free_bytes: int, target_free_bytes: int, target_available_memory_bytes: int, required_bytes: int, volumes: list<string>}
     */
    public function preflight(
        SSHService $source,
        SSHService $target,
        ContainerDeployment $deployment,
    ): array {
        $containerPath = self::BASE_PATH.'/'.$deployment->container_name;
        $path = escapeshellarg($containerPath);
        $source->exec('test -f '.$path.'/docker-compose.yml', 15);
        $source->exec('docker info >/dev/null && docker compose version >/dev/null', 30);
        $target->exec('docker info >/dev/null && docker compose version >/dev/null', 30);
        $this->assertTargetNginxReady($source, $target, $deployment);
        $source->exec(
            'docker image inspect '.escapeshellarg(self::UTILITY_IMAGE)
                .' >/dev/null 2>&1 || docker pull '.escapeshellarg(self::UTILITY_IMAGE).' >/dev/null',
            300,
        );
        $target->exec(
            'docker image inspect '.escapeshellarg(self::UTILITY_IMAGE)
                .' >/dev/null 2>&1 || docker pull '.escapeshellarg(self::UTILITY_IMAGE).' >/dev/null',
            300,
        );

        $volumes = $this->discoverProjectVolumes($source, $deployment->container_name);
        $targetCollision = trim($target->exec(
            'if [ -e '.escapeshellarg($containerPath)
                .' ] || docker ps -aq --filter name='.escapeshellarg('^/'.$deployment->container_name.'$')
                .' | grep -q .; then echo yes; else echo no; fi',
            20,
        ));
        if ($targetCollision === 'yes') {
            throw new \RuntimeException(
                'Target node already contains this service path or container name. Remove the stale target copy before migrating.'
            );
        }
        foreach ($volumes as $volume) {
            $exists = trim($target->exec(
                'if docker volume inspect '.escapeshellarg($volume)
                    .' >/dev/null 2>&1; then echo yes; else echo no; fi',
                15,
            ));
            if ($exists === 'yes') {
                throw new \RuntimeException(
                    "Target node already contains Docker volume {$volume}. Refusing to overwrite potentially unrelated data."
                );
            }
        }
        $sourceBytes = max(0, (int) trim($source->exec(
            'du -sb '.$path." 2>/dev/null | awk '{print \$1}'",
            60,
        )));
        if ($sourceBytes < 1) {
            throw new \RuntimeException('Source application directory is empty or unreadable.');
        }
        foreach ($volumes as $volume) {
            $output = trim($source->exec(
                'docker run --rm -v '.escapeshellarg($volume.':/volume:ro').' '
                    .escapeshellarg(self::UTILITY_IMAGE)
                    .' sh -c '.escapeshellarg("du -sb /volume 2>/dev/null | awk '{print \$1}'"),
                120,
            ));
            $sourceBytes += max(0, (int) $output);
        }
        $sourceFreeBytes = max(0, (int) trim($source->exec(
            'df -PB1 '.escapeshellarg('/opt/talksasa')." | awk 'NR==2 {print \$4}'",
            30,
        )));
        $sourceRequiredBytes = max(268435456, (int) ceil($sourceBytes * 1.25));
        if ($sourceFreeBytes < $sourceRequiredBytes) {
            throw new \RuntimeException(
                'Source node has insufficient migration staging space. Required '
                .$this->formatBytes($sourceRequiredBytes).', available '.$this->formatBytes($sourceFreeBytes).'.'
            );
        }

        $targetFreeBytes = max(0, (int) trim($target->exec(
            'df -PB1 '.escapeshellarg('/opt/talksasa')." | awk 'NR==2 {print \$4}'",
            30,
        )));
        $requiredBytes = max(536870912, (int) ceil($sourceBytes * 2.25));
        if ($targetFreeBytes < $requiredBytes) {
            throw new \RuntimeException(
                'Target node has insufficient free disk space. Required '
                .$this->formatBytes($requiredBytes).', available '.$this->formatBytes($targetFreeBytes).'.'
            );
        }
        $targetAvailableMemoryBytes = max(0, (int) trim($target->exec(
            "awk '/MemAvailable:/ {print \$2 * 1024}' /proc/meminfo",
            15,
        )));
        $requiredMemoryBytes = max(268435456, ((int) $deployment->memory_limit_mb + 256) * 1048576);
        if ($targetAvailableMemoryBytes < $requiredMemoryBytes) {
            throw new \RuntimeException(
                'Target node has insufficient available memory. Required '
                .$this->formatBytes($requiredMemoryBytes).', available '
                .$this->formatBytes($targetAvailableMemoryBytes).'.'
            );
        }

        if ((int) $deployment->assigned_port > 0) {
            $port = (int) $deployment->assigned_port;
            $inUse = trim($target->exec(
                "if ss -ltnH 'sport = :{$port}' 2>/dev/null | grep -q .; then echo yes; else echo no; fi",
                15,
            ));
            if ($inUse === 'yes') {
                throw new \RuntimeException("Target node port {$port} is already in use.");
            }
        }

        return [
            'source_bytes' => $sourceBytes,
            'source_free_bytes' => $sourceFreeBytes,
            'target_free_bytes' => $targetFreeBytes,
            'target_available_memory_bytes' => $targetAvailableMemoryBytes,
            'required_bytes' => $requiredBytes,
            'volumes' => $volumes,
        ];
    }

    /**
     * Validate host nginx before the source is stopped.
     *
     * A migration can restore and start the application successfully while
     * still being unable to publish it because the target host has a broken
     * unrelated vhost or lacks a certificate referenced by this deployment.
     */
    private function assertTargetNginxReady(
        SSHService $source,
        SSHService $target,
        ContainerDeployment $deployment,
    ): void
    {
        $domains = $deployment->relationLoaded('domains')
            ? $deployment->domains
            : collect();

        if ($domains->isEmpty()) {
            return;
        }

        try {
            $installed = trim($target->exec(
                'if command -v nginx >/dev/null 2>&1; then echo yes; else echo no; fi',
                15,
            ));
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'Target nginx preflight could not determine whether nginx is installed: '.$e->getMessage(),
                0,
                $e,
            );
        }

        if ($installed !== 'yes') {
            throw new \RuntimeException(
                'Target node has bound domains, but nginx is not installed. Install and configure nginx before migrating this service.'
            );
        }

        $this->syncMissingSslCertificates($source, $target, $domains);

        try {
            $target->exec('nginx -t 2>&1', 30);
        } catch (Throwable $directError) {
            try {
                $target->exec('sudo -n nginx -t 2>&1', 30);
            } catch (Throwable $sudoError) {
                throw new \RuntimeException(
                    'Target nginx configuration is invalid; migration stopped before source downtime. '
                    .'Direct error: '.$directError->getMessage().' | Sudo error: '.$sudoError->getMessage(),
                    0,
                    $sudoError,
                );
            }
        }

        foreach ($domains as $domain) {
            if (! $domain->ssl_enabled || $domain->status !== 'active') {
                continue;
            }

            $certificate = trim((string) $domain->ssl_certificate_path);
            $key = trim((string) $domain->ssl_key_path);
            if ($certificate === '' || $key === '') {
                throw new \RuntimeException(
                    "SSL is enabled for {$domain->domain}, but its certificate paths are incomplete. "
                    .'Repair the domain SSL configuration before migrating.'
                );
            }

            try {
                $exists = trim($target->exec(
                    '[ -f '.escapeshellarg($certificate).' ] && [ -f '.escapeshellarg($key).' ] && echo yes || echo no',
                    15,
                ));
            } catch (Throwable $e) {
                throw new \RuntimeException(
                    "Could not verify the SSL certificate for {$domain->domain} on the target node: ".$e->getMessage(),
                    0,
                    $e,
                );
            }

            if ($exists !== 'yes') {
                throw new \RuntimeException(
                    "Target node is missing the SSL certificate for {$domain->domain} "
                    ."({$certificate} and/or {$key}). Copy or issue the certificate before migrating; "
                    .'the source application was not stopped.'
                );
            }
        }
    }

    /**
     * Certificates live on the node, not in the container bundle. Copy a
     * missing Let's Encrypt lineage before nginx is tested on the target.
     */
    private function syncMissingSslCertificates(SSHService $source, SSHService $target, iterable $domains): void
    {
        foreach ($domains as $domain) {
            if (! $domain->ssl_enabled || $domain->status !== 'active') {
                continue;
            }

            $certificate = trim((string) $domain->ssl_certificate_path);
            $key = trim((string) $domain->ssl_key_path);
            if ($certificate === '' || $key === '') {
                continue;
            }

            $targetHasCertificate = trim($target->exec(
                '[ -f '.escapeshellarg($certificate).' ] && [ -f '.escapeshellarg($key).' ] && echo yes || echo no',
                15,
            )) === 'yes';
            if ($targetHasCertificate) {
                continue;
            }

            $hostname = (string) $domain->domain;
            if (
                ! str_starts_with($certificate, '/etc/letsencrypt/live/'.$hostname.'/')
                || ! str_starts_with($key, '/etc/letsencrypt/live/'.$hostname.'/')
                || preg_match('/^[a-z0-9.-]+$/i', $hostname) !== 1
            ) {
                throw new \RuntimeException(
                    "Cannot safely transfer the SSL certificate for {$hostname}; "
                    .'the configured certificate paths are not a standard Let\'s Encrypt lineage.'
                );
            }

            $archive = $source->exec(
                'sudo -n tar -C /etc/letsencrypt -czf - '
                    .escapeshellarg('live/'.$hostname).' '
                    .escapeshellarg('archive/'.$hostname).' '
                    .escapeshellarg('renewal/'.$hostname.'.conf')
                    .' | base64 -w0',
                60,
            );
            if (trim($archive) === '') {
                throw new \RuntimeException(
                    "Could not read the Let's Encrypt certificate for {$hostname} from the source node."
                );
            }

            $temporary = '/tmp/talksasa-cert-'.hash('sha256', $hostname).'.b64';
            try {
                $target->upload($archive, $temporary);
                $target->exec(
                    'base64 -d '.escapeshellarg($temporary)
                        .' | sudo -n tar -xzf - -C /etc/letsencrypt; rm -f '.escapeshellarg($temporary),
                    60,
                );
            } catch (Throwable $e) {
                throw new \RuntimeException(
                    "Could not transfer the SSL certificate for {$hostname} to the target node: ".$e->getMessage(),
                    0,
                    $e,
                );
            } finally {
                try {
                    $target->exec('rm -f '.escapeshellarg($temporary), 15);
                } catch (Throwable) {
                    // The migration must report the transfer failure, not mask it with cleanup.
                }
            }
        }
    }

    /**
     * @return array{archive: string, checksum: string, bytes: int, application: array{file: string, checksum: string, bytes: int}, volumes: list<array{name: string, file: string, checksum: string, bytes: int}>}
     */
    public function create(
        SSHService $source,
        ContainerDeployment $deployment,
        array $volumes,
        string $archive,
    ): array {
        $containerName = $deployment->container_name;
        $containerPath = self::BASE_PATH.'/'.$containerName;
        $stage = $archive.'.stage';
        $source->exec('rm -rf '.escapeshellarg($stage).' && mkdir -p '.escapeshellarg($stage.'/volumes'), 60);
        $source->exec(
            'cd '.escapeshellarg($containerPath).' && docker compose -f docker-compose.yml stop',
            180,
        );
        $source->exec(
            'tar -czf '.escapeshellarg($stage.'/application.tar.gz')
                .' -C '.escapeshellarg(self::BASE_PATH).' '.escapeshellarg($containerName),
            self::TIMEOUT,
        );

        $volumeManifest = [];
        foreach (array_values($volumes) as $index => $volume) {
            $file = sprintf('volume-%03d.tar.gz', $index + 1);
            $source->exec(
                'docker run --rm -v '.escapeshellarg($volume.':/volume:ro')
                    .' -v '.escapeshellarg($stage.'/volumes:/backup').' '
                    .escapeshellarg(self::UTILITY_IMAGE).' sh -c '
                    .escapeshellarg('tar -czf /backup/'.$file.' -C /volume .'),
                self::TIMEOUT,
            );
            $volumeManifest[] = [
                'name' => $volume,
                'file' => $file,
                'checksum' => $this->remoteChecksum($source, $stage.'/volumes/'.$file),
                'bytes' => $this->remoteSize($source, $stage.'/volumes/'.$file),
            ];
        }

        $manifest = [
            'schema' => 1,
            'service_id' => $deployment->service_id,
            'deployment_id' => $deployment->id,
            'container_name' => $containerName,
            'created_at' => now()->toIso8601String(),
            'application' => [
                'file' => 'application.tar.gz',
                'checksum' => $this->remoteChecksum($source, $stage.'/application.tar.gz'),
                'bytes' => $this->remoteSize($source, $stage.'/application.tar.gz'),
            ],
            'volumes' => $volumeManifest,
        ];
        $source->upload(
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n",
            $stage.'/manifest.json',
        );
        $source->exec(
            'tar -czf '.escapeshellarg($archive)
                .' -C '.escapeshellarg($stage).' manifest.json application.tar.gz volumes',
            self::TIMEOUT,
        );

        return [
            'archive' => $archive,
            'checksum' => $this->remoteChecksum($source, $archive),
            'bytes' => $this->remoteSize($source, $archive),
            'application' => $manifest['application'],
            'volumes' => $volumeManifest,
        ];
    }

    /**
     * @param  array{checksum: string, bytes: int}  $bundle
     */
    public function transfer(
        SSHService $source,
        SSHService $target,
        string $archive,
        string $localArchive,
        array $bundle,
    ): void {
        $directory = dirname($localArchive);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create the local migration staging directory.');
        }
        $localFreeBytes = disk_free_space($directory);
        $localRequiredBytes = (int) ceil((int) $bundle['bytes'] * 1.10);
        if ($localFreeBytes === false || $localFreeBytes < $localRequiredBytes) {
            throw new \RuntimeException('The platform does not have enough local staging space for this migration.');
        }

        $source->downloadToLocal($archive, $localArchive);
        if (! is_file($localArchive)
            || filesize($localArchive) !== (int) $bundle['bytes']
            || hash_file('sha256', $localArchive) !== $bundle['checksum']) {
            throw new \RuntimeException('Migration archive checksum or size changed while downloading from the source node.');
        }

        $target->exec('mkdir -p '.escapeshellarg(self::MIGRATION_PATH), 30);
        $target->uploadFromLocal($localArchive, $archive);
        if ($this->remoteSize($target, $archive) !== (int) $bundle['bytes']
            || ! hash_equals($bundle['checksum'], $this->remoteChecksum($target, $archive))) {
            throw new \RuntimeException('Migration archive checksum or size changed while uploading to the target node.');
        }
    }

    /**
     * @param  array{application: array{file: string, checksum: string, bytes: int}, volumes: list<array{name: string, file: string, checksum: string, bytes: int}>}  $bundle
     */
    public function restore(
        SSHService $target,
        ContainerDeployment $deployment,
        string $archive,
        array $bundle,
    ): void {
        $containerName = $deployment->container_name;
        $containerPath = self::BASE_PATH.'/'.$containerName;
        $stage = $archive.'.restore';
        $target->exec(
            'rm -rf '.escapeshellarg($stage).' && mkdir -p '.escapeshellarg($stage)
                .' && tar -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($stage),
            self::TIMEOUT,
        );
        if (! hash_equals($bundle['application']['checksum'], $this->remoteChecksum($target, $stage.'/application.tar.gz'))
            || $this->remoteSize($target, $stage.'/application.tar.gz') !== (int) $bundle['application']['bytes']) {
            throw new \RuntimeException('Application archive verification failed on the target node.');
        }

        $this->stopAndRemoveTarget($target, $deployment, true);
        $target->exec('mkdir -p '.escapeshellarg(self::BASE_PATH), 30);
        $target->exec(
            'tar -xzf '.escapeshellarg($stage.'/application.tar.gz').' -C '.escapeshellarg(self::BASE_PATH),
            self::TIMEOUT,
        );

        foreach ($bundle['volumes'] as $volume) {
            $name = $volume['name'];
            $file = $stage.'/volumes/'.$volume['file'];
            if (! hash_equals($volume['checksum'], $this->remoteChecksum($target, $file))
                || $this->remoteSize($target, $file) !== (int) $volume['bytes']) {
                throw new \RuntimeException("Volume archive verification failed for {$name}.");
            }
            $target->exec('docker volume create '.escapeshellarg($name).' >/dev/null', 60);
            $target->exec(
                'docker run --rm -v '.escapeshellarg($name.':/volume')
                    .' -v '.escapeshellarg(dirname($file).':/backup:ro').' '
                    .escapeshellarg(self::UTILITY_IMAGE).' sh -c '
                    .escapeshellarg('rm -rf /volume/* /volume/.[!.]* /volume/..?* 2>/dev/null || true; '
                        .'tar -xzf /backup/'.basename($file).' -C /volume'),
                self::TIMEOUT,
            );
        }

        $target->exec('test -f '.escapeshellarg($containerPath.'/docker-compose.yml'), 15);
    }

    public function stopAndRemoveTarget(
        SSHService $target,
        ContainerDeployment $deployment,
        bool $removeVolumes,
        array $volumeNames = [],
    ): void {
        $containerPath = self::BASE_PATH.'/'.$deployment->container_name;
        $flag = $removeVolumes ? ' -v' : '';
        $target->exec(
            'if [ -f '.escapeshellarg($containerPath.'/docker-compose.yml').' ]; then cd '
                .escapeshellarg($containerPath).' && docker compose -f docker-compose.yml down'
                .$flag.' --remove-orphans; fi; rm -rf '.escapeshellarg($containerPath),
            300,
        );
        if ($removeVolumes) {
            foreach ($volumeNames as $volume) {
                if (is_string($volume) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/', $volume)) {
                    $target->exec('docker volume rm -f '.escapeshellarg($volume).' >/dev/null 2>&1 || true', 60);
                }
            }
        }
    }

    public function cleanup(SSHService $ssh, string $archive): void
    {
        $ssh->exec(
            'rm -rf '.escapeshellarg($archive).' '.escapeshellarg($archive.'.stage').' '
                .escapeshellarg($archive.'.restore'),
            120,
        );
    }

    /**
     * @return list<string>
     */
    public function discoverProjectVolumes(SSHService $ssh, string $project): array
    {
        $output = trim($ssh->exec(
            'ids=$(docker ps -aq --filter label=com.docker.compose.project='.escapeshellarg($project).'); '
                .'if [ -n "$ids" ]; then docker inspect --format '
                .escapeshellarg('{{range .Mounts}}{{if eq .Type "volume"}}{{println .Name}}{{end}}{{end}}')
                .' $ids; fi; '
                .'docker volume ls --filter label=com.docker.compose.project='.escapeshellarg($project)
                .' --format '.escapeshellarg('{{.Name}}'),
            30,
        ));

        return $this->parseVolumeList($output);
    }

    /**
     * @return list<string>
     */
    public function parseVolumeList(string $output): array
    {
        $volumes = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $volume) {
            $volume = trim($volume);
            if ($volume !== '' && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/', $volume)) {
                $volumes[] = $volume;
            }
        }

        return array_values(array_unique($volumes));
    }

    private function remoteChecksum(SSHService $ssh, string $path): string
    {
        $checksum = strtolower(trim($ssh->exec(
            'sha256sum '.escapeshellarg($path)." | cut -d ' ' -f1",
            120,
        )));
        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            throw new \RuntimeException('Could not verify migration artifact checksum.');
        }

        return $checksum;
    }

    private function remoteSize(SSHService $ssh, string $path): int
    {
        $size = (int) trim($ssh->exec('stat -c %s '.escapeshellarg($path), 30));
        if ($size < 1) {
            throw new \RuntimeException('Migration artifact is empty.');
        }

        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        return number_format($bytes / 1073741824, 2).' GiB';
    }
}
