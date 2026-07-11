<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Models\Service;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use App\Services\SSH\SSHService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ETL pipeline: DirectAdmin shared hosting → container app hosting.
 * WordPress-first. Email stays on DirectAdmin.
 */
class DirectAdminToContainerMigrationService
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';

    private const WORK_BASE = '/opt/talksasa/da-migrations';

    public function __construct(
        private ?ContainerDeploymentService $deployments = null,
    ) {
        $this->deployments ??= new ContainerDeploymentService;
    }

    /**
     * @return array{
     *     username: string,
     *     domain: ?string,
     *     databases: list<array{name: string}>,
     *     stack: string,
     *     docroot: ?string,
     *     has_wp_config: bool,
     *     email_stays_on_da: true,
     *     warnings: list<string>
     * }
     */
    public function inventory(Service $source): array
    {
        if (! $source->isSharedHosting()) {
            throw new \InvalidArgumentException('Source must be a DirectAdmin shared hosting service.');
        }

        $source->loadMissing('node', 'product');
        $creds = $source->getHostingCredentials();
        $username = (string) ($creds['username'] ?? '');
        $domain = $source->attachedDomainName() ?? ($creds['domain'] ?? null);

        if ($username === '' || ! $source->node) {
            throw new \InvalidArgumentException('Source hosting credentials or node are missing.');
        }

        $api = DirectAdminCustomerPanelApi::forServiceNode($source->node);
        $databases = [];
        $dbList = $api->listDatabases($username);
        if ($dbList['success'] ?? false) {
            $databases = array_values(array_map(
                fn ($row) => ['name' => (string) ($row['name'] ?? (is_string($row) ? $row : ''))],
                $dbList['data'] ?? []
            ));
            $databases = array_values(array_filter($databases, fn ($row) => $row['name'] !== ''));
        }

        $docroot = $domain
            ? "/home/{$username}/domains/{$domain}/public_html"
            : "/home/{$username}/public_html";

        $detection = $this->detectStackOnNode($source->node, $docroot);

        $warnings = [
            'Email mailboxes stay on DirectAdmin — only site files and database are moved.',
            'DNS must be updated to the container host after cutover.',
        ];
        if (! $detection['has_wp_config']) {
            $warnings[] = 'wp-config.php was not detected at the expected docroot. Migration may fail until the path is confirmed.';
        }

        return [
            'username' => $username,
            'domain' => $domain,
            'databases' => $databases,
            'stack' => $detection['stack'],
            'docroot' => $docroot,
            'has_wp_config' => $detection['has_wp_config'],
            'email_stays_on_da' => true,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{stack: string, has_wp_config: bool}
     */
    public function detectStackOnNode(Node $node, string $docroot): array
    {
        $ssh = SSHService::forNode($node);
        try {
            $escaped = escapeshellarg($docroot);
            $wp = trim($ssh->exec("test -f {$escaped}/wp-config.php && echo yes || echo no"));
            $artisan = trim($ssh->exec("test -f {$escaped}/artisan && echo yes || echo no"));
            $composer = trim($ssh->exec("test -f {$escaped}/composer.json && echo yes || echo no"));

            $stack = 'unknown';
            if ($wp === 'yes') {
                $stack = 'wordpress';
            } elseif ($artisan === 'yes') {
                $stack = 'laravel';
            } elseif ($composer === 'yes') {
                $stack = 'php';
            } elseif (trim($ssh->exec("test -d {$escaped} && echo yes || echo no")) === 'yes') {
                $stack = 'static_or_php';
            }

            return [
                'stack' => $stack,
                'has_wp_config' => $wp === 'yes',
            ];
        } finally {
            $ssh->disconnect();
        }
    }

    public function assertCanMigrate(Service $source, Service $target): void
    {
        $source->loadMissing('product');
        $target->loadMissing('product.containerTemplate', 'containerDeployment.node');

        if ($source->user_id !== $target->user_id) {
            throw new \InvalidArgumentException('Source and target must belong to the same customer.');
        }

        if (! $source->isSharedHosting()) {
            throw new \InvalidArgumentException('Source must be DirectAdmin shared hosting.');
        }

        if (! $target->isContainerHosting()) {
            throw new \InvalidArgumentException('Target must be an app hosting (container) service.');
        }

        $slug = $target->product?->containerTemplate?->slug;
        if ($slug !== 'wordpress') {
            throw new \InvalidArgumentException('WordPress migrator requires a WordPress container target.');
        }

        $deployment = $target->containerDeployment;
        if (! $deployment || ! $deployment->node) {
            throw new \InvalidArgumentException('Target container is not deployed yet.');
        }
    }

    /**
     * Export WordPress files + DB from a DA shared hosting service to local temp files.
     *
     * @param  array{docroot: ?string, databases: list<array{name: string}>, domain: ?string, stack: string, has_wp_config: bool}  $inventory
     * @return array{local_dump: string, local_tar: string, remote_work: string, db_name: string}
     */
    public function exportWordPressFromDirectAdmin(Service $source, array $inventory, ?string $databaseName = null): array
    {
        $source->loadMissing('node');
        if (! $source->node) {
            throw new \InvalidArgumentException('DirectAdmin node is missing.');
        }

        $workId = 'wp-export-'.$source->id.'-'.Str::lower(Str::random(6));
        $remoteWork = self::WORK_BASE.'/'.$workId;
        $dumpFile = $remoteWork.'/db.sql';
        $filesTar = $remoteWork.'/files.tar.gz';
        $localDump = storage_path('app/migrations/'.$workId.'-db.sql');
        $localTar = storage_path('app/migrations/'.$workId.'-files.tar.gz');

        if (! is_dir(dirname($localDump))) {
            mkdir(dirname($localDump), 0755, true);
        }

        $daSsh = SSHService::forNode($source->node);
        try {
            $daSsh->exec('mkdir -p '.escapeshellarg($remoteWork));

            $dbName = $databaseName
                ?: ($inventory['databases'][0]['name'] ?? null)
                ?: $this->parseWpDatabaseName($daSsh, (string) $inventory['docroot']);

            if (! $dbName) {
                throw new \RuntimeException('Could not determine MySQL database name for WordPress.');
            }

            $daSsh->exec(
                'mysqldump --single-transaction --quick '.escapeshellarg($dbName).' > '.escapeshellarg($dumpFile),
                600
            );
            $daSsh->exec(
                'tar -czf '.escapeshellarg($filesTar).' -C '.escapeshellarg((string) $inventory['docroot']).' .',
                900
            );
            $daSsh->downloadToLocal($dumpFile, $localDump);
            $daSsh->downloadToLocal($filesTar, $localTar);
        } finally {
            $daSsh->disconnect();
        }

        return [
            'local_dump' => $localDump,
            'local_tar' => $localTar,
            'remote_work' => $remoteWork,
            'db_name' => $dbName,
        ];
    }

    /**
     * Import previously exported WordPress dump/tar into a deployed WordPress container service.
     */
    public function importWordPressIntoContainer(
        Service $target,
        string $localDump,
        string $localTar,
        string $remoteWork,
        ?Node $cleanupDaNode = null,
    ): void {
        $target->loadMissing('containerDeployment.node');
        $deployment = $target->containerDeployment;
        if (! $deployment?->node) {
            throw new \InvalidArgumentException('Target container is not deployed.');
        }

        $dumpFile = rtrim($remoteWork, '/').'/db.sql';
        $filesTar = rtrim($remoteWork, '/').'/files.tar.gz';
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $appService = $deployment->container_name;

        $targetSsh = SSHService::forNode($deployment->node);
        try {
            $targetSsh->exec('mkdir -p '.escapeshellarg($remoteWork));
            $targetSsh->uploadFromLocal($localDump, $dumpFile);
            $targetSsh->uploadFromLocal($localTar, $filesTar);

            $targetSsh->exec(
                "cd {$containerPath} && docker compose cp ".escapeshellarg($filesTar)." {$appService}:/tmp/wp-files.tar.gz",
                300
            );
            $targetSsh->exec(
                "cd {$containerPath} && docker compose exec -T {$appService} sh -c "
                .escapeshellarg('mkdir -p /var/www/html && tar -xzf /tmp/wp-files.tar.gz -C /var/www/html && rm -f /tmp/wp-files.tar.gz'),
                900
            );

            $env = $this->readContainerDbEnv($targetSsh, $containerPath);
            $dbService = $this->resolveDbServiceName($targetSsh, $containerPath);
            $dbUser = $env['WORDPRESS_DB_USER'] ?? $env['MYSQL_USER'] ?? $env['DB_USERNAME'] ?? 'wordpress';
            $dbPass = $env['WORDPRESS_DB_PASSWORD'] ?? $env['MYSQL_PASSWORD'] ?? $env['DB_PASSWORD'] ?? '';
            $dbDatabase = $env['WORDPRESS_DB_NAME'] ?? $env['MYSQL_DATABASE'] ?? $env['DB_DATABASE'] ?? 'wordpress';

            $targetSsh->exec(
                "cd {$containerPath} && docker compose exec -T -e MYSQL_PWD=".escapeshellarg($dbPass)
                .' '.$dbService.' mysql -u'.escapeshellarg($dbUser).' '.escapeshellarg($dbDatabase)
                .' < '.escapeshellarg($dumpFile),
                600
            );

            $this->rewriteWpConfigInContainer($targetSsh, $containerPath, $appService, [
                'DB_NAME' => $dbDatabase,
                'DB_USER' => $dbUser,
                'DB_PASSWORD' => $dbPass,
                'DB_HOST' => $dbService,
            ]);

            @$targetSsh->exec("cd {$containerPath} && docker compose restart {$appService}", 120);
            @$targetSsh->exec('rm -rf '.escapeshellarg($remoteWork));
        } finally {
            $targetSsh->disconnect();
        }

        if ($cleanupDaNode) {
            @$this->cleanupDaWork($cleanupDaNode, $remoteWork);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordExternalProgress(Service $service, array $data): void
    {
        $this->updateMigrationMeta($service, $data);
    }

    /**
     * Run WordPress ETL into an existing WordPress container.
     *
     * @return array{ok: bool, message: string, steps: list<string>}
     */
    public function migrateWordPress(Service $source, Service $target, ?string $databaseName = null): array
    {
        $this->assertCanMigrate($source, $target);
        $source->loadMissing('node', 'product');
        $target->loadMissing('containerDeployment.node', 'product.containerTemplate');

        $inventory = $this->inventory($source);
        if ($inventory['stack'] !== 'wordpress' && ! $inventory['has_wp_config']) {
            throw new \InvalidArgumentException('Source does not look like a WordPress site (no wp-config.php).');
        }

        $steps = [];
        $this->updateMigrationMeta($target, [
            'status' => 'running',
            'source_service_id' => $source->id,
            'started_at' => now()->toIso8601String(),
            'steps' => [],
            'error' => null,
        ]);

        try {
            $steps[] = 'Inventory complete ('.$inventory['stack'].')';
            $this->appendStep($target, $steps);

            $steps[] = 'Exporting from DirectAdmin';
            $this->appendStep($target, $steps);
            $export = $this->exportWordPressFromDirectAdmin($source, $inventory, $databaseName);

            $steps[] = 'Importing into container';
            $this->appendStep($target, $steps);
            $this->importWordPressIntoContainer(
                $target,
                $export['local_dump'],
                $export['local_tar'],
                $export['remote_work'],
                $source->node,
            );

            foreach ([$export['local_dump'], $export['local_tar']] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $steps[] = 'Migration completed. Point DNS to the container and keep email on DirectAdmin.';
            $this->updateMigrationMeta($target, [
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'steps' => $steps,
                'source_domain' => $inventory['domain'],
            ]);

            return [
                'ok' => true,
                'message' => 'WordPress site migrated into the container. Update DNS; email remains on DirectAdmin.',
                'steps' => $steps,
            ];
        } catch (\Throwable $e) {
            Log::error('DA→container WordPress migration failed', [
                'source' => $source->id,
                'target' => $target->id,
                'error' => $e->getMessage(),
            ]);
            $this->updateMigrationMeta($target, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'steps' => $steps,
                'failed_at' => now()->toIso8601String(),
            ]);
            throw $e;
        }
    }

    /**
     * @return Collection<int, Service>
     */
    public function availableWordPressTargets(Service $source)
    {
        return Service::query()
            ->where('user_id', $source->user_id)
            ->where('id', '!=', $source->id)
            ->whereHas('product', fn ($q) => $q->where('type', 'container_hosting'))
            ->whereHas('product.containerTemplate', fn ($q) => $q->where('slug', 'wordpress'))
            ->whereHas('containerDeployment')
            ->with(['product.containerTemplate', 'containerDeployment'])
            ->orderByDesc('id')
            ->get();
    }

    private function parseWpDatabaseName(SSHService $ssh, string $docroot): ?string
    {
        $cfg = escapeshellarg($docroot.'/wp-config.php');
        $out = $ssh->exec("grep -E \"define\\s*\\(\\s*'DB_NAME'\" {$cfg} | head -1");
        if (preg_match("/DB_NAME'\\s*,\\s*'([^']+)'/", $out, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function readContainerDbEnv(SSHService $ssh, string $containerPath): array
    {
        $raw = $ssh->exec('grep -E "^(MYSQL_|DB_|WORDPRESS_DB_)" '.escapeshellarg($containerPath.'/.env').' 2>/dev/null || true');
        $env = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v, " \t\"'");
        }

        return $env;
    }

    private function resolveDbServiceName(SSHService $ssh, string $containerPath): string
    {
        $names = trim($ssh->exec("cd {$containerPath} && docker compose config --services 2>/dev/null"));
        $services = preg_split('/\s+/', $names) ?: [];
        foreach (['db', 'mysql', 'mariadb'] as $candidate) {
            if (in_array($candidate, $services, true)) {
                return $candidate;
            }
        }

        return 'db';
    }

    /**
     * @param  array{DB_NAME: string, DB_USER: string, DB_PASSWORD: string, DB_HOST: string}  $db
     */
    private function rewriteWpConfigInContainer(SSHService $ssh, string $containerPath, string $appService, array $db): void
    {
        foreach ($db as $key => $value) {
            $escapedValue = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
            $php = <<<PHP
\$cfg = '/var/www/html/wp-config.php';
\$key = '{$key}';
\$val = '{$escapedValue}';
\$text = file_get_contents(\$cfg);
\$pattern = "/define\\s*\\(\\s*['\\"]{\$key}['\\"]\\s*,\\s*['\\"].*?['\\"]\\s*\\)/i";
\$repl = "define('{\$key}', '{\$val}')";
if (preg_match(\$pattern, \$text)) {
    \$text = preg_replace(\$pattern, \$repl, \$text, 1);
} else {
    \$text = preg_replace('/<\\?php/', "<?php\\n" . \$repl, \$text, 1);
}
file_put_contents(\$cfg, \$text);
PHP;
            $ssh->exec(
                "cd {$containerPath} && docker compose exec -T {$appService} php -r ".escapeshellarg($php),
                60
            );
        }
    }

    private function cleanupDaWork(Node $node, string $remoteWork): void
    {
        try {
            $ssh = SSHService::forNode($node);
            try {
                @$ssh->exec('rm -rf '.escapeshellarg($remoteWork));
            } finally {
                $ssh->disconnect();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function updateMigrationMeta(Service $service, array $data): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['da_migration'] = array_merge($meta['da_migration'] ?? [], $data);
        $service->update(['service_meta' => $meta]);
        $service->refresh();
    }

    /**
     * @param  list<string>  $steps
     */
    private function appendStep(Service $service, array $steps): void
    {
        $this->updateMigrationMeta($service, ['steps' => $steps, 'status' => 'running']);
    }
}
