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
 * Supports WordPress, Laravel, plain PHP, and static sites. Email stays on DirectAdmin.
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
            'Each live website needs its own App Hosting container — addon sites are listed below and must be converted as separate services after the primary.',
        ];
        if ($stackError) {
            $warnings[] = 'Could not SSH-detect application stack: '.$stackError;
        }
        if ($detection['stack'] === 'wordpress' && ! $detection['has_wp_config']) {
            $warnings[] = 'wp-config.php was not detected at the expected docroot. Migration may fail until the path is confirmed.';
        }

        $sites = $this->listSitesOnDirectAdminUser($source->node, $username, is_string($domain) ? $domain : null);

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
            'sites' => $sites,
            'addon_site_count' => max(0, count(array_filter($sites, fn ($site) => ! ($site['is_primary'] ?? false)))),
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
     * List domains under /home/{user}/domains with stack detection.
     *
     * @return list<array{
     *     domain: string,
     *     docroot: string,
     *     stack: string,
     *     has_wp_config: bool,
     *     is_primary: bool,
     *     recommended_action: string
     * }>
     */
    public function listSitesOnDirectAdminUser(Node $node, string $username, ?string $primaryDomain): array
    {
        $ssh = SSHService::forNode($node);
        $sites = [];

        try {
            $domainsRoot = '/home/'.trim($username, '/').'/domains';
            $listing = trim($ssh->exec(
                'if [ -d '.escapeshellarg($domainsRoot).' ]; then ls -1 '.escapeshellarg($domainsRoot).'; else echo ""; fi'
            ));
            $names = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $listing) ?: [])));

            if ($names === [] && filled($primaryDomain)) {
                $names = [$primaryDomain];
            }

            foreach ($names as $name) {
                if ($name === '' || str_contains($name, '/') || $name === '.' || $name === '..') {
                    continue;
                }

                $docroot = $domainsRoot.'/'.$name.'/public_html';
                try {
                    $detection = $this->detectStackViaSsh($ssh, $docroot);
                } catch (\Throwable) {
                    $detection = ['stack' => 'unknown', 'has_wp_config' => false];
                }

                $isPrimary = filled($primaryDomain) && strcasecmp($name, $primaryDomain) === 0;
                $sites[] = [
                    'domain' => $name,
                    'docroot' => $docroot,
                    'stack' => $detection['stack'],
                    'has_wp_config' => (bool) ($detection['has_wp_config'] ?? false),
                    'is_primary' => $isPrimary,
                    'recommended_action' => $isPrimary
                        ? 'Convert this service in-place to one App Hosting container.'
                        : 'Create a separate App Hosting service for this site after primary convert (1 site = 1 container).',
                ];
            }

            usort($sites, function (array $a, array $b): int {
                if (($a['is_primary'] ?? false) === ($b['is_primary'] ?? false)) {
                    return strcmp($a['domain'], $b['domain']);
                }

                return ($a['is_primary'] ?? false) ? -1 : 1;
            });
        } finally {
            $ssh->disconnect();
        }

        return $sites;
    }

    /**
     * @return array{stack: string, has_wp_config: bool}
     */
    public function detectStackOnNode(Node $node, string $docroot): array
    {
        $ssh = SSHService::forNode($node);
        try {
            return $this->detectStackViaSsh($ssh, $docroot);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{stack: string, has_wp_config: bool}
     */
    public function detectStackViaSsh(SSHService $ssh, string $docroot): array
    {
        $escaped = escapeshellarg($docroot);
        $checks = trim($ssh->exec(
            'echo WP:$(test -f '.$escaped.'/wp-config.php && echo yes || echo no);'
            .'echo ART:$(test -f '.$escaped.'/artisan && echo yes || echo no);'
            .'echo CMP:$(test -f '.$escaped.'/composer.json && echo yes || echo no);'
            .'echo IDX:$(test -f '.$escaped.'/index.php -o -f '.$escaped.'/index.html -o -f '.$escaped.'/index.htm && echo yes || echo no);'
            .'echo DIR:$(test -d '.$escaped.' && echo yes || echo no)'
        ));
        $wp = str_contains($checks, 'WP:yes');
        $artisan = str_contains($checks, 'ART:yes');
        $composer = str_contains($checks, 'CMP:yes');
        $index = str_contains($checks, 'IDX:yes');
        $dir = str_contains($checks, 'DIR:yes');

        $stack = 'unknown';
        if ($wp) {
            $stack = 'wordpress';
        } elseif ($artisan) {
            $stack = 'laravel';
        } elseif ($composer) {
            $stack = 'php';
        } elseif ($dir && $index) {
            $stack = 'static_or_php';
        } elseif ($dir) {
            $stack = 'static_or_php';
        }

        return [
            'stack' => $stack,
            'has_wp_config' => $wp,
        ];
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

            $defaultsFile = $remoteWork.'/mysqldump.cnf';
            $this->writeRemoteMysqlDefaultsFile($daSsh, $defaultsFile, $wpCreds);
            try {
                $daSsh->exec(
                    $this->buildMysqlDumpCommand($wpCreds, $dbName, $dumpFile, $defaultsFile),
                    600
                );
            } finally {
                @$daSsh->exec('rm -f '.escapeshellarg($defaultsFile));
            }

            $daSsh->exec(
                $this->buildWordPressFilesTarCommand((string) $inventory['docroot'], $filesTar),
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
     *
     * @param  (callable(string): void)|null  $onProgress
     */
    public function importWordPressIntoContainer(
        Service $target,
        string $localDump,
        string $localTar,
        string $remoteWork,
        ?Node $cleanupDaNode = null,
        ?callable $onProgress = null,
    ): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $progress = static function (string $detail) use ($onProgress): void {
            if ($onProgress) {
                $onProgress($detail);
            }
        };

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
            $progress('Uploading export archives to container node');
            $targetSsh->exec('mkdir -p '.escapeshellarg($remoteWork));
            $targetSsh->uploadFromLocal($localDump, $dumpFile);
            $targetSsh->uploadFromLocal($localTar, $filesTar);

            $db = $this->resolveWordpressImportCredentials($target, $targetSsh, $containerPath);
            $dbService = $db['service'];

            // Prefer secrets from the running mysql container (source of truth after compose up).
            $live = $this->readLiveMysqlSidecarEnv($targetSsh, $containerPath, $dbService);
            if (($live['MYSQL_ROOT_PASSWORD'] ?? '') !== '') {
                $db['root_password'] = $live['MYSQL_ROOT_PASSWORD'];
            }
            if (($live['MYSQL_PASSWORD'] ?? '') !== '') {
                $db['password'] = $live['MYSQL_PASSWORD'];
            }
            if (($live['MYSQL_USER'] ?? '') !== '') {
                $db['user'] = $live['MYSQL_USER'];
            }
            if (($live['MYSQL_DATABASE'] ?? '') !== '') {
                $db['database'] = $live['MYSQL_DATABASE'];
            }

            $progress('Waiting for MySQL sidecar');
            $this->waitForComposeMysql(
                $targetSsh,
                $containerPath,
                $dbService,
                $db['root_password'] !== '' ? $db['root_password'] : $db['password'],
                180
            );

            // Extract onto the host bind mount (.../app → /var/www/html) so the customer
            // file manager sees the same files as the WordPress container.
            $hostAppPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
            $progress('Extracting WordPress files onto host bind mount');
            $targetSsh->exec('mkdir -p '.escapeshellarg($hostAppPath));
            $targetSsh->exec(
                $this->buildWordPressHostExtractCommand($filesTar, $hostAppPath),
                900
            );

            // Heavy extract/IO can stall or restart the MySQL sidecar — wait again before import.
            $progress('Re-checking MySQL sidecar before import');
            $this->waitForComposeMysql(
                $targetSsh,
                $containerPath,
                $dbService,
                $db['root_password'] !== '' ? $db['root_password'] : $db['password'],
                180,
                $db['root_password'] !== '' ? 'root' : ($db['user'] !== '' ? $db['user'] : 'wordpress'),
            );

            $importPass = $db['root_password'] !== '' ? $db['root_password'] : $db['password'];
            $importUser = $db['root_password'] !== '' ? 'root' : $db['user'];
            if ($importPass === '') {
                throw new \RuntimeException(
                    'WordPress container MySQL password is missing (check deployment env_values / MYSQL_ROOT_PASSWORD).'
                );
            }

            $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $importUser) ?: 'root';
            $safeDatabase = preg_replace('/[^a-zA-Z0-9_]/', '', $db['database']) ?: 'wordpress';

            $createDbSql = 'CREATE DATABASE IF NOT EXISTS `'.$safeDatabase.'`;';
            try {
                $this->execMysqlInCompose(
                    $targetSsh,
                    $containerPath,
                    $dbService,
                    $safeUser,
                    $importPass,
                    $createDbSql,
                    null,
                    60
                );
            } catch (\Throwable $rootError) {
                // Fall back to app user — MYSQL_DATABASE already creates the schema on first boot.
                if ($safeUser === 'root' && $db['password'] !== '' && $db['user'] !== '') {
                    $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $db['user']) ?: 'wordpress';
                    $importPass = $db['password'];
                    Log::warning('WordPress import root login failed; retrying as app user', [
                        'error' => $rootError->getMessage(),
                        'user' => $safeUser,
                    ]);
                    $this->waitForComposeMysql(
                        $targetSsh,
                        $containerPath,
                        $dbService,
                        $importPass,
                        120,
                        $safeUser,
                    );
                    $this->execMysqlInCompose(
                        $targetSsh,
                        $containerPath,
                        $dbService,
                        $safeUser,
                        $importPass,
                        $createDbSql,
                        null,
                        60
                    );
                } else {
                    throw $rootError;
                }
            }

            $progress('Importing MySQL dump');
            $this->importMysqlDumpViaCompose(
                $targetSsh,
                $containerPath,
                $dbService,
                $dumpFile,
                $safeUser,
                $importPass,
                $safeDatabase,
            );

            $dbDefines = [
                'DB_NAME' => $safeDatabase,
                'DB_USER' => $db['user'] !== '' ? $db['user'] : 'wordpress',
                'DB_PASSWORD' => $db['password'] !== '' ? $db['password'] : $importPass,
                'DB_HOST' => $dbService,
            ];

            $progress('Rewriting wp-config and normalizing runtime');
            // Rewrite via the WordPress container (nodes typically have no host PHP).
            // With the host app bind-mount, this updates the same files the file manager sees.
            $this->rewriteWpConfigInContainer($targetSsh, $containerPath, $appService, $dbDefines);
            $this->ensureWordPressProxyHttpsAwareness($targetSsh, $containerPath, $appService);
            $this->sanitizeWordPressHostRuntimeConfig($targetSsh, $hostAppPath);
            $this->normalizeWordPressAppPermissions($targetSsh, $hostAppPath, $containerPath, $appService);

            $progress('Restarting WordPress container');
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
     * Stack-aware DA export used by convert-in-place (WordPress, Laravel, PHP, static).
     *
     * @param  array{docroot: ?string, databases: list<array{name: string}>, domain: ?string, stack: string, has_wp_config: bool}  $inventory
     * @return array{local_dump: ?string, local_tar: string, remote_work: string, db_name: ?string, stack: string}
     */
    public function exportSiteFromDirectAdmin(Service $source, array $inventory, ?string $databaseName = null): array
    {
        $stack = (string) ($inventory['stack'] ?? 'unknown');
        if ($stack === 'wordpress' || ($inventory['has_wp_config'] ?? false)) {
            $export = $this->exportWordPressFromDirectAdmin($source, $inventory, $databaseName);

            return array_merge($export, ['stack' => 'wordpress']);
        }

        $source->loadMissing('node');
        if (! $source->node) {
            throw new \InvalidArgumentException('DirectAdmin node is missing.');
        }

        $workId = 'site-export-'.$source->id.'-'.Str::lower(Str::random(6));
        $remoteWork = self::WORK_BASE.'/'.$workId;
        $dumpFile = $remoteWork.'/db.sql';
        $filesTar = $remoteWork.'/files.tar.gz';
        $localDump = storage_path('app/migrations/'.$workId.'-db.sql');
        $localTar = storage_path('app/migrations/'.$workId.'-files.tar.gz');

        if (! is_dir(dirname($localDump))) {
            mkdir(dirname($localDump), 0755, true);
        }

        $needsDatabase = in_array($stack, ['laravel', 'php'], true);
        $dbName = null;
        $localDumpPath = null;

        $daSsh = SSHService::forNode($source->node);
        try {
            $daSsh->exec('mkdir -p '.escapeshellarg($remoteWork));

            $docroot = (string) ($inventory['docroot'] ?? '');
            if ($docroot === '') {
                throw new \RuntimeException('Docroot is missing from inventory.');
            }

            if ($needsDatabase) {
                $dbCreds = $stack === 'laravel'
                    ? $this->parseLaravelEnvDatabaseCredentials($daSsh, $docroot)
                    : $this->parseGenericPhpDatabaseCredentials($daSsh, $docroot, $inventory);

                $dbName = $databaseName
                    ?: ($dbCreds['DB_NAME'] ?? null)
                    ?: ($inventory['databases'][0]['name'] ?? null);

                if ($dbName && ! blank($dbCreds['DB_USER'] ?? null)) {
                    $defaultsFile = $remoteWork.'/mysqldump.cnf';
                    $this->writeRemoteMysqlDefaultsFile($daSsh, $defaultsFile, $dbCreds);
                    try {
                        $daSsh->exec(
                            $this->buildMysqlDumpCommand($dbCreds, $dbName, $dumpFile, $defaultsFile),
                            600
                        );
                        $daSsh->downloadToLocal($dumpFile, $localDump);
                        $localDumpPath = $localDump;
                    } finally {
                        @$daSsh->exec('rm -f '.escapeshellarg($defaultsFile));
                    }
                } elseif ($needsDatabase && $stack === 'laravel') {
                    throw new \RuntimeException(
                        'Could not determine Laravel database credentials from .env / inventory. Pass database_name or fix .env DB_* values.'
                    );
                }
            }

            $daSsh->exec(
                $this->buildGenericDocrootTarCommand($docroot, $filesTar, $stack),
                900
            );
            $daSsh->downloadToLocal($filesTar, $localTar);
        } finally {
            $daSsh->disconnect();
        }

        return [
            'local_dump' => $localDumpPath,
            'local_tar' => $localTar,
            'remote_work' => $remoteWork,
            'db_name' => $dbName,
            'stack' => $stack === 'unknown' ? 'static_or_php' : $stack,
        ];
    }

    /**
     * Import a generic/Laravel/static export into a deployed container (bind-mounted host app path).
     *
     * @param  (callable(string): void)|null  $onProgress
     */
    public function importSiteIntoContainer(
        Service $target,
        ?string $localDump,
        string $localTar,
        string $remoteWork,
        string $stack,
        ?Node $cleanupDaNode = null,
        ?callable $onProgress = null,
    ): void {
        if ($stack === 'wordpress') {
            if (! is_string($localDump) || $localDump === '') {
                throw new \InvalidArgumentException('WordPress import requires a MySQL dump.');
            }
            $this->importWordPressIntoContainer(
                $target,
                $localDump,
                $localTar,
                $remoteWork,
                $cleanupDaNode,
                $onProgress
            );

            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $progress = static function (string $detail) use ($onProgress): void {
            if ($onProgress) {
                $onProgress($detail);
            }
        };

        $target->loadMissing('containerDeployment.node', 'product.containerTemplate');
        $deployment = $target->containerDeployment;
        if (! $deployment?->node) {
            throw new \InvalidArgumentException('Target container is not deployed.');
        }

        $filesTar = rtrim($remoteWork, '/').'/files.tar.gz';
        $dumpFile = rtrim($remoteWork, '/').'/db.sql';
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $appService = $deployment->container_name;
        $hostAppPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';

        $targetSsh = SSHService::forNode($deployment->node);
        try {
            $progress('Uploading export archives to container node');
            $targetSsh->exec('mkdir -p '.escapeshellarg($remoteWork));
            $targetSsh->uploadFromLocal($localTar, $filesTar);
            if (is_string($localDump) && is_file($localDump)) {
                $targetSsh->uploadFromLocal($localDump, $dumpFile);
            }

            $progress('Extracting site files onto host bind mount');
            $targetSsh->exec('mkdir -p '.escapeshellarg($hostAppPath));
            $targetSsh->exec(
                $this->buildGenericHostExtractCommand($filesTar, $hostAppPath),
                900
            );

            if (is_string($localDump) && is_file($localDump) && in_array($stack, ['laravel', 'php'], true)) {
                $db = $this->resolveGenericImportCredentials($target, $targetSsh, $containerPath);
                $dbService = $db['service'];

                $live = $this->readLiveMysqlSidecarEnv($targetSsh, $containerPath, $dbService);
                if (($live['MYSQL_ROOT_PASSWORD'] ?? '') !== '') {
                    $db['root_password'] = $live['MYSQL_ROOT_PASSWORD'];
                }
                if (($live['MYSQL_PASSWORD'] ?? '') !== '') {
                    $db['password'] = $live['MYSQL_PASSWORD'];
                }
                if (($live['MYSQL_USER'] ?? '') !== '') {
                    $db['user'] = $live['MYSQL_USER'];
                }
                if (($live['MYSQL_DATABASE'] ?? '') !== '') {
                    $db['database'] = $live['MYSQL_DATABASE'];
                }

                $progress('Waiting for MySQL sidecar');
                $this->waitForComposeMysql(
                    $targetSsh,
                    $containerPath,
                    $dbService,
                    $db['root_password'] !== '' ? $db['root_password'] : $db['password'],
                    180
                );

                $importPass = $db['root_password'] !== '' ? $db['root_password'] : $db['password'];
                $importUser = $db['root_password'] !== '' ? 'root' : $db['user'];
                if ($importPass === '') {
                    throw new \RuntimeException(
                        'Container MySQL password is missing (check deployment env_values / MYSQL_ROOT_PASSWORD).'
                    );
                }

                $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $importUser) ?: 'root';
                $safeDatabase = preg_replace('/[^a-zA-Z0-9_]/', '', $db['database']) ?: 'appdb';

                $createDbSql = 'CREATE DATABASE IF NOT EXISTS `'.$safeDatabase.'`;';
                $this->execMysqlInCompose(
                    $targetSsh,
                    $containerPath,
                    $dbService,
                    $safeUser,
                    $importPass,
                    $createDbSql,
                    null,
                    60
                );

                $progress('Importing MySQL dump');
                $this->importMysqlDumpViaCompose(
                    $targetSsh,
                    $containerPath,
                    $dbService,
                    $dumpFile,
                    $safeUser,
                    $importPass,
                    $safeDatabase,
                );

                $progress('Rewriting application database settings');
                $this->rewriteAppEnvDatabase(
                    $targetSsh,
                    $hostAppPath,
                    [
                        'DB_CONNECTION' => 'mysql',
                        'DB_HOST' => $dbService,
                        'DB_PORT' => '3306',
                        'DB_DATABASE' => $safeDatabase,
                        'DB_USERNAME' => $db['user'] !== '' ? $db['user'] : 'appuser',
                        'DB_PASSWORD' => $db['password'] !== '' ? $db['password'] : $importPass,
                    ]
                );
            } else {
                $progress('No database dump imported (static/files-only or missing dump)');
            }

            $progress('Restarting application container');
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
     * @return array{service: string, database: string, user: string, password: string, root_password: string}
     */
    public function resolveGenericImportCredentials(Service $target, SSHService $ssh, string $containerPath): array
    {
        $wp = $this->resolveWordpressImportCredentials($target, $ssh, $containerPath);
        $target->loadMissing('containerDeployment');
        $env = is_array($target->containerDeployment?->env_values) ? $target->containerDeployment->env_values : [];
        $fromFile = $this->readContainerDbEnv($ssh, $containerPath);

        $database = (string) (
            $env['DB_DATABASE']
            ?? $env['MYSQL_DATABASE']
            ?? $fromFile['DB_DATABASE']
            ?? $fromFile['MYSQL_DATABASE']
            ?? $wp['database']
            ?? 'appdb'
        );
        $user = (string) (
            $env['DB_USERNAME']
            ?? $env['MYSQL_USER']
            ?? $fromFile['DB_USERNAME']
            ?? $fromFile['MYSQL_USER']
            ?? $wp['user']
            ?? 'appuser'
        );
        $password = (string) (
            $env['DB_PASSWORD']
            ?? $env['MYSQL_PASSWORD']
            ?? $fromFile['DB_PASSWORD']
            ?? $fromFile['MYSQL_PASSWORD']
            ?? $wp['password']
            ?? ''
        );

        return [
            'service' => $wp['service'],
            'database' => $database !== '' ? $database : 'appdb',
            'user' => $user !== '' ? $user : 'appuser',
            'password' => $password,
            'root_password' => $wp['root_password'],
        ];
    }

    /**
     * @return array{DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: string}
     */
    public function parseLaravelEnvDatabaseCredentials(SSHService $ssh, string $docroot): array
    {
        $envPath = rtrim($docroot, '/').'/.env';
        $raw = $ssh->exec('grep -E "^(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=" '.escapeshellarg($envPath).' 2>/dev/null || true');

        $creds = [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");
            match ($key) {
                'DB_HOST' => $creds['DB_HOST'] = $value !== '' ? $value : 'localhost',
                'DB_DATABASE' => $creds['DB_NAME'] = $value !== '' ? $value : null,
                'DB_USERNAME' => $creds['DB_USER'] = $value !== '' ? $value : null,
                'DB_PASSWORD' => $creds['DB_PASSWORD'] = $value,
                default => null,
            };
        }

        return $creds;
    }

    /**
     * @param  array{databases?: list<array{name: string}>}  $inventory
     * @return array{DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: string}
     */
    public function parseGenericPhpDatabaseCredentials(SSHService $ssh, string $docroot, array $inventory = []): array
    {
        $laravelStyle = $this->parseLaravelEnvDatabaseCredentials($ssh, $docroot);
        if (! blank($laravelStyle['DB_USER'] ?? null)) {
            return $laravelStyle;
        }

        // Best-effort: first DA database name only — credentials still required for dump.
        return [
            'DB_NAME' => $inventory['databases'][0]['name'] ?? null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ];
    }

    public function buildGenericDocrootTarCommand(string $docroot, string $filesTar, string $stack): string
    {
        $excludes = [
            './node_modules',
            './.git',
            './storage/logs',
            './storage/framework/cache',
            './storage/framework/sessions',
            './storage/framework/views',
            './*.log',
        ];

        if ($stack === 'wordpress') {
            return $this->buildWordPressFilesTarCommand($docroot, $filesTar);
        }

        $excludeArgs = array_map(fn ($path) => '--exclude='.escapeshellarg($path), $excludes);
        $tar = 'tar -czf '.escapeshellarg($filesTar)
            .' '.implode(' ', $excludeArgs)
            .' -C '.escapeshellarg($docroot)
            .' .';

        return $tar
            .' ; status=$?'
            .' ; if [ "$status" -eq 0 ] || [ "$status" -eq 1 ]; then'
            .'   if [ -s '.escapeshellarg($filesTar).' ]; then exit 0; fi'
            .' ; fi'
            .' ; exit "$status"';
    }

    public function buildGenericHostExtractCommand(string $filesTar, string $hostAppPath): string
    {
        return 'mkdir -p '.escapeshellarg($hostAppPath)
            .' && tar -xzf '.escapeshellarg($filesTar).' -C '.escapeshellarg($hostAppPath)
            .' ; status=$?'
            .' ; if [ "$status" -eq 0 ] || [ "$status" -eq 1 ]; then'
            .'   if [ "$(ls -A '.escapeshellarg($hostAppPath).' 2>/dev/null)" ]; then exit 0; fi'
            .' ; fi'
            .' ; exit "$status"';
    }

    /**
     * @param  array<string, string>  $values
     */
    public function rewriteAppEnvDatabase(SSHService $ssh, string $hostAppPath, array $values): void
    {
        $envPath = rtrim($hostAppPath, '/').'/.env';
        $exists = trim($ssh->exec('test -f '.escapeshellarg($envPath).' && echo yes || echo no'));

        if ($exists !== 'yes') {
            $lines = [];
            foreach ($values as $key => $value) {
                $lines[] = $key.'='.$value;
            }
            $ssh->exec('printf %s '.escapeshellarg(implode("\n", $lines)."\n").' > '.escapeshellarg($envPath));

            return;
        }

        $payload = base64_encode(json_encode($values, JSON_UNESCAPED_SLASHES));
        $php = <<<'PHP'
$envPath = $argv[1] ?? '';
$json = base64_decode($argv[2] ?? '', true);
$values = is_string($json) ? json_decode($json, true) : null;
if (! is_file($envPath) || ! is_array($values)) {
    fwrite(STDERR, "invalid env rewrite\n");
    exit(1);
}
$text = file_get_contents($envPath);
foreach ($values as $key => $value) {
    $key = (string) $key;
    $value = str_replace(["\r", "\n"], '', (string) $value);
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    if (preg_match($pattern, $text)) {
        $text = preg_replace($pattern, $line, $text, 1);
    } else {
        $text = rtrim($text)."\n".$line."\n";
    }
}
file_put_contents($envPath, $text);
PHP;

        $ssh->exec(
            'php -r '.escapeshellarg($php).' '.escapeshellarg($envPath).' '.escapeshellarg($payload).' 2>/dev/null'
            .' || python3 -c '.escapeshellarg(
                'import json,base64,re,sys; p=sys.argv[1]; vals=json.loads(base64.b64decode(sys.argv[2])); t=open(p).read();\n'
                .'for k,v in vals.items():\n'
                .' v=str(v).replace("\\r","").replace("\\n",""); line=f"{k}={v}";\n'
                .' t=re.sub(rf"^{re.escape(k)}=.*$", line, t, count=1, flags=re.M) if re.search(rf"^{re.escape(k)}=.*$", t, flags=re.M) else t.rstrip()+"\\n"+line+"\\n";\n'
                .'open(p,"w").write(t)'
            ).' '.escapeshellarg($envPath).' '.escapeshellarg($payload),
            60
        );
    }

    /**
     * @return array{service: string, database: string, user: string, password: string, root_password: string}
     */
    public function resolveWordpressImportCredentials(Service $target, SSHService $ssh, string $containerPath): array
    {
        $target->loadMissing('containerDeployment');
        $deployment = $target->containerDeployment;
        $env = is_array($deployment?->env_values) ? $deployment->env_values : [];
        $fromFile = $this->readContainerDbEnv($ssh, $containerPath);

        $credentials = [];
        if (is_string($target->credentials ?? null) && $target->credentials !== '') {
            $decoded = json_decode($target->credentials, true);
            if (is_array($decoded)) {
                $credentials = $decoded;
            }
        } elseif (is_array($target->credentials ?? null)) {
            $credentials = $target->credentials;
        }
        $dbCreds = is_array($credentials['database'] ?? null) ? $credentials['database'] : [];

        $database = (string) (
            $env['WORDPRESS_DB_NAME']
            ?? $env['MYSQL_DATABASE']
            ?? $fromFile['WORDPRESS_DB_NAME']
            ?? $fromFile['MYSQL_DATABASE']
            ?? $dbCreds['name']
            ?? 'wordpress'
        );
        $user = (string) (
            $env['WORDPRESS_DB_USER']
            ?? $env['MYSQL_USER']
            ?? $fromFile['WORDPRESS_DB_USER']
            ?? $fromFile['MYSQL_USER']
            ?? $dbCreds['username']
            ?? 'wordpress'
        );
        $password = (string) (
            $env['WORDPRESS_DB_PASSWORD']
            ?? $env['MYSQL_PASSWORD']
            ?? $fromFile['WORDPRESS_DB_PASSWORD']
            ?? $fromFile['MYSQL_PASSWORD']
            ?? $dbCreds['password']
            ?? ''
        );
        $rootPassword = (string) (
            $env['MYSQL_ROOT_PASSWORD']
            ?? $fromFile['MYSQL_ROOT_PASSWORD']
            ?? ''
        );

        return [
            'service' => $this->resolveDbServiceName($ssh, $containerPath),
            'database' => $database !== '' ? $database : 'wordpress',
            'user' => $user !== '' ? $user : 'wordpress',
            'password' => $password,
            'root_password' => $rootPassword,
        ];
    }

    public function waitForComposeMysql(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $password,
        int $timeoutSeconds = 180,
        string $user = 'root',
    ): void {
        $delaySeconds = 5;
        $maxAttempts = max(1, (int) ceil($timeoutSeconds / $delaySeconds));
        $pathArg = escapeshellarg($containerPath);
        $serviceArg = escapeshellarg($dbService);
        $pwdArg = escapeshellarg($password);
        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $user) ?: 'root';
        $userArg = escapeshellarg($safeUser);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                // Soft-start the sidecar if extract/IO knocked it over.
                if ($attempt > 0 && $attempt % 3 === 0) {
                    @$ssh->exec("cd {$pathArg} && docker compose start {$serviceArg} 2>/dev/null || true", 30);
                    sleep(3);
                }

                // Authenticate over the unix socket (not -h 127.0.0.1 / TCP).
                if ($password !== '') {
                    $ssh->exec(
                        "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$pwdArg} {$serviceArg}"
                        ." mysqladmin ping -u{$userArg} --silent",
                        20
                    );
                    $ssh->exec(
                        "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$pwdArg} {$serviceArg}"
                        ." mysql -u{$userArg} -e 'SELECT 1'",
                        20
                    );
                } else {
                    $ssh->exec(
                        "cd {$pathArg} && docker compose exec -T {$serviceArg} mysqladmin ping -u{$userArg} --silent",
                        20
                    );
                }

                return;
            } catch (\Throwable $e) {
                Log::debug('WordPress MySQL sidecar not ready yet', [
                    'attempt' => $attempt + 1,
                    'service' => $dbService,
                    'user' => $safeUser,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxAttempts - 1) {
                sleep($delaySeconds);
            }
        }

        throw new \RuntimeException(
            "MySQL sidecar \"{$dbService}\" did not become ready within {$timeoutSeconds} seconds."
        );
    }

    /**
     * Run a short SQL statement inside the compose MySQL service (unix socket).
     */
    public function execMysqlInCompose(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $user,
        string $password,
        string $sql,
        ?string $database = null,
        int $timeoutSeconds = 60,
    ): string {
        $command = 'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -T -e MYSQL_PWD='.escapeshellarg($password)
            .' '.escapeshellarg($dbService)
            .' mysql -u'.escapeshellarg($user);

        if ($database !== null && $database !== '') {
            $command .= ' '.escapeshellarg($database);
        }

        $command .= ' -e '.escapeshellarg($sql);

        return $ssh->exec($command, $timeoutSeconds);
    }

    /**
     * Stream a dump into MySQL via docker compose (host cat → container mysql stdin).
     * Avoids docker compose cp into the DB container and in-container shell redirects,
     * which are fragile after heavy disk IO during file extract.
     */
    public function buildMysqlDumpImportCommand(
        string $containerPath,
        string $dbService,
        string $dumpFile,
        string $user,
        string $password,
        string $database,
    ): string {
        return 'cd '.escapeshellarg($containerPath)
            .' && cat '.escapeshellarg($dumpFile)
            .' | docker compose exec -T -e MYSQL_PWD='.escapeshellarg($password)
            .' '.escapeshellarg($dbService)
            .' mysql -u'.escapeshellarg($user)
            .' '.escapeshellarg($database);
    }

    private function importMysqlDumpViaCompose(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $dumpFile,
        string $user,
        string $password,
        string $database,
    ): void {
        $command = $this->buildMysqlDumpImportCommand(
            $containerPath,
            $dbService,
            $dumpFile,
            $user,
            $password,
            $database,
        );

        $attempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->waitForComposeMysql(
                    $ssh,
                    $containerPath,
                    $dbService,
                    $password,
                    90,
                    $user,
                );
                $ssh->exec($command, 600);

                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('WordPress MySQL dump import attempt failed', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $attempts) {
                    @$ssh->exec(
                        'cd '.escapeshellarg($containerPath)
                        .' && docker compose start '.escapeshellarg($dbService).' 2>/dev/null || true',
                        30
                    );
                    sleep(5);
                }
            }
        }

        throw new \RuntimeException(
            'MySQL dump import failed after '.$attempts.' attempts: '.($lastError?->getMessage() ?? 'unknown error'),
            0,
            $lastError
        );
    }

    /**
     * @return array<string, string>
     */
    public function readLiveMysqlSidecarEnv(SSHService $ssh, string $containerPath, string $dbService): array
    {
        $raw = $ssh->exec(
            'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -T '.escapeshellarg($dbService)
            .' printenv 2>/dev/null || true'
        );

        $env = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (in_array($key, ['MYSQL_ROOT_PASSWORD', 'MYSQL_PASSWORD', 'MYSQL_USER', 'MYSQL_DATABASE'], true)) {
                $env[$key] = $value;
            }
        }

        return $env;
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

        try {
            $out = $ssh->exec('php -r '.escapeshellarg($php).' '.escapeshellarg($cfgPath).' 2>/dev/null || true');
            $creds = $this->decodeWpDatabaseCredentialLines($out);
            if (! blank($creds['DB_USER'] ?? null)) {
                return $creds;
            }
        } catch (\Throwable) {
            // Fall through to grep parser when php CLI is missing on the node.
        }

        return $this->parseWpDatabaseCredentialsViaGrep($ssh, $cfgPath);
    }

    /**
     * @return array{DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: string}
     */
    public function parseWpDatabaseCredentialsViaGrep(SSHService $ssh, string $cfgPath): array
    {
        $raw = $ssh->exec(
            'grep -E "define\\s*\\(\\s*[\'\\\"]DB_(NAME|USER|PASSWORD|HOST)[\'\\\"]" '
            .escapeshellarg($cfgPath).' 2>/dev/null || true'
        );

        $creds = [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ];

        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $key) {
            if (preg_match('/define\s*\(\s*[\'"]'.$key.'[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/s', $raw, $m)) {
                $creds[$key] = stripcslashes($m[2]);
            }
        }

        if (($creds['DB_HOST'] ?? null) === null || $creds['DB_HOST'] === '') {
            $creds['DB_HOST'] = 'localhost';
        }

        return $creds;
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
    public function writeRemoteMysqlDefaultsFile(SSHService $ssh, string $remotePath, array $creds): void
    {
        $user = (string) ($creds['DB_USER'] ?? '');
        $pass = (string) ($creds['DB_PASSWORD'] ?? '');
        $host = (string) ($creds['DB_HOST'] ?? 'localhost');

        $hostLine = 'host='.$host;
        $socketLine = '';
        $portLine = '';

        if (preg_match('#^([^:]+):(/[^:]+)$#', $host, $socketMatch)) {
            $hostLine = 'host='.$socketMatch[1];
            $socketLine = 'socket='.$socketMatch[2]."\n";
        } elseif (preg_match('/^(.+):(\d+)$/', $host, $portMatch)) {
            $hostLine = 'host='.$portMatch[1];
            $portLine = 'port='.$portMatch[2]."\n";
        }

        // Escape password for my.cnf: backslash and double-quote.
        $passEscaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $pass);

        $cnf = "[client]\n"
            ."user=\"{$user}\"\n"
            ."password=\"{$passEscaped}\"\n"
            ."{$hostLine}\n"
            .$portLine
            .$socketLine;

        $b64 = base64_encode($cnf);
        $ssh->exec(
            'umask 077; echo '.escapeshellarg($b64).' | base64 -d > '.escapeshellarg($remotePath)
            .' && chmod 600 '.escapeshellarg($remotePath)
        );
    }

    /**
     * @param  array{DB_NAME?: ?string, DB_USER?: ?string, DB_PASSWORD?: ?string, DB_HOST?: string}  $creds
     */
    public function buildMysqlDumpCommand(
        array $creds,
        string $dbName,
        string $dumpFile,
        ?string $defaultsExtraFile = null,
    ): string {
        $user = (string) ($creds['DB_USER'] ?? '');
        $pass = (string) ($creds['DB_PASSWORD'] ?? '');
        $host = (string) ($creds['DB_HOST'] ?? 'localhost');

        if ($user === '') {
            throw new \InvalidArgumentException('MySQL dump requires DB_USER.');
        }

        $parts = [];

        if ($defaultsExtraFile) {
            // Must be the first option or mysqldump treats it as an unknown variable.
            $parts[] = 'mysqldump';
            $parts[] = '--defaults-extra-file='.escapeshellarg($defaultsExtraFile);
            $parts[] = '--single-transaction';
            $parts[] = '--quick';
            $parts[] = '--no-tablespaces';
        } else {
            $parts[] = 'MYSQL_PWD='.escapeshellarg($pass);
            $parts[] = 'mysqldump';
            $parts[] = '--single-transaction';
            $parts[] = '--quick';
            $parts[] = '--no-tablespaces';

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
        }

        $parts[] = escapeshellarg($dbName);
        $parts[] = '>';
        $parts[] = escapeshellarg($dumpFile);

        return implode(' ', $parts);
    }

    /**
     * Archive WordPress docroot for migration.
     * GNU tar exits 1 when files change mid-read (common on live sites); treat that as OK if the archive exists.
     */
    public function buildWordPressFilesTarCommand(string $docroot, string $filesTar): string
    {
        $excludeArgs = [];
        foreach ([
            './wp-content/cache',
            './wp-content/upgrade',
            './wp-content/temp',
            './wp-content/tmp',
            './wp-content/uploads/cache',
            './wp-content/wflogs',
            './*.log',
        ] as $exclude) {
            $excludeArgs[] = '--exclude='.escapeshellarg($exclude);
        }

        $tar = 'tar -czf '.escapeshellarg($filesTar)
            .' '.implode(' ', $excludeArgs)
            .' -C '.escapeshellarg($docroot)
            .' .';

        // Remap exit status 1 → 0 when archive was written (files changed while reading).
        return $tar
            .' ; status=$?'
            .' ; if [ "$status" -eq 0 ] || [ "$status" -eq 1 ]; then'
            .'   if [ -s '.escapeshellarg($filesTar).' ]; then exit 0; fi'
            .' ; fi'
            .' ; exit "$status"';
    }

    /**
     * Extract a WordPress files archive onto the host bind mount used by the file manager.
     */
    public function buildWordPressHostExtractCommand(string $filesTar, string $hostAppPath): string
    {
        return 'mkdir -p '.escapeshellarg($hostAppPath)
            .' && tar -xzf '.escapeshellarg($filesTar).' -C '.escapeshellarg($hostAppPath)
            .' ; status=$?'
            .' ; if [ "$status" -eq 0 ] || [ "$status" -eq 1 ]; then'
            .'   if [ -f '.escapeshellarg(rtrim($hostAppPath, '/').'/wp-config.php')
            .' ] || [ -d '.escapeshellarg(rtrim($hostAppPath, '/').'/wp-content').' ]; then exit 0; fi'
            .' ; fi'
            .' ; exit "$status"';
    }

    /**
     * Make bind-mounted WordPress files readable/writable by Apache (www-data / uid 33).
     *
     * DA archives often keep wp-config.php as mode 600 under a DA user UID; that causes
     * HTTP 500 once Apache in the official WordPress image cannot read the config.
     */
    public function buildWordPressPermissionsCommand(string $hostAppPath): string
    {
        $path = escapeshellarg(rtrim($hostAppPath, '/'));

        return 'if [ -d '.$path.' ]; then'
            .'  chown -R 33:33 '.$path
            // chmod -R is far faster than find -exec on multi-GB WordPress trees.
            .'  && chmod -R u+rwX,g+rX,o+rX '.$path
            .'  && if [ -f '.$path.'/wp-config.php ]; then chmod 640 '.$path.'/wp-config.php; fi'
            .'  && if [ -d '.$path.'/wp-content ]; then chmod -R ug+rwX '.$path.'/wp-content; fi'
            .'  ; fi';
    }

    /**
     * Neutralize cPanel/DA PHP overrides that break sessions inside the container.
     *
     * Migrated sites often ship .user.ini / php.ini / .htaccess with session.save_path
     * under /var/cpanel/... which does not exist in App Hosting containers.
     */
    public function buildWordPressRuntimeSanitizeCommand(string $hostAppPath): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));

        return 'root='.$root.'; '
            .'if [ ! -d "$root" ]; then exit 0; fi; '
            .'mkdir -p "$root/wp-content/uploads/sessions"; '
            .'chmod 775 "$root/wp-content/uploads/sessions" 2>/dev/null || true; '
            // Drop stale EasyApache / cPanel / DA session paths from common override files.
            .'for f in "$root/.user.ini" "$root/php.ini" "$root/wp-content/.user.ini"; do '
            .'  if [ -f "$f" ]; then '
            .'    sed -i'
            .' -e "/session\\.save_path/Id"'
            .' -e "/open_basedir/Id"'
            .' -e "/upload_tmp_dir/Id"'
            .' -e "/error_log/Id"'
            .' "$f" 2>/dev/null || true; '
            .'  fi; '
            .'done; '
            .'if [ -f "$root/.htaccess" ]; then '
            .'  sed -i'
            .' -e "/php_value[[:space:]]\\+session\\.save_path/Id"'
            .' -e "/php_admin_value[[:space:]]\\+session\\.save_path/Id"'
            .' -e "/php_value[[:space:]]\\+open_basedir/Id"'
            .' "$root/.htaccess" 2>/dev/null || true; '
            .'fi; '
            // Ensure a container-local session path for plugins that call session_start().
            // Path must be the in-container mount path, not the host bind path.
            .'printf "%s\\n" \'session.save_path = "/var/www/html/wp-content/uploads/sessions"\' > "$root/.user.ini"; '
            .'chmod 644 "$root/.user.ini" 2>/dev/null || true';
    }

    private function sanitizeWordPressHostRuntimeConfig(SSHService $ssh, string $hostAppPath): void
    {
        $ssh->exec($this->buildWordPressRuntimeSanitizeCommand($hostAppPath), 60);
    }

    /**
     * Teach WordPress that HTTPS terminated at nginx is still HTTPS (avoids redirect loops).
     */
    private function ensureWordPressProxyHttpsAwareness(
        SSHService $ssh,
        string $containerPath,
        string $appService
    ): void {
        $snippet = <<<'SNIP'
/* TALKASA_PROXY_HTTPS */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
$sessionDir = '/var/www/html/wp-content/uploads/sessions';
if (is_dir($sessionDir) || @mkdir($sessionDir, 0775, true)) {
    @ini_set('session.save_path', $sessionDir);
} else {
    @ini_set('session.save_path', '/tmp');
}

SNIP;

        $php = '$cfg = \'/var/www/html/wp-config.php\';'
            .' if (! is_file($cfg)) { fwrite(STDERR, "wp-config.php missing\\n"); exit(1); }'
            .' $text = file_get_contents($cfg);'
            .' if (str_contains($text, \'TALKASA_PROXY_HTTPS\')) { exit(0); }'
            .' $snippet = '.var_export($snippet, true).';'
            .' if (preg_match(\'/<\?php\\b/\', $text)) {'
            .'   $text = preg_replace(\'/<\?php\\b/\', "<?php\\n".$snippet, $text, 1);'
            .' } else {'
            .'   $text = "<?php\\n".$snippet.$text;'
            .' }'
            .' file_put_contents($cfg, $text);';

        $ssh->exec(
            "cd {$containerPath} && docker compose exec -T {$appService} php -r ".escapeshellarg($php),
            60
        );
    }

    private function normalizeWordPressAppPermissions(
        SSHService $ssh,
        string $hostAppPath,
        string $containerPath,
        string $appService
    ): void {
        try {
            $ssh->exec($this->buildWordPressPermissionsCommand($hostAppPath), 300);
        } catch (\Throwable $e) {
            // Fallback: chown inside the container (same bind mount) as root.
            $ssh->exec(
                "cd {$containerPath} && docker compose exec -u 0 -T {$appService} sh -c "
                .escapeshellarg(
                    'chown -R www-data:www-data /var/www/html'
                    .' && find /var/www/html -type d -exec chmod 755 {} +'
                    .' && find /var/www/html -type f -exec chmod 644 {} +'
                    .' && chmod 640 /var/www/html/wp-config.php 2>/dev/null || true'
                    .' && find /var/www/html/wp-content -type d -exec chmod 775 {} + 2>/dev/null || true'
                    .' && find /var/www/html/wp-content -type f -exec chmod 664 {} + 2>/dev/null || true'
                ),
                300
            );
            Log::warning('Host WordPress permission normalize failed; applied via container', [
                'error' => $e->getMessage(),
                'host_app_path' => $hostAppPath,
            ]);
        }
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
if (! is_file(\$cfg)) {
    fwrite(STDERR, "wp-config.php missing at {\$cfg}\\n");
    exit(1);
}
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
