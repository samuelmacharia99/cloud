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

        $source->loadMissing('node', 'product.directAdminPackage');
        $creds = $source->getHostingCredentials() ?? [];
        $meta = is_array($source->service_meta) ? $source->service_meta : [];
        $username = (string) ($creds['username'] ?? $source->external_reference ?? ($meta['username'] ?? ''));
        $domain = $source->attachedDomainName()
            ?? (is_string($creds['domain'] ?? null) ? $creds['domain'] : null)
            ?? (is_string($meta['domain'] ?? null) ? $meta['domain'] : null);

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

        $detection = ['stack' => 'unknown', 'has_wp_config' => false];
        $stackError = null;
        try {
            $detection = $this->detectStackOnNode($source->node, $docroot);
        } catch (\Throwable $e) {
            $stackError = $e->getMessage();
        }

        $dashboard = null;
        $dashboardError = null;
        try {
            $dash = $api->getDashboard($username, is_string($domain) ? $domain : null);
            if ($dash['success'] ?? false) {
                $dashboard = $dash['data'] ?? null;
                if (is_array($dashboard) && $databases === [] && ! empty($dashboard['databases'])) {
                    $databases = array_values(array_map(
                        fn ($name) => ['name' => (string) $name],
                        $dashboard['databases']
                    ));
                }
            } else {
                $dashboardError = (string) ($dash['message'] ?? 'Failed to load DirectAdmin dashboard.');
            }
        } catch (\Throwable $e) {
            $dashboardError = $e->getMessage();
        }

        $warnings = [
            'Email mailboxes stay on DirectAdmin — only site files and database are moved.',
            'DNS must be updated to the container host after cutover.',
        ];
        if ($stackError) {
            $warnings[] = 'Could not SSH-detect application stack: '.$stackError;
        }
        if (! $detection['has_wp_config']) {
            $warnings[] = 'wp-config.php was not detected at the expected docroot. Migration may fail until the path is confirmed.';
        }

        $daAccount = is_array($meta['directadmin_account'] ?? null) ? $meta['directadmin_account'] : [];
        $packageUsage = is_array($meta['package_usage'] ?? null) ? $meta['package_usage'] : [];

        return [
            'username' => $username,
            'domain' => $domain,
            'databases' => $databases,
            'stack' => $detection['stack'],
            'docroot' => $docroot,
            'has_wp_config' => $detection['has_wp_config'],
            'email_stays_on_da' => true,
            'warnings' => $warnings,
            'account' => [
                'service_id' => $source->id,
                'service_name' => $source->name,
                'status' => $source->status?->value ?? (string) $source->status,
                'platform_product' => $source->product?->name,
                'platform_product_id' => $source->product_id,
                'billing_cycle' => $source->billing_cycle,
                'next_due_date' => optional($source->next_due_date)->toDateString(),
                'custom_price' => $source->custom_price,
                'node' => $source->node?->name,
                'node_hostname' => $source->node?->hostname ?? $source->node?->ip_address,
                'da_package' => $dashboard['package']
                    ?? ($daAccount['package'] ?? null)
                    ?? ($meta['package_name'] ?? null)
                    ?? ($meta['package'] ?? null)
                    ?? $source->product?->directAdminPackage?->name,
                'da_package_key' => $meta['package'] ?? $source->product?->directAdminPackage?->package_key,
                'panel_url' => $dashboard['panel_url'] ?? ($creds['panel_url'] ?? null),
                'suspended_on_da' => (bool) ($dashboard['suspended'] ?? false),
                'disk' => $dashboard['disk'] ?? null,
                'bandwidth' => $dashboard['bandwidth'] ?? null,
                'counts' => $dashboard['counts'] ?? ($daAccount['counts'] ?? null),
                'nameservers' => $dashboard['nameservers'] ?? [],
                'package_usage_meta' => $packageUsage,
                'dashboard_error' => $dashboardError,
                'stack_error' => $stackError,
            ],
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

            $wpCreds = $this->parseWpDatabaseCredentials($daSsh, (string) $inventory['docroot']);
            $dbName = $databaseName
                ?: ($wpCreds['DB_NAME'] ?? null)
                ?: ($inventory['databases'][0]['name'] ?? null);

            if (! $dbName) {
                throw new \RuntimeException('Could not determine MySQL database name for WordPress.');
            }

            if (blank($wpCreds['DB_USER'] ?? null)) {
                throw new \RuntimeException(
                    'Could not read DB_USER from wp-config.php. Cannot dump the database without WordPress MySQL credentials.'
                );
            }

            $daSsh->exec(
                $this->buildMysqlDumpCommand($wpCreds, $dbName, $dumpFile),
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
        return $this->parseWpDatabaseCredentials($ssh, $docroot)['DB_NAME'] ?? null;
    }

    /**
     * Read DB_* defines from wp-config.php on the DirectAdmin node.
     *
     * @return array{DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: string}
     */
    public function parseWpDatabaseCredentials(SSHService $ssh, string $docroot): array
    {
        $cfgPath = rtrim($docroot, '/').'/wp-config.php';
        $php = <<<'PHP'
$cfgPath = $argv[1] ?? '';
$text = @file_get_contents($cfgPath);
if ($text === false) {
    fwrite(STDERR, "unreadable\n");
    exit(1);
}
foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $key) {
    if (preg_match('/define\s*\(\s*[\'"]'.$key.'[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/s', $text, $m)) {
        echo $key.':'.base64_encode(stripcslashes($m[2]))."\n";
    }
}
PHP;

        $out = $ssh->exec('php -r '.escapeshellarg($php).' '.escapeshellarg($cfgPath).' 2>/dev/null || true');

        return $this->decodeWpDatabaseCredentialLines($out);
    }

    /**
     * @return array{DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: string}
     */
    public function decodeWpDatabaseCredentialLines(string $output): array
    {
        $creds = [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ];

        foreach (preg_split('/\r\n|\r|\n/', $output) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $encoded] = explode(':', $line, 2);
            if (! array_key_exists($key, $creds)) {
                continue;
            }
            $decoded = base64_decode(trim($encoded), true);
            if ($decoded === false) {
                continue;
            }
            $creds[$key] = $decoded;
        }

        if (($creds['DB_HOST'] ?? null) === null || $creds['DB_HOST'] === '') {
            $creds['DB_HOST'] = 'localhost';
        }

        return $creds;
    }

    /**
     * @param  array{DB_NAME?: ?string, DB_USER?: ?string, DB_PASSWORD?: ?string, DB_HOST?: string}  $creds
     */
    public function buildMysqlDumpCommand(array $creds, string $dbName, string $dumpFile): string
    {
        $user = (string) ($creds['DB_USER'] ?? '');
        $pass = (string) ($creds['DB_PASSWORD'] ?? '');
        $host = (string) ($creds['DB_HOST'] ?? 'localhost');

        if ($user === '') {
            throw new \InvalidArgumentException('MySQL dump requires DB_USER.');
        }

        $parts = [
            'MYSQL_PWD='.escapeshellarg($pass),
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
        ];

        if (preg_match('#^([^:]+):(/[^:]+)$#', $host, $socketMatch)) {
            $parts[] = '-h'.escapeshellarg($socketMatch[1]);
            $parts[] = '--socket='.escapeshellarg($socketMatch[2]);
        } elseif (preg_match('/^(.+):(\d+)$/', $host, $portMatch)) {
            $parts[] = '-h'.escapeshellarg($portMatch[1]);
            $parts[] = '-P'.escapeshellarg($portMatch[2]);
        } else {
            $parts[] = '-h'.escapeshellarg($host);
        }

        $parts[] = '-u'.escapeshellarg($user);
        $parts[] = escapeshellarg($dbName);
        $parts[] = '>';
        $parts[] = escapeshellarg($dumpFile);

        return implode(' ', $parts);
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
