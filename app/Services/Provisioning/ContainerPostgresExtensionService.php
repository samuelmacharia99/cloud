<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Symfony\Component\Yaml\Yaml;

/**
 * Postgres extensions a dump needs, and the one image swap that supplies them.
 *
 * The stock postgres image carries no PostGIS, so a dump from a machine that
 * had it aborts at `CREATE EXTENSION postgis` with psql stopping on first
 * error. Nothing about that is recoverable by editing the dump: the schema
 * genuinely needs the extension. What it needs is a database image that ships
 * it, which is the same Postgres at the same major version with the extension
 * files present.
 *
 * The compose patch lives here rather than in ContainerDeploymentService, which
 * is already one of the largest files in the repository.
 */
class ContainerPostgresExtensionService
{
    /** Always present in a stock image, so never worth reporting as missing. */
    private const ALWAYS_AVAILABLE = ['plpgsql'];

    /**
     * Extension names a dump asks for.
     *
     * @return list<string>
     */
    public function requiredExtensions(string $sql): array
    {
        preg_match_all(
            '/\bCREATE\s+EXTENSION\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|([A-Za-z0-9_]+))/i',
            $sql,
            $matches,
            PREG_SET_ORDER
        );

        $names = [];
        foreach ($matches as $match) {
            $name = strtolower(trim($match[1] !== '' ? $match[1] : ($match[2] ?? '')));
            if ($name !== '' && ! in_array($name, self::ALWAYS_AVAILABLE, true)) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Which of those the sidecar could not install.
     *
     * A probe that cannot answer returns nothing missing: refusing an import
     * over an unreadable catalogue would be worse than letting psql speak.
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    public function missingExtensions(SSHService $ssh, ContainerDeployment $deployment, array $required): array
    {
        if ($required === []) {
            return [];
        }

        $available = $this->availableExtensions($ssh, $deployment);
        if ($available === []) {
            return [];
        }

        return array_values(array_filter(
            $required,
            fn (string $name): bool => ! in_array($name, $available, true),
        ));
    }

    /**
     * @return list<string>
     */
    public function availableExtensions(SSHService $ssh, ContainerDeployment $deployment): array
    {
        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $user = escapeshellarg((string) ($env['DB_USERNAME'] ?? $env['POSTGRES_USER'] ?? 'appuser'));
        $database = escapeshellarg((string) ($env['DB_DATABASE'] ?? $env['POSTGRES_DB'] ?? 'appdb'));
        $password = escapeshellarg((string) ($env['DB_PASSWORD'] ?? $env['POSTGRES_PASSWORD'] ?? ''));
        $containerPath = escapeshellarg(ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name);

        try {
            $output = $ssh->exec(
                'cd '.$containerPath.' && docker compose exec -T -e PGPASSWORD='.$password
                .' db psql -U '.$user.' -d '.$database.' -tAc '
                .escapeshellarg('SELECT name FROM pg_available_extensions'),
                30
            );
        } catch (\Throwable) {
            return [];
        }

        $names = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && preg_match('/^[a-z0-9_-]+$/', $line) === 1) {
                $names[] = $line;
            }
        }

        return $names;
    }

    /**
     * The PostGIS build of an image, at the same major version.
     *
     * The C library has to match too. A data directory initialised by an Alpine
     * image carries musl's collation, and reading it back under glibc can order
     * a text index differently, so an Alpine image is only ever swapped for an
     * Alpine one.
     */
    public function postgisImageFor(string $currentImage): ?string
    {
        if (str_contains(strtolower($currentImage), 'postgis')) {
            return null;
        }

        if (preg_match('#^(?:docker\.io/)?(?:library/)?postgres:(\d+)(?:\.\d+)*(-alpine.*)?$#i', trim($currentImage), $match) !== 1) {
            return null;
        }

        $template = (string) config(
            'containers.postgres_extensions.postgis_image',
            'postgis/postgis:{major}-3.4-alpine'
        );

        $image = str_replace('{major}', $match[1], $template);

        // Only offer a swap that keeps the same C library as what is on disk.
        $currentIsAlpine = isset($match[2]) && $match[2] !== '';

        return $currentIsAlpine === str_contains(strtolower($image), 'alpine') ? $image : null;
    }

    /**
     * Swap the sidecar onto a PostGIS image and create the extension.
     *
     * The image is pulled before anything is changed, so a tag that does not
     * exist fails while the stack is still untouched. If the new container does
     * not come up, the previous compose file is restored and started again.
     */
    public function enablePostgis(Service $service, ContainerDeployment $deployment, SSHService $ssh): string
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $yaml = $this->readCompose($ssh, $containerPath, $deployment);
        $current = $this->databaseImage($yaml);
        if ($current === null) {
            throw new \RuntimeException('This service has no database container in its compose file.');
        }

        $target = $this->postgisImageFor($current);
        if ($target === null) {
            return str_contains(strtolower($current), 'postgis')
                ? $this->createExtension($ssh, $containerPath, $deployment)
                : throw new \RuntimeException(
                    'No PostGIS build is configured for '.$current.'. An operator can set one in the container config.'
                );
        }

        $ssh->exec('docker pull '.escapeshellarg($target), 600);

        $patched = $this->patchComposeDatabaseImage($yaml, $target);
        if ($patched === $yaml) {
            throw new \RuntimeException('Could not set the database image in docker-compose.yml.');
        }

        $ssh->upload($patched, $containerPath.'/docker-compose.yml');

        try {
            $ssh->exec('cd '.escapeshellarg($containerPath).' && docker compose up -d db', 300);
            $this->waitForPostgres($ssh, $containerPath, $deployment);
        } catch (\Throwable $e) {
            $ssh->upload($yaml, $containerPath.'/docker-compose.yml');
            @$ssh->exec('cd '.escapeshellarg($containerPath).' && docker compose up -d db', 300);

            throw new \RuntimeException(
                'The database did not come back on '.$target.', so the previous image was restored. '
                .$e->getMessage(),
                0,
                $e
            );
        }

        $deployment->update(['docker_compose_content' => $patched]);

        // Remembered on the service, or the next redeploy renders the stock
        // image again and the extension disappears from a database that needs it.
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['database_image'] = $target;
        $service->update(['service_meta' => $meta]);

        return $this->createExtension($ssh, $containerPath, $deployment)
            .' The database now runs '.$target.'.';
    }

    public function patchComposeDatabaseImage(string $yaml, string $image): string
    {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services']['db'] ?? null)) {
            return $yaml;
        }

        $compose['services']['db']['image'] = $image;

        return Yaml::dump($compose, 10, 2);
    }

    public function databaseImage(string $yaml): ?string
    {
        $compose = Yaml::parse($yaml);
        $image = data_get($compose, 'services.db.image');

        return is_string($image) && trim($image) !== '' ? trim($image) : null;
    }

    /**
     * Postgres reopens its data directory on a new image, which takes a moment
     * and can fail outright. Proven by polling, never by sleeping.
     */
    private function waitForPostgres(SSHService $ssh, string $containerPath, ContainerDeployment $deployment): void
    {
        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $command = 'cd '.escapeshellarg($containerPath).' && '
            .app(ContainerDeploymentService::class)->postgresqlSidecarReadinessCommand($env);

        $lastError = null;
        for ($attempt = 0; $attempt < 24; $attempt++) {
            try {
                $ssh->exec($command, 20);

                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                usleep(5_000_000);
            }
        }

        throw new \RuntimeException(
            'Postgres did not accept connections after the image change. '.($lastError?->getMessage() ?? ''),
            0,
            $lastError
        );
    }

    private function createExtension(SSHService $ssh, string $containerPath, ContainerDeployment $deployment): string
    {
        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $database = escapeshellarg((string) ($env['DB_DATABASE'] ?? $env['POSTGRES_DB'] ?? 'appdb'));
        $password = escapeshellarg((string) ($env['DB_PASSWORD'] ?? $env['POSTGRES_PASSWORD'] ?? ''));

        // The application role first, and `postgres` only as a fallback.
        //
        // This ran as `postgres` first, on the assumption that the app role is
        // not a superuser. On a volume created with a custom POSTGRES_USER
        // there is no `postgres` role at all: the app user owns the cluster. So
        // the first attempt always failed, Postgres logged
        // `FATAL: role "postgres" does not exist`, and the fallback succeeded.
        // Doctor then read six hours of logs, found the line the platform had
        // just written, and raised it to the customer as a critical fault.
        $roles = app(ContainerDeploymentService::class)->postgresqlAdminRoleCandidates(
            $env,
            (string) ($env['DB_USERNAME'] ?? $env['POSTGRES_USER'] ?? 'appuser'),
        );

        $attempts = array_map(
            fn (string $role): string => 'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T -e PGPASSWORD='.$password
                .' db psql -U '.escapeshellarg($role).' -d '.$database.' -c '
                .escapeshellarg('CREATE EXTENSION IF NOT EXISTS postgis'),
            $roles,
        );

        $ssh->exec(implode(' || ', $attempts), 120);

        return 'PostGIS is available in this database.';
    }

    private function readCompose(SSHService $ssh, string $containerPath, ContainerDeployment $deployment): string
    {
        try {
            $yaml = trim((string) $ssh->exec('cat '.escapeshellarg($containerPath.'/docker-compose.yml'), 20));
        } catch (\Throwable) {
            $yaml = '';
        }

        return $yaml !== '' ? $yaml : (string) ($deployment->docker_compose_content ?? '');
    }
}
