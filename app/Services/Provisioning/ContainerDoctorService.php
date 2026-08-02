<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerDoctorService
{
    public const LOG_LINES = 2000;

    /**
     * @return array{
     *     scanned_at: string,
     *     lines_scanned: int,
     *     stack: string,
     *     findings: list<array<string, mixed>>,
     *     healthy: bool
     * }
     */
    public function diagnose(Service $service): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');
        $deployment = $service->containerDeployment;
        $stack = strtolower((string) ($service->product?->containerTemplate?->slug ?? 'unknown'));

        if (! $deployment || ! $deployment->node) {
            return [
                'scanned_at' => now()->toIso8601String(),
                'lines_scanned' => 0,
                'stack' => $stack,
                'findings' => [[
                    'id' => 'no_deployment',
                    'severity' => 'critical',
                    'title' => 'Application is not deployed',
                    'summary' => 'No running deployment was found for this service.',
                    'evidence' => [],
                    'treat_action' => null,
                    'treat_label' => null,
                    'manual_steps' => ['Wait for provisioning to finish, or redeploy the stack from Overview.'],
                ]],
                'healthy' => false,
            ];
        }

        $logs = app(ContainerDeploymentService::class)->getLogs($service, self::LOG_LINES);
        $findings = $this->analyzeLogs($logs, $stack, $service);
        $findings = $this->annotateFindingsWithLiveStatus($service, $findings);

        return [
            'scanned_at' => now()->toIso8601String(),
            'lines_scanned' => $this->countLines($logs),
            'stack' => $stack,
            'findings' => $findings,
            'healthy' => $findings === [] || collect($findings)->every(
                fn ($f) => in_array(($f['severity'] ?? ''), ['info'], true)
            ),
        ];
    }

    /**
     * @return array{success: bool, message: string, diagnosis?: array<string, mixed>}
     */
    public function treat(Service $service, string $action): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');
        $deployment = $service->containerDeployment;

        if (! $deployment || ! $deployment->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        if (! $deployment->isRunning()) {
            return ['success' => false, 'message' => 'Application must be running before applying a fix.'];
        }

        $result = match ($action) {
            'sync_database_credentials' => $this->treatSyncDatabaseCredentials($service),
            'ensure_pdo_pgsql' => $this->treatEnsurePdoPgsql($service),
            'ensure_gd' => $this->treatEnsureGd($service),
            'ensure_node' => $this->treatEnsureNode($service),
            'clear_laravel_caches' => $this->treatClearLaravelCaches($service),
            'fix_storage_permissions' => $this->treatFixStoragePermissions($service),
            'restart_application' => $this->treatRestartApplication($service),
            default => ['success' => false, 'message' => 'Unknown treatment action.'],
        };

        if ($result['success']) {
            try {
                $result['diagnosis'] = $this->diagnose($service->fresh([
                    'product.containerTemplate',
                    'containerDeployment.node',
                ]));
            } catch (\Throwable) {
                // Treatment already succeeded; diagnosis refresh is optional.
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function analyzeLogs(string $logs, string $stack, ?Service $service = null): array
    {
        $normalized = $logs;
        $findings = [];

        foreach ($this->rules() as $rule) {
            $stacks = $rule['stacks'] ?? null;
            if (is_array($stacks) && $stacks !== [] && ! in_array($stack, $stacks, true) && ! in_array('*', $stacks, true)) {
                continue;
            }

            $evidence = [];
            foreach ($rule['patterns'] as $pattern) {
                if (preg_match_all($pattern, $normalized, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        $line = trim((string) ($match[0] ?? ''));
                        if ($line !== '') {
                            $evidence[] = mb_substr($line, 0, 240);
                        }
                    }
                }
            }

            if ($evidence === []) {
                continue;
            }

            $evidence = array_values(array_unique($evidence));
            $evidence = array_slice($evidence, 0, 5);

            $findings[] = [
                'id' => $rule['id'],
                'severity' => $rule['severity'],
                'title' => $rule['title'],
                'summary' => $rule['summary'],
                'evidence' => $evidence,
                'treat_action' => $rule['treat_action'],
                'treat_label' => $rule['treat_label'],
                'manual_steps' => $rule['manual_steps'],
            ];
        }

        if ($findings === [] && trim($logs) !== '' && ! str_starts_with(trim($logs), 'Error fetching logs:')) {
            $hasHttp500 = preg_match('/\[500\]:\s*GET\s+\//i', $logs) === 1;
            if ($hasHttp500) {
                $findings[] = [
                    'id' => 'http_500_generic',
                    'severity' => 'warning',
                    'title' => 'HTTP 500 on homepage',
                    'summary' => 'The app returned 500 for GET /, but no specific known signature matched. Check Laravel storage/logs or enable APP_DEBUG temporarily.',
                    'evidence' => $this->extractMatchingLines($logs, '/\[500\]:\s*GET\s+\//i', 3),
                    'treat_action' => in_array($stack, ['laravel', 'php'], true) ? 'clear_laravel_caches' : null,
                    'treat_label' => in_array($stack, ['laravel', 'php'], true) ? 'Clear Laravel caches' : null,
                    'manual_steps' => [
                        'Open Terminal and run: tail -n 80 storage/logs/laravel.log (from the app root).',
                        'Confirm DB_* values in Environment match the Database tab.',
                        'Retry after clearing config/route/view caches.',
                    ],
                ];
            }
        }

        usort($findings, function (array $a, array $b): int {
            $order = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
        });

        return $findings;
    }

    /**
     * Downgrade log matches that look fixed in the live deployment env
     * (old FATAL lines often remain in the last 2000 log lines).
     *
     * @param  list<array<string, mixed>>  $findings
     * @return list<array<string, mixed>>
     */
    public function annotateFindingsWithLiveStatus(Service $service, array $findings): array
    {
        $service->loadMissing('containerDeployment');
        $deployment = $service->containerDeployment;
        if (! $deployment || $findings === []) {
            return $findings;
        }

        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $canonical = app(ContainerDeploymentService::class)->canonicalDatabaseIdentifiers($service);
        $database = (string) ($env['DB_DATABASE'] ?? $env['POSTGRES_DB'] ?? $env['MYSQL_DATABASE'] ?? '');
        $username = (string) ($env['DB_USERNAME'] ?? $env['POSTGRES_USER'] ?? $env['MYSQL_USER'] ?? '');
        $dbPassword = (string) ($env['DB_PASSWORD'] ?? '');
        $sidecarPassword = (string) ($env['POSTGRES_PASSWORD'] ?? $env['MYSQL_PASSWORD'] ?? '');
        $urlPassword = null;
        if (! empty($env['DATABASE_URL']) && is_string($env['DATABASE_URL'])) {
            $parts = parse_url($env['DATABASE_URL']);
            if (is_array($parts) && isset($parts['pass'])) {
                $urlPassword = rawurldecode((string) $parts['pass']);
            }
        }

        $databaseLooksHealthy = $database !== ''
            && $database === $canonical['database']
            && $database !== $username
            && ! preg_match('/^u\d+_s\d+$/', $database);

        $passwordsAligned = $dbPassword !== ''
            && ($sidecarPassword === '' || $sidecarPassword === $dbPassword)
            && ($urlPassword === null || $urlPassword === '' || $urlPassword === $dbPassword);

        foreach ($findings as &$finding) {
            $id = (string) ($finding['id'] ?? '');

            if ($id === 'postgres_database_missing' && $databaseLooksHealthy) {
                $finding['severity'] = 'info';
                $finding['stale'] = true;
                $finding['title'] = 'Older logs: missing database (current env looks fixed)';
                $finding['summary'] = 'Logs still mention a missing database, but live DB_DATABASE is already "'
                    .$database.'". Treatment may have worked; reload the app or wait for newer log lines. '
                    .'You can still re-sync credentials if errors continue.';
                $finding['treat_label'] = 'Re-sync credentials';
            }

            if (in_array($id, ['postgres_password_auth_failed', 'mysql_access_denied'], true)
                && $databaseLooksHealthy
                && $passwordsAligned
            ) {
                $finding['severity'] = 'info';
                $finding['stale'] = true;
                $finding['title'] = 'Older logs: database auth failure (current env looks aligned)';
                $finding['summary'] = 'Logs still show auth failures, but DB name/password fields in the live environment look consistent. '
                    .'Reload the app or re-sync if new errors appear.';
                $finding['treat_label'] = 'Re-sync credentials';
            }
        }
        unset($finding);

        usort($findings, function (array $a, array $b): int {
            $order = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
        });

        return $findings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rules(): array
    {
        return [
            [
                'id' => 'postgres_password_auth_failed',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php', 'nodejs', 'python', 'ruby', '*'],
                'patterns' => [
                    '/password authentication failed for user/i',
                    '/FATAL:.*password authentication failed/i',
                ],
                'title' => 'PostgreSQL password mismatch',
                'summary' => 'The app password does not match the password stored in the Postgres volume. This commonly happens after redeploy without resetting the database volume.',
                'treat_action' => 'sync_database_credentials',
                'treat_label' => 'Repair DB credentials',
                'manual_steps' => [
                    'Click Repair DB credentials to reset the database user password to match Environment.',
                    'If it still fails, Redeploy stack with Reset database (wipes DB data).',
                    'Confirm DB_DATABASE is s{service}_db (not the username).',
                ],
            ],
            [
                'id' => 'postgres_database_missing',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php', 'nodejs', 'python', 'ruby', '*'],
                'patterns' => [
                    '/FATAL:\s+database ".*" does not exist/i',
                    '/database ".*" does not exist/i',
                ],
                'title' => 'PostgreSQL database does not exist',
                'summary' => 'Postgres accepted a connection but the configured database name is missing. Often DB_DATABASE was set to the username instead of s{id}_db.',
                'treat_action' => 'sync_database_credentials',
                'treat_label' => 'Create/sync database',
                'manual_steps' => [
                    'Click Create/sync database — Doctor will rename DB_DATABASE to s{id}_db if it was set to the username, create the database, and rewrite .env.',
                    'Then run: php artisan migrate --force',
                    'If it still fails, Redeploy stack with Reset database (wipes DB data).',
                ],
            ],
            [
                'id' => 'mysql_access_denied',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php', 'wordpress', '*'],
                'patterns' => [
                    '/Access denied for user/i',
                    '/SQLSTATE\[HY000\]\s*\[\d+\]\s*Access denied/i',
                ],
                'title' => 'MySQL access denied',
                'summary' => 'MySQL rejected the username/password. The sidecar volume may still have older credentials.',
                'treat_action' => 'sync_database_credentials',
                'treat_label' => 'Repair DB credentials',
                'manual_steps' => [
                    'Repair DB credentials from Doctor.',
                    'If unresolved, Redeploy with Reset database.',
                ],
            ],
            [
                'id' => 'missing_pdo_pgsql',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/missing_pdo_pgsql/i',
                    '/could not find driver/i',
                    '/could not find driver.*pgsql/i',
                    '/PDOException.*could not find driver/i',
                ],
                'title' => 'Missing PHP PostgreSQL driver (pdo_pgsql)',
                'summary' => 'PHP cannot talk to Postgres because the pdo_pgsql extension is not enabled in this container.',
                'treat_action' => 'ensure_pdo_pgsql',
                'treat_label' => 'Install pdo_pgsql',
                'manual_steps' => [
                    'Install pdo_pgsql, then restart the app if needed.',
                    'Or Redeploy stack to rebuild the runtime image.',
                ],
            ],
            [
                'id' => 'missing_ext_gd',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/ext-gd/i',
                    '/requires ext-gd/i',
                    '/gd extension/i',
                    '/Call to undefined function\s+imagecreate/i',
                ],
                'title' => 'Missing PHP GD extension',
                'summary' => 'Composer or the app needs the GD image extension (common with PhpSpreadsheet / image uploads).',
                'treat_action' => 'ensure_gd',
                'treat_label' => 'Install GD',
                'manual_steps' => [
                    'Install GD from Doctor, then retry composer install / the failing request.',
                ],
            ],
            [
                'id' => 'node_not_found',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php', 'nodejs'],
                'patterns' => [
                    '/sh:\s+\d+:\s+.*\beval:\s+node:\s+not found/i',
                    '/node:\s+not found/i',
                    '/npm:\s+not found/i',
                    '/exec: node: not found/i',
                ],
                'title' => 'Node.js / npm not available',
                'summary' => 'Frontend tooling (Vite/Next) needs Node in the app container.',
                'treat_action' => 'ensure_node',
                'treat_label' => 'Install Node.js',
                'manual_steps' => [
                    'Install Node from Doctor, or Redeploy to pick up the Node-enabled runtime image.',
                ],
            ],
            [
                'id' => 'artisan_production_cancelled',
                'severity' => 'info',
                'stacks' => ['laravel'],
                'patterns' => [
                    '/APPLICATION IN PRODUCTION[\s\S]{0,200}Command cancelled/i',
                    '/Command cancelled[\s\S]{0,200}APPLICATION IN PRODUCTION/i',
                ],
                'title' => 'Artisan blocked in production mode',
                'summary' => 'Laravel cancelled a destructive artisan command because APP_ENV=production and --force was not used.',
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'Run: php artisan migrate --force',
                    'Newer terminal sessions auto-add --force for migrate/seed/wipe.',
                ],
            ],
            [
                'id' => 'storage_permission_denied',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/Permission denied.*storage/i',
                    '/Failed to open stream: Permission denied/i',
                    '/Please provide a valid cache path/i',
                ],
                'title' => 'Storage / cache permission problem',
                'summary' => 'Laravel cannot write to storage or bootstrap/cache.',
                'treat_action' => 'fix_storage_permissions',
                'treat_label' => 'Fix storage permissions',
                'manual_steps' => [
                    'Fix permissions, then: php artisan cache:clear',
                ],
            ],
            [
                'id' => 'app_key_missing',
                'severity' => 'critical',
                'stacks' => ['laravel'],
                'patterns' => [
                    '/No application encryption key/i',
                    '/MissingAppKeyException/i',
                ],
                'title' => 'Missing APP_KEY',
                'summary' => 'Laravel requires APP_KEY for encryption/sessions.',
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'In Terminal: php artisan key:generate --force',
                    'Or set APP_KEY in the Environment tab, then clear config cache.',
                ],
            ],
            [
                'id' => 'composer_platform_reqs',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/Your lock file does not contain a compatible set of packages/i',
                    '/the requested PHP extension/i',
                ],
                'title' => 'Composer platform requirements failed',
                'summary' => 'composer install failed because a PHP extension or platform package is missing.',
                'treat_action' => 'ensure_gd',
                'treat_label' => 'Install common PHP extensions (GD)',
                'manual_steps' => [
                    'Install missing extensions from the PHP Extensions tab.',
                    'Retry Git pull / composer install.',
                ],
            ],
            [
                'id' => 'oom_killed',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => [
                    '/oom-kill/i',
                    '/Out of memory/i',
                    '/Cannot allocate memory/i',
                ],
                'title' => 'Possible out-of-memory kill',
                'summary' => 'The process may have been killed because the plan ran out of RAM.',
                'treat_action' => 'restart_application',
                'treat_label' => 'Restart application',
                'manual_steps' => [
                    'Restart the app, then consider upgrading the plan if OOM continues.',
                    'Avoid heavy builds (npm/composer) concurrent with traffic on small plans.',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function extractMatchingLines(string $logs, string $pattern, int $limit = 3): array
    {
        $lines = [];
        if (preg_match_all($pattern, $logs, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $lines[] = mb_substr(trim($match), 0, 240);
            }
        }

        return array_slice(array_values(array_unique($lines)), 0, $limit);
    }

    private function countLines(string $logs): int
    {
        if (trim($logs) === '') {
            return 0;
        }

        return count(preg_split("/\r\n|\n|\r/", $logs) ?: []);
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatSyncDatabaseCredentials(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $deploymentService = app(ContainerDeploymentService::class);
        $databaseTemplate = $deploymentService->resolveDatabaseTemplateForService($service);

        if (! $databaseTemplate) {
            return ['success' => false, 'message' => 'No database sidecar is configured for this service.'];
        }

        try {
            $rawEnv = is_array($deployment->env_values) ? $deployment->env_values : [];
            $normalized = $deploymentService->normalizeDatabaseEnvironment(
                $service,
                $rawEnv,
                (string) $databaseTemplate->type
            );
            $envVars = $normalized['env'];

            if ($normalized['corrected'] || $envVars !== $rawEnv) {
                $deployment->update(['env_values' => $envVars]);
                $meta = is_array($service->service_meta) ? $service->service_meta : [];
                $meta['env_values'] = $envVars;
                $service->update(['service_meta' => $meta]);
                $deployment->refresh();
                $service->refresh();
            }

            match ($databaseTemplate->type) {
                'mysql', 'mariadb' => $deploymentService
                    ->syncMysqlSidecarCredentials($ssh, $containerPath, $envVars),
                'postgresql' => $deploymentService
                    ->syncPostgresqlSidecarCredentials($ssh, $containerPath, $envVars),
                'mongodb' => $deploymentService
                    ->syncMongodbSidecarCredentials($ssh, $containerPath, $envVars),
                default => throw new \RuntimeException('Unsupported database type: '.$databaseTemplate->type),
            };

            $stack = strtolower((string) ($service->product?->containerTemplate?->slug ?? ''));
            if (in_array($stack, ['laravel', 'php'], true)) {
                try {
                    app(LaravelAppInitializationService::class)
                        ->writeApplicationEnvironment($service, $deployment, $ssh, preserveExisting: true);
                } catch (\Throwable $e) {
                    app(ContainerEnvironmentService::class)
                        ->syncDotEnvFile($ssh, $service, $deployment, $envVars);
                    \Log::warning('Doctor fell back to .env merge after Laravel env write failed', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $this->clearLaravelCachesQuietly($service, $ssh);
                } catch (\Throwable) {
                    // Cache clear is best-effort after credential repair.
                }
            } else {
                app(ContainerEnvironmentService::class)
                    ->syncDotEnvFile($ssh, $service, $deployment, $envVars);
            }

            $message = 'Database "'.$normalized['database'].'" is ready and app credentials were synced.';
            if ($normalized['corrected'] && $normalized['previous_database'] && $normalized['previous_database'] !== $normalized['database']) {
                $message = 'Fixed DB_DATABASE from "'.$normalized['previous_database'].'" to "'
                    .$normalized['database'].'". '.$message;
            }
            if (! empty($normalized['password_aligned'])) {
                $message .= ' Aligned DB_PASSWORD, sidecar password, and DATABASE_URL to the same value.';
            }
            $message .= ' Reload the site; if tables are missing run: php artisan migrate --force';

            return [
                'success' => true,
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Could not repair credentials: '.$e->getMessage(),
            ];
        } finally {
            $ssh->disconnect();
        }
    }

    private function clearLaravelCachesQuietly(Service $service, SSHService $ssh): void
    {
        $deployment = $service->containerDeployment;
        $init = app(LaravelAppInitializationService::class);

        $locator = 'if [ -f /app/backend/artisan ]; then echo /app/backend; '
            .'elif [ -f /app/artisan ]; then echo /app; '
            .'else echo /app; fi';
        $projectRoot = trim($ssh->exec(
            'docker exec -u www-data '.escapeshellarg($deployment->container_name)
            .' sh -lc '.escapeshellarg($locator),
            15
        )) ?: '/app';

        $script = 'set -e; cd '.escapeshellarg($projectRoot).'; '
            .'php artisan optimize:clear --no-interaction 2>/dev/null '
            .'|| (php artisan config:clear --no-interaction; php artisan cache:clear --no-interaction)';

        $init->dockerExecPublic($ssh, $deployment->container_name, $script, 120);
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatEnsurePdoPgsql(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);

        try {
            app(LaravelAppInitializationService::class)->ensurePostgresqlPdoDriver($ssh, $deployment);

            return ['success' => true, 'message' => 'pdo_pgsql is installed. Retry the failing request.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to install pdo_pgsql: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatEnsureGd(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);

        try {
            app(ContainerPhpExtensionsService::class)->ensureExtensionInstalled($ssh, $deployment, 'gd');

            return ['success' => true, 'message' => 'PHP GD extension installed.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to install GD: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatEnsureNode(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);

        try {
            app(LaravelAppInitializationService::class)->ensureNodeRuntime($ssh, $deployment);

            return ['success' => true, 'message' => 'Node.js/npm are available in the container.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to install Node.js: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatClearLaravelCaches(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);
        $init = app(LaravelAppInitializationService::class);

        try {
            $locator = 'if [ -f /app/backend/artisan ]; then echo /app/backend; '
                .'elif [ -f /app/artisan ]; then echo /app; '
                .'else echo /app; fi';
            $projectRoot = trim($ssh->exec(
                'docker exec -u www-data '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg($locator),
                15
            )) ?: '/app';

            $script = 'set -e; cd '.escapeshellarg($projectRoot).'; '
                .'php artisan optimize:clear --no-interaction 2>/dev/null '
                .'|| (php artisan config:clear --no-interaction; php artisan cache:clear --no-interaction; php artisan route:clear --no-interaction; php artisan view:clear --no-interaction)';

            $init->dockerExecPublic($ssh, $deployment->container_name, $script, 120);

            return ['success' => true, 'message' => 'Laravel caches cleared.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to clear caches: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatFixStoragePermissions(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);

        try {
            app(ContainerAppDirectoryService::class)->normalizePermissions($ssh, $deployment);

            return ['success' => true, 'message' => 'Storage permissions refreshed.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to fix permissions: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatRestartApplication(Service $service): array
    {
        try {
            app(ContainerDeploymentService::class)->restart($service);

            return ['success' => true, 'message' => 'Application restart requested.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Restart failed: '.$e->getMessage()];
        }
    }
}
