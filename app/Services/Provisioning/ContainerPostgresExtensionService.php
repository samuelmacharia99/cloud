<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Symfony\Component\Yaml\Yaml;

/**
 * Postgres extensions an application needs, and how the sidecar gets them.
 *
 * Two different walls look the same from a migration's point of view. Some
 * extensions ship with every Postgres image (`uuid-ossp`, `pg_trgm`, `hstore`)
 * but only a superuser may create them, and the application role is not one.
 * Others (`postgis`, `vector`) are not in the stock image at all and need a
 * build of the same Postgres major that carries them. The first case is one
 * statement as the cluster owner. The second swaps the image, which restarts
 * the database, and when the swap crosses from musl to glibc it also rebuilds
 * every index, because the two C libraries do not order text the same way.
 *
 * The compose patch lives here rather than in ContainerDeploymentService, which
 * is already one of the largest files in the repository.
 */
class ContainerPostgresExtensionService
{
    /** Always present in a stock image, so never worth reporting as missing. */
    private const ALWAYS_AVAILABLE = ['plpgsql'];

    /**
     * Extensions the stock image lacks, the config key naming an image that
     * ships them, and the word that identifies such an image by name.
     */
    private const IMAGES = [
        'postgis' => ['key' => 'postgis_image', 'default' => 'postgis/postgis:{major}-3.4-alpine', 'marker' => 'postgis', 'label' => 'PostGIS'],
        'vector' => ['key' => 'vector_image', 'default' => 'pgvector/pgvector:pg{major}', 'marker' => 'pgvector', 'label' => 'pgvector'],
    ];

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
     * The extension a failed migration or import was missing, read from what
     * the tool printed. Null when the failure was about something else.
     */
    public function extensionRequiredByError(string $output): ?string
    {
        $patterns = [
            '/pgvector extension is required/i' => 'vector',
            '/type "vector" does not exist/i' => 'vector',
            '/extension "([a-z0-9_-]+)" is not available/i' => null,
            '/could not open extension control file "[^"]*\/([a-z0-9_-]+)\.control"/i' => null,
            '/permission denied to create extension "([a-z0-9_-]+)"/i' => null,
            '/function\s+(?:st_|geometry)[a-z0-9_]*\(.*does not exist/i' => 'postgis',
        ];

        foreach ($patterns as $pattern => $fixed) {
            if (preg_match($pattern, $output, $match) === 1) {
                $name = strtolower($fixed ?? ($match[1] ?? ''));

                return $name !== '' && ! in_array($name, self::ALWAYS_AVAILABLE, true) ? $name : null;
            }
        }

        return null;
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
     * The build of an image that ships an extension, at the same major version.
     *
     * The C library matters. A data directory initialised by an Alpine image
     * carries musl's collation, and reading it back under glibc orders text
     * differently, so by default an Alpine image is only swapped for an Alpine
     * one. A caller that will rebuild the indexes afterwards may allow the
     * crossing, which is how pgvector, published on Debian only, reaches an
     * Alpine sidecar.
     */
    public function imageFor(string $currentImage, string $extension, bool $allowLibcChange = false): ?string
    {
        $spec = self::IMAGES[strtolower($extension)] ?? null;
        if ($spec === null || str_contains(strtolower($currentImage), $spec['marker'])) {
            return null;
        }

        if (preg_match('#^(?:docker\.io/)?(?:library/)?postgres:(\d+)(?:\.\d+)*(-alpine.*)?$#i', trim($currentImage), $match) !== 1) {
            return null;
        }

        $template = (string) config('containers.postgres_extensions.'.$spec['key'], $spec['default']);
        $image = str_replace('{major}', $match[1], $template);

        if ($allowLibcChange || ! $this->swapChangesLibc($currentImage, $image)) {
            return $image;
        }

        return null;
    }

    public function postgisImageFor(string $currentImage): ?string
    {
        return $this->imageFor($currentImage, 'postgis');
    }

    public function swapChangesLibc(string $from, string $to): bool
    {
        return str_contains(strtolower($from), 'alpine') !== str_contains(strtolower($to), 'alpine');
    }

    public function enablePostgis(Service $service, ContainerDeployment $deployment, SSHService $ssh): string
    {
        return $this->enableExtension($service, $deployment, $ssh, 'postgis');
    }

    /**
     * Make an extension available in this database, by whatever it takes.
     *
     * The image is pulled before anything is changed, so a tag that does not
     * exist fails while the stack is still untouched. If the new container does
     * not come up, the previous compose file is restored and started again.
     */
    public function enableExtension(Service $service, ContainerDeployment $deployment, SSHService $ssh, string $extension): string
    {
        $extension = strtolower(trim($extension));
        if ($extension === '' || preg_match('/^[a-z0-9_-]+$/', $extension) !== 1) {
            throw new \InvalidArgumentException('That is not a Postgres extension name.');
        }

        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $label = $this->label($extension);

        if ($this->extensionInstalled($ssh, $containerPath, $env, $extension)) {
            return $label.' is already available in this database.';
        }

        // Shipped with the image, only ever missing a superuser to create it.
        if (in_array($extension, $this->availableExtensions($ssh, $deployment), true)) {
            return $this->createExtension($ssh, $containerPath, $deployment, $extension);
        }

        $yaml = $this->readCompose($ssh, $containerPath, $deployment);
        $current = $this->databaseImage($yaml);
        if ($current === null) {
            throw new \RuntimeException('This service has no database container in its compose file.');
        }

        $target = $this->imageFor($current, $extension, allowLibcChange: true);
        if ($target === null) {
            throw new \RuntimeException(
                "No database image with {$label} is configured for {$current}. "
                .'An operator can set one in the container config (postgres_extensions).'
            );
        }

        $reindex = $this->swapChangesLibc($current, $target);

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

        $note = ' The database now runs '.$target.'.';
        if ($reindex) {
            $this->rebuildIndexesForNewLibc($ssh, $containerPath, $env);
            $note .= ' Its indexes were rebuilt for the new C library.';
        }

        return $this->createExtension($ssh, $containerPath, $deployment, $extension).$note;
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

    private function label(string $extension): string
    {
        return self::IMAGES[$extension]['label'] ?? $extension;
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

    /**
     * A musl data directory now served by glibc, or the reverse: every text
     * index was built in an order the new library does not agree with, and
     * Postgres 15+ will say so on the first query that notices. Rebuilding
     * them is the documented remedy; refreshing the recorded collation
     * version afterwards stops the warning (older servers have no such
     * statement, so that one is allowed to fail).
     *
     * @param  array<string, mixed>  $env
     */
    private function rebuildIndexesForNewLibc(SSHService $ssh, string $containerPath, array $env): void
    {
        $database = (string) ($env['DB_DATABASE'] ?? $env['POSTGRES_DB'] ?? 'appdb');
        $quoted = '"'.str_replace('"', '""', $database).'"';

        $this->runAsClusterOwner($ssh, $containerPath, $env, 'REINDEX DATABASE '.$quoted, 900);

        try {
            $this->runAsClusterOwner($ssh, $containerPath, $env, 'ALTER DATABASE '.$quoted.' REFRESH COLLATION VERSION', 60);
        } catch (\Throwable) {
            // Postgres 14 and older: nothing to refresh.
        }
    }

    private function createExtension(SSHService $ssh, string $containerPath, ContainerDeployment $deployment, string $extension): string
    {
        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $quoted = '"'.str_replace('"', '""', $extension).'"';

        try {
            $this->runAsClusterOwner($ssh, $containerPath, $env, 'CREATE EXTENSION IF NOT EXISTS '.$quoted, 120);
        } catch (\Throwable $e) {
            // The goal is the extension existing, not a command exiting zero.
            // One failed login among several attempts is not a failure if the
            // extension is there afterwards, and reporting it as one sent a
            // customer back to a button that had already done its job.
            if (! $this->extensionInstalled($ssh, $containerPath, $env, $extension)) {
                throw $e;
            }
        }

        return $this->label($extension).' is available in this database.';
    }

    /**
     * Run one statement as whichever role owns the cluster.
     *
     * The application role first, and `postgres` only as a fallback. This ran
     * as `postgres` first, on the assumption that the app role is not a
     * superuser. On a volume created with a custom POSTGRES_USER there is no
     * `postgres` role at all: the app user owns the cluster. So the first
     * attempt always failed, Postgres logged `FATAL: role "postgres" does not
     * exist`, and Doctor read six hours of logs, found the line the platform
     * had just written, and raised it to the customer as a critical fault.
     *
     * @param  array<string, mixed>  $env
     */
    private function runAsClusterOwner(SSHService $ssh, string $containerPath, array $env, string $sql, int $timeout): void
    {
        $database = escapeshellarg((string) ($env['DB_DATABASE'] ?? $env['POSTGRES_DB'] ?? 'appdb'));
        $password = escapeshellarg((string) ($env['DB_PASSWORD'] ?? $env['POSTGRES_PASSWORD'] ?? ''));

        $attempts = array_map(
            fn (string $role): string => 'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T -e PGPASSWORD='.$password
                .' db psql -v ON_ERROR_STOP=1 -U '.escapeshellarg($role).' -d '.$database.' -c '.escapeshellarg($sql),
            $this->superuserCandidates($env),
        );

        $ssh->exec(implode(' || ', $attempts), $timeout);
    }

    /**
     * Roles that might own this cluster, the likeliest first.
     *
     * The official image creates exactly one superuser, named by POSTGRES_USER,
     * and no `postgres` role at all unless that is the name chosen.
     * Deliberately not postgresqlAdminRoleCandidates(): that one leads with a
     * platform admin name which is itself sometimes `postgres`, which would put
     * the failing login back at the front.
     *
     * @param  array<string, mixed>  $env
     * @return list<string>
     */
    private function superuserCandidates(array $env): array
    {
        $roles = [];

        foreach ([
            $env['POSTGRES_USER'] ?? null,
            $env['DB_USERNAME'] ?? null,
            $env['TALKSASA_PLATFORM_DB_USERNAME'] ?? null,
            'postgres',
        ] as $role) {
            $role = trim((string) $role);
            if ($role !== '') {
                $roles[$role] = true;
            }
        }

        return array_keys($roles);
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private function extensionInstalled(
        SSHService $ssh,
        string $containerPath,
        array $env,
        string $extension,
    ): bool {
        $database = escapeshellarg((string) ($env['DB_DATABASE'] ?? $env['POSTGRES_DB'] ?? 'appdb'));
        $password = escapeshellarg((string) ($env['DB_PASSWORD'] ?? $env['POSTGRES_PASSWORD'] ?? ''));

        foreach ($this->superuserCandidates($env) as $role) {
            try {
                $found = trim((string) $ssh->exec(
                    'cd '.escapeshellarg($containerPath).' && docker compose exec -T -e PGPASSWORD='.$password
                    .' db psql -U '.escapeshellarg($role).' -d '.$database.' -tAc '
                    .escapeshellarg("SELECT 1 FROM pg_extension WHERE extname = '".str_replace("'", "''", $extension)."'"),
                    30
                ));
            } catch (\Throwable) {
                continue;
            }

            if (str_contains($found, '1')) {
                return true;
            }
        }

        return false;
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
