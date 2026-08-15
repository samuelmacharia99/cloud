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
        $stack = strtolower((string) ($service->effectiveContainerTemplate()?->slug ?? 'unknown'));

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

        $live = $this->collectLiveFindings($service);
        $findings = $this->mergeLogAndLiveFindings($findings, $live);

        return [
            'scanned_at' => now()->toIso8601String(),
            'lines_scanned' => $this->countLines($logs),
            'stack' => $stack,
            'findings' => $findings,
            'live_checks' => $live['checks'] ?? [],
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
            'fix_npm_cache_permissions' => $this->treatFixNpmCachePermissions($service),
            'clear_laravel_caches' => $this->treatClearLaravelCaches($service),
            'fix_storage_permissions' => $this->treatFixStoragePermissions($service),
            'fix_wordpress_permissions' => $this->treatFixWordPressPermissions($service),
            'restart_application' => $this->treatRestartApplication($service),
            'run_migrations' => $this->treatRunMigrations($service),
            'migrate_fresh' => $this->treatMigrateFresh($service),
            'use_file_cache' => $this->treatUseFileCache($service),
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
     * Live probes against on-disk .env, PDO connectivity, HTTP status, and empty schema.
     *
     * @return array{findings: list<array<string, mixed>>, checks: array<string, mixed>}
     */
    public function collectLiveFindings(Service $service): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');
        $deployment = $service->containerDeployment;
        $checks = [
            'env_source' => null,
            'http_status' => null,
            'db_ok' => null,
            'db_error' => null,
            'table_count' => null,
            'sidecar_frontend' => null,
            'sidecar_edge' => null,
            'api_http_status' => null,
        ];
        $findings = [];

        if (! $deployment?->node || ! $deployment->isRunning()) {
            return ['findings' => $findings, 'checks' => $checks];
        }

        $deploymentService = app(ContainerDeploymentService::class);
        $databaseTemplate = $deploymentService->resolveDatabaseTemplateForService($service);
        $stack = strtolower((string) (
            $service->effectiveContainerTemplate()?->slug
            ?? $service->product?->containerTemplate?->slug
            ?? ''
        ));
        $ssh = SSHService::forNode($deployment->node);

        try {
            if ($stack === 'wordpress') {
                try {
                    $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
                    $writeProbe = trim($ssh->exec(
                        'cd '.escapeshellarg($containerPath)
                        .' && docker compose exec -u www-data -T '.escapeshellarg($deployment->container_name)
                        .' sh -lc '.escapeshellarg(
                            'touch /var/www/html/wp-content/uploads/.talksasa-write-test'
                            .' && rm -f /var/www/html/wp-content/uploads/.talksasa-write-test'
                            .' && touch /var/www/html/wp-content/plugins/.talksasa-write-test'
                            .' && rm -f /var/www/html/wp-content/plugins/.talksasa-write-test'
                            .' && echo ok'
                        ),
                        30
                    ));
                    $checks['wordpress_writable'] = $writeProbe === 'ok';
                    if ($writeProbe !== 'ok') {
                        $findings[] = [
                            'id' => 'live_wordpress_not_writable',
                            'severity' => 'critical',
                            'title' => 'WordPress cannot write uploads or plugins',
                            'summary' => 'www-data cannot write under wp-content. Media uploads and plugin installs will fail until ownership is fixed.',
                            'evidence' => [mb_substr($writeProbe !== '' ? $writeProbe : 'write probe failed', 0, 300)],
                            'treat_action' => 'fix_wordpress_permissions',
                            'treat_label' => 'Fix WordPress permissions',
                            'manual_steps' => [
                                'Click Fix WordPress permissions, then retry the media upload or plugin install.',
                            ],
                            'source' => 'live',
                        ];
                    }
                } catch (\Throwable $e) {
                    $checks['wordpress_writable'] = false;
                    $findings[] = [
                        'id' => 'live_wordpress_not_writable',
                        'severity' => 'critical',
                        'title' => 'WordPress cannot write uploads or plugins',
                        'summary' => 'www-data cannot write under wp-content. Media uploads and plugin installs will fail until ownership is fixed.',
                        'evidence' => [mb_substr($e->getMessage(), 0, 300)],
                        'treat_action' => 'fix_wordpress_permissions',
                        'treat_label' => 'Fix WordPress permissions',
                        'manual_steps' => [
                            'Click Fix WordPress permissions, then retry the media upload or plugin install.',
                        ],
                        'source' => 'live',
                    ];
                }
            }

            if ($deploymentService->usesLaravelNextSidecarStack($deployment)) {
                $frontendName = LaravelNextGatewayProxy::frontendContainerName($deployment->container_name);
                $edgeName = LaravelNextGatewayProxy::edgeContainerName($deployment->container_name);
                $frontendRunning = trim($ssh->exec(
                    'docker inspect -f "{{.State.Running}}" '.escapeshellarg($frontendName).' 2>/dev/null || echo false',
                    15
                )) === 'true';
                $edgeRunning = trim($ssh->exec(
                    'docker inspect -f "{{.State.Running}}" '.escapeshellarg($edgeName).' 2>/dev/null || echo false',
                    15
                )) === 'true';
                $checks['sidecar_frontend'] = $frontendRunning;
                $checks['sidecar_edge'] = $edgeRunning;

                if (! $frontendRunning || ! $edgeRunning) {
                    $findings[] = [
                        'id' => 'live_next_sidecar_down',
                        'severity' => 'critical',
                        'title' => 'Next.js sidecar stack is incomplete',
                        'summary' => 'This app uses separate frontend/edge containers. '
                            .(! $frontendRunning ? 'Frontend is not running. ' : '')
                            .(! $edgeRunning ? 'Edge router is not running. ' : '')
                            .'Redeploy or restart the stack so edge → frontend/backend routing works.',
                        'evidence' => array_filter([
                            $frontendRunning ? null : 'frontend container stopped: '.$frontendName,
                            $edgeRunning ? null : 'edge container stopped: '.$edgeName,
                        ]),
                        'treat_action' => null,
                        'treat_label' => null,
                        'manual_steps' => [
                            'Open Overview → Restart, or Redeploy stack (keep database).',
                            'Confirm docker compose ps shows backend, frontend, edge, and db.',
                        ],
                        'source' => 'live',
                    ];
                } else {
                    $apiUrl = rtrim((string) ($deployment->getAccessUrl() ?? ''), '/').'/api/v1/app/branding';
                    if (str_starts_with($apiUrl, 'http')) {
                        $apiCode = trim($ssh->exec(
                            'curl -s -o /dev/null -w "%{http_code}" --max-time 12 '.escapeshellarg($apiUrl).' || true',
                            20
                        ));
                        if (preg_match('/^\d{3}$/', $apiCode) === 1) {
                            $checks['api_http_status'] = (int) $apiCode;
                            if ((int) $apiCode >= 500) {
                                $findings[] = [
                                    'id' => 'live_api_via_edge_failed',
                                    'severity' => 'warning',
                                    'title' => 'API via edge returned HTTP '.$apiCode,
                                    'summary' => 'Public /api traffic through the edge router is failing. Check Laravel logs on the backend container.',
                                    'evidence' => ['GET '.$apiUrl.' → '.$apiCode],
                                    'treat_action' => null,
                                    'treat_label' => null,
                                    'manual_steps' => ['Inspect backend logs and DATABASE_URL / APP_KEY.'],
                                    'source' => 'live',
                                ];
                            }
                        }
                    }
                }
            }

            $platformEnv = is_array($deployment->env_values) ? $deployment->env_values : [];
            $liveEnv = $this->readLiveAppEnvironment($ssh, $deployment);
            $checks['env_source'] = $liveEnv === [] ? 'platform' : 'app_dotenv';
            $mergedEnv = $liveEnv === [] ? $platformEnv : array_merge($platformEnv, $liveEnv);

            if ($databaseTemplate) {
                $probeEnv = $this->envForRuntimeDatabaseProbe($mergedEnv, (string) $databaseTemplate->type);
                $probe = $deploymentService->probeApplicationDatabaseAccess(
                    $ssh,
                    $deployment->container_name,
                    (string) $databaseTemplate->type,
                    $probeEnv
                );
                $checks['db_ok'] = $probe['ok'];
                $checks['db_error'] = $probe['error'];

                if (! $probe['ok']) {
                    $normalized = $deploymentService->normalizeDatabaseEnvironment(
                        $service,
                        $mergedEnv,
                        (string) $databaseTemplate->type
                    );

                    $details = [];
                    if ($normalized['corrected'] && $normalized['previous_database'] !== $normalized['database']) {
                        $details[] = 'DB_DATABASE is "'.($normalized['previous_database'] ?? '').'" but should be "'.$normalized['database'].'"';
                    }
                    if (! empty($normalized['password_aligned'])) {
                        $details[] = 'DB_PASSWORD, POSTGRES_PASSWORD/MYSQL_PASSWORD, and DATABASE_URL are not the same password';
                    }

                    if ($details !== []) {
                        $findings[] = [
                            'id' => 'live_env_credential_drift',
                            'severity' => 'critical',
                            'title' => 'Live .env database credentials are inconsistent',
                            'summary' => 'The running app config still has drifted DB settings that commonly cause HTTP 500s. '
                                .implode('. ', $details).'.',
                            'evidence' => $details,
                            'treat_action' => 'sync_database_credentials',
                            'treat_label' => 'Repair DB credentials',
                            'manual_steps' => [
                                'Click Repair DB credentials to align passwords and rewrite .env.',
                                'Then reload the site.',
                            ],
                            'source' => 'live',
                        ];
                    }

                    if (! empty($probe['driver_missing'])) {
                        $findings[] = [
                            'id' => 'live_missing_pdo',
                            'severity' => 'critical',
                            'title' => 'Live check: database PDO driver missing',
                            'summary' => 'The app container cannot open a database connection because the PDO driver is missing.',
                            'evidence' => array_filter([(string) $probe['error']]),
                            'treat_action' => $databaseTemplate->type === 'postgresql' ? 'ensure_pdo_pgsql' : null,
                            'treat_label' => $databaseTemplate->type === 'postgresql' ? 'Install pdo_pgsql' : null,
                            'manual_steps' => ['Install the missing PDO driver, then retry.'],
                            'source' => 'live',
                        ];
                    } else {
                        $error = (string) ($probe['error'] ?? 'Connection failed');
                        $isAuth = (bool) preg_match('/password authentication failed|access denied/i', $error);
                        $isMissingDb = (bool) preg_match('/database ".*" does not exist|unknown database/i', $error);

                        $findings[] = [
                            'id' => 'live_db_connection_failed',
                            'severity' => 'critical',
                            'title' => $isMissingDb
                                ? 'Live check: database does not exist'
                                : ($isAuth ? 'Live check: database authentication failed' : 'Live check: database connection failed'),
                            'summary' => 'A real connection from the app container to the database sidecar failed right now. '
                                .'This is why the site can still return HTTP 500 even when older log lines look stale.',
                            'evidence' => [mb_substr($error, 0, 300)],
                            'treat_action' => 'sync_database_credentials',
                            'treat_label' => 'Repair DB credentials',
                            'manual_steps' => [
                                'Repair DB credentials (creates missing DB, resets role password, rewrites .env).',
                                'If it still fails, Redeploy with Reset database.',
                            ],
                            'source' => 'live',
                        ];
                    }
                } else {
                    $pdoTableCount = $deploymentService->countApplicationDatabaseTables(
                        $ssh,
                        $deployment->container_name,
                        (string) $databaseTemplate->type,
                        $probeEnv
                    );
                    $artisanTableCount = $this->countTablesViaArtisan($ssh, $deployment);
                    // Prefer artisan (same connection Laravel uses, including config cache).
                    $tableCount = $artisanTableCount ?? $pdoTableCount;
                    $checks['table_count'] = $tableCount;
                    $checks['table_count_pdo'] = $pdoTableCount;
                    $checks['table_count_artisan'] = $artisanTableCount;
                    $checks['db_name'] = $probeEnv['DB_DATABASE'] ?? null;

                    $hasArtisan = $this->containerHasArtisan($ssh, $deployment);

                    if (
                        $pdoTableCount === 0
                        && $artisanTableCount !== null
                        && $artisanTableCount > 0
                    ) {
                        $findings[] = [
                            'id' => 'live_db_config_drift',
                            'severity' => 'critical',
                            'title' => 'Live check: .env DB differs from artisan connection',
                            'summary' => 'On-disk .env database "'.($probeEnv['DB_DATABASE'] ?? '').'" has 0 tables, '
                                .'but artisan sees '.$artisanTableCount.' tables. Config cache is likely pointing at a different database than .env.',
                            'evidence' => [
                                'pdo_tables=0',
                                'artisan_tables='.$artisanTableCount,
                                'DB_DATABASE='.($probeEnv['DB_DATABASE'] ?? ''),
                            ],
                            'treat_action' => 'clear_laravel_caches',
                            'treat_label' => 'Clear Laravel caches',
                            'manual_steps' => [
                                'Click Clear Laravel caches (runs config:clear / optimize:clear).',
                                'Then: php artisan migrate:fresh --force && php artisan db:seed --force against the .env database, or align .env with the DB that already has tables.',
                            ],
                            'source' => 'live',
                        ];
                    } elseif ($tableCount === 0 && $hasArtisan && in_array($stack, ['laravel', 'php'], true)) {
                        $findings[] = [
                            'id' => 'live_empty_database',
                            'severity' => 'critical',
                            'title' => 'Live check: database has no tables',
                            'summary' => 'DB credentials work for "'.($probeEnv['DB_DATABASE'] ?? '').'", but the schema is empty. '
                                .'HTTP 500 will continue until migrations (or a SQL import) create tables.',
                            'evidence' => [
                                'table_count=0',
                                'DB_DATABASE='.($probeEnv['DB_DATABASE'] ?? ''),
                                $artisanTableCount === null ? 'artisan_count=unavailable' : 'artisan_tables=0',
                            ],
                            'treat_action' => 'migrate_fresh',
                            'treat_label' => 'Rebuild schema (migrate:fresh)',
                            'manual_steps' => [
                                'Click Rebuild schema — runs php artisan migrate:fresh --force (safe while tables=0).',
                                'Or in Terminal: php artisan config:clear && php artisan migrate:fresh --force',
                                'Then: php artisan db:seed --force',
                            ],
                            'source' => 'live',
                        ];
                    }
                }
            }

            $httpStatus = $this->probeHttpStatus($ssh, $deployment);
            $checks['http_status'] = $httpStatus;

            if ($httpStatus !== null && $httpStatus >= 500) {
                $hasEmptyDb = collect($findings)->contains(
                    fn ($f) => in_array($f['id'] ?? '', ['live_empty_database', 'live_db_config_drift'], true)
                );
                $hasCredentialDbIssue = collect($findings)->contains(
                    fn ($f) => in_array($f['id'] ?? '', ['live_db_connection_failed', 'live_env_credential_drift', 'live_missing_pdo'], true)
                );

                $appErrors = $this->readRecentApplicationErrors($ssh, $deployment);
                $evidence = array_values(array_filter([
                    'HTTP '.$httpStatus,
                    (string) ($deployment->getAccessUrl() ?? ''),
                    ...$appErrors,
                ]));

                if ($hasEmptyDb) {
                    // Empty-schema finding already exposes migrate:fresh — avoid a second
                    // card that wrongly suggests Repair DB credentials.
                } elseif ($hasCredentialDbIssue) {
                    $findings[] = [
                        'id' => 'live_http_5xx',
                        'severity' => 'critical',
                        'title' => 'Live check: site returns HTTP '.$httpStatus,
                        'summary' => 'The public URL is returning HTTP '.$httpStatus.'. Fix the database credential findings above first, then re-scan.',
                        'evidence' => $evidence,
                        'treat_action' => 'sync_database_credentials',
                        'treat_label' => 'Repair DB credentials',
                        'manual_steps' => [
                            'Re-scan after applying the suggested fix.',
                            'In Terminal check: tail -n 80 storage/logs/laravel.log',
                        ],
                        'source' => 'live',
                    ];
                } else {
                    $looksLikeMissingCacheLocks = collect($appErrors)->contains(
                        fn ($line) => (bool) preg_match('/cache_locks/i', (string) $line)
                    );
                    $looksLikeMissingTable = collect($appErrors)->contains(
                        fn ($line) => (bool) preg_match('/relation .* does not exist|Base table or view not found|no such table/i', (string) $line)
                    );

                    $hasTables = ($checks['table_count'] ?? null) !== null && (int) $checks['table_count'] > 0;

                    if ($looksLikeMissingCacheLocks) {
                        $treatAction = 'use_file_cache';
                        $treatLabel = 'Switch cache to file';
                    } elseif ($looksLikeMissingTable) {
                        $treatAction = $hasTables ? 'run_migrations' : 'migrate_fresh';
                        $treatLabel = $hasTables ? 'Run migrations' : 'Rebuild schema (migrate:fresh)';
                    } elseif (in_array($stack, ['laravel', 'php'], true) && $hasTables) {
                        $treatAction = 'restart_application';
                        $treatLabel = 'Restart application';
                    } elseif (in_array($stack, ['laravel', 'php'], true)) {
                        $treatAction = 'clear_laravel_caches';
                        $treatLabel = 'Clear Laravel caches';
                    } else {
                        $treatAction = 'restart_application';
                        $treatLabel = 'Restart application';
                    }

                    $summary = $looksLikeMissingCacheLocks
                        ? 'DB connects and has tables, but Laravel is using database cache without a cache_locks table — that commonly 500s GET /.'
                        : ($looksLikeMissingTable
                            ? 'DB connects, but the app error looks like missing tables/migrations.'
                            : 'DB and schema look healthy (tables: '.((string) ($checks['table_count'] ?? '?')).'), '
                                .'but the public URL still returns HTTP '.$httpStatus
                                .'. This is an application exception — clearing caches alone will not clear this card until the URL returns 2xx/3xx.');

                    $findings[] = [
                        'id' => 'live_http_5xx',
                        'severity' => 'critical',
                        'title' => 'Live check: site returns HTTP '.$httpStatus,
                        'summary' => $summary,
                        'evidence' => $evidence,
                        'treat_action' => $treatAction,
                        'treat_label' => $treatLabel,
                        'manual_steps' => [
                            'In Terminal: tail -n 120 storage/logs/laravel.log',
                            'If you see cache_locks missing: set CACHE_STORE=file && php artisan config:clear',
                            'Fix the exception shown there, then re-scan — this card stays until the public URL stops returning HTTP 5xx.',
                        ],
                        'source' => 'live',
                    ];
                }
            }
        } catch (\Throwable $e) {
            $findings[] = [
                'id' => 'live_probe_failed',
                'severity' => 'warning',
                'title' => 'Live checks could not finish',
                'summary' => 'Doctor could not complete live probes: '.$e->getMessage(),
                'evidence' => [mb_substr($e->getMessage(), 0, 240)],
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => ['Retry Run doctor in a minute.'],
                'source' => 'live',
            ];
        } finally {
            $ssh->disconnect();
        }

        usort($findings, function (array $a, array $b): int {
            $order = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
        });

        return ['findings' => $findings, 'checks' => $checks];
    }

    /**
     * @param  list<array<string, mixed>>  $logFindings
     * @param  array{findings?: list<array<string, mixed>>, checks?: array<string, mixed>}  $live
     * @return list<array<string, mixed>>
     */
    public function mergeLogAndLiveFindings(array $logFindings, array $live): array
    {
        $liveFindings = $live['findings'] ?? [];
        $checks = $live['checks'] ?? [];
        $dbOk = ($checks['db_ok'] ?? null) === true;

        $resolvedLogIds = [
            'postgres_password_auth_failed',
            'postgres_database_missing',
            'mysql_access_denied',
            'missing_pdo_pgsql',
        ];

        $logFindings = array_values(array_filter($logFindings, function (array $f) use ($dbOk, $liveFindings, $resolvedLogIds) {
            if (! empty($f['stale'])) {
                return false;
            }

            // If live PDO works, old auth/missing-db log signatures are historical only.
            if ($dbOk && in_array($f['id'] ?? '', $resolvedLogIds, true)) {
                return false;
            }

            $hasLiveDbSignal = collect($liveFindings)->contains(
                fn ($live) => in_array($live['id'] ?? '', [
                    'live_db_connection_failed',
                    'live_env_credential_drift',
                    'live_empty_database',
                    'live_missing_pdo',
                    'live_http_5xx',
                ], true)
            );

            if ($hasLiveDbSignal && in_array($f['id'] ?? '', $resolvedLogIds, true)) {
                return false;
            }

            return true;
        }));

        $byId = [];
        foreach ($logFindings as $finding) {
            $byId[(string) ($finding['id'] ?? uniqid('log_', true))] = $finding;
        }
        foreach ($liveFindings as $finding) {
            $byId[(string) ($finding['id'] ?? uniqid('live_', true))] = $finding;
        }

        $merged = array_values($byId);
        usort($merged, function (array $a, array $b): int {
            $order = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
        });

        return $merged;
    }

    /**
     * @return array<string, string>
     */
    public function parseEnvFileContent(string $content): array
    {
        $env = [];
        foreach (preg_split("/\r\n|\n|\r/", $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $env[$key] = $value;
        }

        return $env;
    }

    /**
     * @return array<string, string>
     */
    private function readLiveAppEnvironment(SSHService $ssh, $deployment): array
    {
        $base = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
        foreach ([$base.'/.env', $base.'/backend/.env'] as $path) {
            try {
                $exists = trim($ssh->exec('test -f '.escapeshellarg($path).' && echo yes || echo no', 10));
                if ($exists !== 'yes') {
                    continue;
                }
                $content = $ssh->exec('cat '.escapeshellarg($path), 20);
                $parsed = $this->parseEnvFileContent((string) $content);
                if ($parsed !== []) {
                    return $parsed;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    /**
     * Prefer DATABASE_URL credentials when present (Laravel does the same).
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public function envForRuntimeDatabaseProbe(array $env, string $databaseType): array
    {
        $probe = $env;
        $url = (string) ($env['DATABASE_URL'] ?? '');
        if ($url !== '') {
            $parts = parse_url($url);
            if (is_array($parts)) {
                if (! empty($parts['host'])) {
                    $probe['DB_HOST'] = (string) $parts['host'];
                }
                if (! empty($parts['port'])) {
                    $probe['DB_PORT'] = (string) $parts['port'];
                }
                if (isset($parts['user'])) {
                    $probe['DB_USERNAME'] = rawurldecode((string) $parts['user']);
                }
                if (isset($parts['pass'])) {
                    $probe['DB_PASSWORD'] = rawurldecode((string) $parts['pass']);
                    if ($databaseType === 'postgresql') {
                        $probe['POSTGRES_PASSWORD'] = $probe['DB_PASSWORD'];
                    }
                    if (in_array($databaseType, ['mysql', 'mariadb'], true)) {
                        $probe['MYSQL_PASSWORD'] = $probe['DB_PASSWORD'];
                    }
                }
                if (! empty($parts['path']) && $parts['path'] !== '/') {
                    $dbName = ltrim((string) $parts['path'], '/');
                    if ($dbName !== '') {
                        $probe['DB_DATABASE'] = $dbName;
                        if ($databaseType === 'postgresql') {
                            $probe['POSTGRES_DB'] = $dbName;
                        }
                        if (in_array($databaseType, ['mysql', 'mariadb'], true)) {
                            $probe['MYSQL_DATABASE'] = $dbName;
                        }
                    }
                }
            }
        }

        return $probe;
    }

    private function containerHasArtisan(SSHService $ssh, $deployment): bool
    {
        try {
            $result = trim($ssh->exec(
                'docker exec '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg('[ -f /app/artisan ] || [ -f /app/backend/artisan ] && echo yes || echo no'),
                15
            ));

            return $result === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Count tables using Laravel's bootstrapped DB connection (honours config cache).
     */
    private function countTablesViaArtisan(SSHService $ssh, $deployment): ?int
    {
        $php = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    if (method_exists(Illuminate\Support\Facades\Schema::class, "getTableListing")) {
        echo count(Illuminate\Support\Facades\Schema::getTableListing());
    } else {
        $rows = Illuminate\Support\Facades\DB::select(
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog','information_schema','mysql','performance_schema','sys')"
        );
        echo (int) ($rows[0]->c ?? 0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHP;

        $script = 'if [ -f /app/backend/artisan ]; then cd /app/backend; '
            .'elif [ -f /app/artisan ]; then cd /app; '
            .'else exit 1; fi; '
            .'php -r '.escapeshellarg($php);

        try {
            $output = trim($ssh->exec(
                'docker exec -u www-data '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg($script),
                45
            ));

            return is_numeric($output) ? (int) $output : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function readRecentApplicationErrors(SSHService $ssh, $deployment): array
    {
        $scripts = [
            'for f in /app/backend/storage/logs/laravel.log /app/storage/logs/laravel.log '
                .'/app/backend/storage/logs/laravel-*.log /app/storage/logs/laravel-*.log; do '
                .'if [ -f "$f" ]; then echo "=== $f"; tail -n 120 "$f"; fi; done',
        ];

        $lines = [];
        foreach ($scripts as $script) {
            try {
                $output = trim($ssh->exec(
                    'docker exec '.escapeshellarg($deployment->container_name)
                    .' sh -lc '.escapeshellarg($script),
                    25
                ));
                if ($output === '') {
                    continue;
                }

                $rawLines = preg_split("/\r\n|\n|\r/", $output) ?: [];
                foreach ($rawLines as $i => $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if (preg_match('/(SQLSTATE|\.ERROR:|local\.ERROR|Exception|FATAL|CRITICAL|relation .* does not exist|Base table or view not found|No application encryption key|APP_KEY)/i', $line)) {
                        $lines[] = mb_substr($line, 0, 280);
                        // Capture the following message line when present.
                        $next = trim((string) ($rawLines[$i + 1] ?? ''));
                        if ($next !== '' && ! str_starts_with($next, '[') && ! str_starts_with($next, '#')) {
                            $lines[] = mb_substr($next, 0, 280);
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($lines === []) {
            $bodyHint = $this->probeHttpErrorSnippet($ssh, $deployment);
            if ($bodyHint !== null) {
                $lines[] = $bodyHint;
            }
        }

        return array_slice(array_values(array_unique($lines)), -8);
    }

    private function probeHttpErrorSnippet(SSHService $ssh, $deployment): ?string
    {
        $url = $deployment->getAccessUrl();
        if (! is_string($url) || $url === '') {
            return null;
        }

        try {
            $body = trim($ssh->exec(
                'curl -sL --max-time 12 '.escapeshellarg($url).' | tr "\\n" " " | head -c 500 || true',
                20
            ));
            if ($body === '') {
                return null;
            }
            // Prefer recognizable Laravel/Whoops phrases.
            if (preg_match('/(SQLSTATE|Exception|ErrorException|Whoops|No application encryption key|server error)[^<]{0,160}/i', $body, $m)) {
                return 'HTTP body: '.trim(preg_replace('/\s+/', ' ', $m[0]));
            }

            return 'HTTP body: '.mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 200);
        } catch (\Throwable) {
            return null;
        }
    }

    private function probeHttpStatus(SSHService $ssh, $deployment): ?int
    {
        $url = $deployment->getAccessUrl();
        if (! is_string($url) || $url === '') {
            return null;
        }

        try {
            $code = trim($ssh->exec(
                'curl -s -o /dev/null -w "%{http_code}" --max-time 12 '.escapeshellarg($url).' || true',
                20
            ));
            if (preg_match('/^\d{3}$/', $code) === 1) {
                return (int) $code;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
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
                'id' => 'missing_cache_locks_table',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/relation "cache_locks" does not exist/i',
                    '/Table [\'"]?cache_locks[\'"]? doesn\'t exist/i',
                    '/Base table or view not found.*cache_locks/i',
                ],
                'title' => 'Database cache table missing (cache_locks)',
                'summary' => 'CACHE_STORE/CACHE_DRIVER is database, but the cache_locks table was never migrated. That commonly makes GET / return HTTP 500 even when the rest of the schema exists.',
                'treat_action' => 'use_file_cache',
                'treat_label' => 'Switch cache to file',
                'manual_steps' => [
                    'Click Switch cache to file (sets CACHE_STORE=file and clears config).',
                    'Or create tables: php artisan cache:table && php artisan migrate --force',
                    'Or in .env set CACHE_STORE=file then php artisan config:clear',
                ],
            ],
            [
                'id' => 'npm_cache_permission_denied',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php', 'nodejs'],
                'patterns' => [
                    '/Your cache folder contains root-owned files/i',
                    '/EACCES[\s\S]{0,80}\/var\/www\/\.npm/i',
                    '/errno EACCES[\s\S]{0,120}\.npm/i',
                ],
                'title' => 'npm cache permission denied',
                'summary' => 'npm cannot write to /var/www/.npm (often root-owned). Installs fail and next/vite binaries stay missing.',
                'treat_action' => 'fix_npm_cache_permissions',
                'treat_label' => 'Fix npm cache permissions',
                'manual_steps' => [
                    'Click Fix npm cache permissions (writes .npmrc + resets /var/www/.npm).',
                    'Then run exactly: cd /app/frontend && rm -rf node_modules && HOME=/tmp npm install --legacy-peer-deps --cache /tmp/.npm',
                ],
            ],
            [
                'id' => 'next_binary_not_found',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php', 'nodejs'],
                'patterns' => [
                    '/sh:\s+1:\s+next:\s+not found/i',
                    '/next:\s+not found/i',
                ],
                'title' => 'Next.js binary not found',
                'summary' => 'npm install did not complete (or node_modules is incomplete), so `next` is missing under /app/frontend.',
                'treat_action' => 'fix_npm_cache_permissions',
                'treat_label' => 'Fix npm cache + reinstall hint',
                'manual_steps' => [
                    'Fix npm cache permissions first.',
                    'cd /app/frontend && rm -rf node_modules && HOME=/tmp npm install --legacy-peer-deps --cache /tmp/.npm',
                    'Then: npm run build && npx next start -H 0.0.0.0 -p 3000',
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
                'id' => 'wordpress_upload_permission_denied',
                'severity' => 'warning',
                'stacks' => ['wordpress'],
                'patterns' => [
                    '/Unable to create directory.*wp-content\/uploads/i',
                    '/Failed to write file to disk/i',
                    '/Could not create directory.*wp-content\/plugins/i',
                    '/Destination folder already exists\./i',
                    '/permission denied.*wp-content/i',
                    '/is_writable\(\).*wp-content/i',
                ],
                'title' => 'WordPress cannot write media or plugins',
                'summary' => 'Apache (www-data) cannot write under wp-content. Media uploads and plugin installs fail until ownership is fixed.',
                'treat_action' => 'fix_wordpress_permissions',
                'treat_label' => 'Fix WordPress permissions',
                'manual_steps' => [
                    'Click Fix WordPress permissions, then retry the upload or plugin install.',
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
            $platformEnv = is_array($deployment->env_values) ? $deployment->env_values : [];
            $liveEnv = $this->readLiveAppEnvironment($ssh, $deployment);
            $rawEnv = $liveEnv === [] ? $platformEnv : array_merge($platformEnv, $liveEnv);

            // Keep a handle on the volume's original platform role for admin bootstrap.
            $canonical = $deploymentService->canonicalDatabaseIdentifiers($service);
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $bootstrap = is_array($meta['postgres_bootstrap'] ?? null) ? $meta['postgres_bootstrap'] : [];

            $platformUser = (string) (
                $bootstrap['username']
                ?? $platformEnv['DB_USERNAME']
                ?? $platformEnv['POSTGRES_USER']
                ?? $canonical['username']
            );
            $platformPassword = (string) (
                $bootstrap['password']
                ?? $platformEnv['DB_PASSWORD']
                ?? $platformEnv['POSTGRES_PASSWORD']
                ?? ''
            );

            // If platform env was already overwritten with the app role, prefer canonical bootstrap user.
            if ($platformUser !== '' && $platformUser === (string) ($rawEnv['DB_USERNAME'] ?? '')) {
                $platformUser = $canonical['username'];
                if (($bootstrap['password'] ?? '') !== '') {
                    $platformPassword = (string) $bootstrap['password'];
                }
            }

            if ($platformUser !== '') {
                $rawEnv['TALKSASA_PLATFORM_DB_USERNAME'] = $platformUser;
            }
            if ($platformPassword !== '') {
                $rawEnv['TALKSASA_PLATFORM_DB_PASSWORD'] = $platformPassword;
            }

            // Persist bootstrap credentials so later repairs can still reach the volume superuser.
            if ($canonical['username'] !== '' && $platformPassword !== '' && $platformUser === $canonical['username']) {
                $meta['postgres_bootstrap'] = [
                    'username' => $canonical['username'],
                    'password' => $platformPassword,
                ];
                $service->update(['service_meta' => $meta]);
            }

            $workingPassword = $this->discoverWorkingDatabasePassword(
                $ssh,
                $deployment,
                $databaseTemplate->type,
                $rawEnv
            );
            if ($workingPassword !== null) {
                $rawEnv['DB_PASSWORD'] = $workingPassword;
                $rawEnv['POSTGRES_PASSWORD'] = $workingPassword;
                $rawEnv['MYSQL_PASSWORD'] = $workingPassword;
            }

            $normalized = $deploymentService->normalizeDatabaseEnvironment(
                $service,
                $rawEnv,
                (string) $databaseTemplate->type
            );
            $envVars = $normalized['env'];
            $platformAdminUser = (string) ($rawEnv['TALKSASA_PLATFORM_DB_USERNAME'] ?? '');
            $platformAdminPassword = (string) ($rawEnv['TALKSASA_PLATFORM_DB_PASSWORD'] ?? '');
            unset(
                $envVars['TALKSASA_PLATFORM_DB_USERNAME'],
                $envVars['TALKSASA_PLATFORM_DB_PASSWORD']
            );

            $deployment->update(['env_values' => array_merge($platformEnv, $envVars)]);
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['env_values'] = array_merge($platformEnv, $envVars);
            $service->update(['service_meta' => $meta]);
            $deployment->refresh();
            $service->setRelation('containerDeployment', $deployment);

            $syncEnv = $envVars;
            if ($platformAdminUser !== '') {
                $syncEnv['TALKSASA_PLATFORM_DB_USERNAME'] = $platformAdminUser;
            }
            if ($platformAdminPassword !== '') {
                $syncEnv['TALKSASA_PLATFORM_DB_PASSWORD'] = $platformAdminPassword;
            }

            match ($databaseTemplate->type) {
                'mysql', 'mariadb' => $deploymentService
                    ->syncMysqlSidecarCredentials($ssh, $containerPath, $syncEnv),
                'postgresql' => $deploymentService
                    ->syncPostgresqlSidecarCredentials($ssh, $containerPath, $syncEnv, $service),
                'mongodb' => $deploymentService
                    ->syncMongodbSidecarCredentials($ssh, $containerPath, $syncEnv),
                default => throw new \RuntimeException('Unsupported database type: '.$databaseTemplate->type),
            };

            $stack = strtolower((string) ($service->effectiveContainerTemplate()?->slug ?? ''));
            if (in_array($stack, ['laravel', 'php'], true)) {
                try {
                    app(LaravelAppInitializationService::class)
                        ->writeApplicationEnvironment($service, $deployment, $ssh, preserveExisting: true);
                } catch (\Throwable $e) {
                    \Log::warning('Doctor Laravel env write failed; falling back to dotenv merge', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Always force DATABASE_URL / POSTGRES_* onto disk — preserveExisting used to skip them.
                app(ContainerEnvironmentService::class)
                    ->syncDotEnvFile($ssh, $service, $deployment, $envVars);

                try {
                    $this->clearLaravelCachesQuietly($service, $ssh);
                } catch (\Throwable) {
                    // Cache clear is best-effort after credential repair.
                }
            } else {
                app(ContainerEnvironmentService::class)
                    ->syncDotEnvFile($ssh, $service, $deployment, $envVars);
            }

            $probe = $deploymentService->probeApplicationDatabaseAccess(
                $ssh,
                $deployment->container_name,
                (string) $databaseTemplate->type,
                $this->envForRuntimeDatabaseProbe($envVars, (string) $databaseTemplate->type)
            );

            $message = 'Database "'.$normalized['database'].'" credentials synced and .env rewritten (including DATABASE_URL).';
            if ($normalized['corrected'] && $normalized['previous_database'] && $normalized['previous_database'] !== $normalized['database']) {
                $message = 'Fixed DB_DATABASE from "'.$normalized['previous_database'].'" to "'
                    .$normalized['database'].'". '.$message;
            }
            if (! empty($normalized['password_aligned']) || $workingPassword !== null) {
                $message .= ' Aligned DB_PASSWORD, sidecar password, and DATABASE_URL.';
            }

            if (! $probe['ok']) {
                return [
                    'success' => false,
                    'message' => $message.' Live connection still fails: '.($probe['error'] ?? 'unknown error')
                        .'. Try Redeploy with Reset database.',
                ];
            }

            $tableCount = $deploymentService->countApplicationDatabaseTables(
                $ssh,
                $deployment->container_name,
                (string) $databaseTemplate->type,
                $envVars
            );
            if ($tableCount === 0 && in_array($stack, ['laravel', 'php'], true)) {
                $migrate = $this->runMigrationsQuietly($service, $ssh);
                if ($migrate['success']) {
                    $message .= ' Empty schema detected — migrations were run automatically.';
                } else {
                    $message .= ' Database connects but has no tables. Run migrations next: '.$migrate['message'];
                }
            } else {
                $message .= ' Live DB probe OK. Reload the site.';
            }

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

    /**
     * Prefer a password that already authenticates against the live sidecar.
     *
     * @param  array<string, string>  $env
     */
    private function discoverWorkingDatabasePassword(
        SSHService $ssh,
        $deployment,
        string $databaseType,
        array $env
    ): ?string {
        if (! in_array($databaseType, ['postgresql', 'mysql', 'mariadb'], true)) {
            return null;
        }

        $candidates = [];
        foreach ([
            (string) ($env['DB_PASSWORD'] ?? ''),
            (string) ($env['POSTGRES_PASSWORD'] ?? ''),
            (string) ($env['MYSQL_PASSWORD'] ?? ''),
        ] as $password) {
            $password = trim($password);
            if ($password !== '') {
                $candidates[$password] = true;
            }
        }

        $urlPassword = null;
        if (! empty($env['DATABASE_URL']) && is_string($env['DATABASE_URL'])) {
            $parts = parse_url($env['DATABASE_URL']);
            if (is_array($parts) && isset($parts['pass']) && $parts['pass'] !== '') {
                $urlPassword = rawurldecode((string) $parts['pass']);
                if ($urlPassword !== '') {
                    $candidates[$urlPassword] = true;
                }
            }
        }

        $deploymentService = app(ContainerDeploymentService::class);
        foreach (array_keys($candidates) as $password) {
            $try = $env;
            $try['DB_PASSWORD'] = $password;
            $try['POSTGRES_PASSWORD'] = $password;
            $try['MYSQL_PASSWORD'] = $password;
            $probe = $deploymentService->probeApplicationDatabaseAccess(
                $ssh,
                $deployment->container_name,
                $databaseType,
                $this->envForRuntimeDatabaseProbe($try, $databaseType)
            );
            if ($probe['ok']) {
                return $password;
            }
        }

        return null;
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatRunMigrations(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);

        try {
            $result = $this->runMigrationsQuietly($service, $ssh, fresh: false);
            // Empty DB + migrations table out of sync: escalate to migrate:fresh.
            if (! $result['success'] && str_contains(mb_strtolower($result['message']), 'out of sync')) {
                $result = $this->runMigrationsQuietly($service, $ssh, fresh: true);
            }
            if ($result['success']) {
                try {
                    $this->clearLaravelCachesQuietly($service, $ssh);
                } catch (\Throwable) {
                }
            }

            return $result;
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Rebuild schema with migrate:fresh. Only allowed when the DB has 0 app tables.
     *
     * @return array{success: bool, message: string}
     */
    private function treatMigrateFresh(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);

        try {
            $result = $this->runMigrationsQuietly($service, $ssh, fresh: true);
            if ($result['success']) {
                try {
                    $this->clearLaravelCachesQuietly($service, $ssh);
                } catch (\Throwable) {
                }
            }

            return $result;
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function runMigrationsQuietly(Service $service, SSHService $ssh, bool $fresh = false): array
    {
        $deployment = $service->containerDeployment;
        $init = app(LaravelAppInitializationService::class);
        $deploymentService = app(ContainerDeploymentService::class);

        try {
            $env = is_array($deployment->env_values) ? $deployment->env_values : [];
            $liveEnv = $this->readLiveAppEnvironment($ssh, $deployment);
            if ($liveEnv !== []) {
                $env = array_merge($env, $liveEnv);
            }
            $databaseTemplate = $deploymentService->resolveDatabaseTemplateForService($service);
            $tableCountBefore = null;
            if ($databaseTemplate) {
                $probeEnv = $this->envForRuntimeDatabaseProbe($env, (string) $databaseTemplate->type);
                $tableCountBefore = $deploymentService->countApplicationDatabaseTables(
                    $ssh,
                    $deployment->container_name,
                    (string) $databaseTemplate->type,
                    $probeEnv
                );
            }

            // migrate:fresh destroys schema — only allow on an empty database.
            if ($fresh && $tableCountBefore !== null && $tableCountBefore > 0) {
                return [
                    'success' => false,
                    'message' => 'Refusing migrate:fresh: database already has '.$tableCountBefore
                        .' tables. Use Run migrations, or wipe the DB from the Database tab first.',
                ];
            }

            $roots = [];
            foreach (['/app/backend', '/app'] as $candidate) {
                try {
                    $has = trim($ssh->exec(
                        'docker exec '.escapeshellarg($deployment->container_name)
                        .' sh -lc '.escapeshellarg('[ -f '.$candidate.'/artisan ] && echo yes || echo no'),
                        10
                    ));
                    if ($has === 'yes') {
                        $roots[] = $candidate;
                    }
                } catch (\Throwable) {
                }
            }

            if ($roots === []) {
                return [
                    'success' => false,
                    'message' => 'No Laravel artisan binary found under /app or /app/backend. Import a SQL dump from the Database tab instead.',
                ];
            }

            $outputs = [];
            $lastError = null;
            $migrateCmd = $fresh
                ? 'php artisan migrate:fresh --force --no-interaction 2>&1'
                : 'php artisan migrate --force --no-interaction 2>&1';

            foreach ($roots as $projectRoot) {
                $script = 'set -e; cd '.escapeshellarg($projectRoot).'; '
                    .'if [ ! -f .env ]; then echo "MISSING_ENV"; exit 42; fi; '
                    .$migrateCmd.'; '
                    .'php artisan db:seed --force --no-interaction 2>&1 || true';

                try {
                    $output = trim((string) $init->dockerExecPublic(
                        $ssh,
                        $deployment->container_name,
                        $script,
                        600
                    ));
                    $outputs[] = $projectRoot.': '.$output;
                    $lastError = null;
                    break;
                } catch (\Throwable $e) {
                    $lastError = $e;
                    $outputs[] = $projectRoot.': FAILED '.$e->getMessage();

                    try {
                        $output = trim((string) $init->dockerExecPublic(
                            $ssh,
                            $deployment->container_name,
                            $script,
                            600,
                            asRoot: true
                        ));
                        $outputs[] = $projectRoot.' (root): '.$output;
                        $lastError = null;
                        break;
                    } catch (\Throwable $rootError) {
                        $lastError = $rootError;
                        $outputs[] = $projectRoot.' (root): FAILED '.$rootError->getMessage();
                    }
                }
            }

            if ($lastError) {
                return [
                    'success' => false,
                    'message' => 'Migrations failed: '.mb_substr($lastError->getMessage(), 0, 400),
                ];
            }

            $tableCount = null;
            if ($databaseTemplate) {
                $probeEnv = $this->envForRuntimeDatabaseProbe($env, (string) $databaseTemplate->type);
                $tableCount = $deploymentService->countApplicationDatabaseTables(
                    $ssh,
                    $deployment->container_name,
                    (string) $databaseTemplate->type,
                    $probeEnv
                );
            }

            if ($tableCount === 0) {
                $joined = mb_strtolower(implode(' | ', $outputs));
                $nothingToMigrate = str_contains($joined, 'nothing to migrate');

                $migrationFiles = 0;
                try {
                    $migrationFiles = (int) trim($ssh->exec(
                        'docker exec '.escapeshellarg($deployment->container_name)
                        .' sh -lc '.escapeshellarg(
                            'for d in /app/backend/database/migrations /app/database/migrations; do '
                            .'if [ -d "$d" ]; then ls -1 "$d"/*.php 2>/dev/null | wc -l; fi; '
                            .'done | awk \'{s+=$1} END {print s+0}\''
                        ),
                        15
                    ));
                } catch (\Throwable) {
                }

                if ($nothingToMigrate && $migrationFiles > 0 && ! $fresh) {
                    return [
                        'success' => false,
                        'message' => 'Artisan says "Nothing to migrate" but '.$migrationFiles
                            .' migration files exist and the database still has 0 tables. '
                            .'The migrations table is likely out of sync (migrations marked ran without schema). '
                            .'Use Rebuild schema (migrate:fresh), or in Terminal: '
                            .'php artisan migrate:status && php artisan migrate:fresh --force '
                            .'(OK on an empty DB).',
                    ];
                }

                return [
                    'success' => false,
                    'message' => $nothingToMigrate
                        ? 'Artisan reports "Nothing to migrate" and the database still has 0 tables. '
                            .'Import your SQL dump from the Database tab (or run migrate:fresh --force if migration files exist).'
                        : 'Artisan migrate finished but the database still has 0 tables. '
                            .'Import your SQL dump from the Database tab. '
                            .'Output: '.mb_substr(implode(' | ', $outputs), 0, 300),
                ];
            }

            return [
                'success' => true,
                'message' => ($fresh ? 'migrate:fresh completed. ' : 'Migrations completed. ')
                    .'Tables now: '.$tableCount.'. '
                    .mb_substr(implode(' | ', $outputs), 0, 240),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Migrations failed: '.$e->getMessage(),
            ];
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
     * Repair root-owned npm cache so www-data can install frontend deps.
     *
     * @return array{success: bool, message: string}
     */
    private function treatFixNpmCachePermissions(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);
        $init = app(LaravelAppInitializationService::class);

        try {
            $script = 'set -e; '
                .'mkdir -p /tmp/.npm /tmp/www-home /var/www; '
                .'rm -rf /var/www/.npm; '
                .'mkdir -p /var/www/.npm; '
                .'printf "cache=/tmp/.npm\\n" > /var/www/.npmrc; '
                .'printf "cache=/tmp/.npm\\n" > /tmp/www-home/.npmrc; '
                .'if [ -d /app/frontend ]; then printf "cache=/tmp/.npm\\n" > /app/frontend/.npmrc; fi; '
                .'if [ -d /app ] && [ -f /app/package.json ]; then printf "cache=/tmp/.npm\\n" > /app/.npmrc; fi; '
                .'chown -R www-data:www-data /var/www/.npm /var/www/.npmrc /tmp/.npm /tmp/www-home 2>/dev/null || true; '
                .'chown www-data:www-data /app/frontend/.npmrc /app/.npmrc 2>/dev/null || true; '
                .'chmod -R u+rwX /var/www/.npm /tmp/.npm /tmp/www-home 2>/dev/null || true; '
                .'echo ok';

            $init->dockerExecPublic($ssh, $deployment->container_name, $script, 60, asRoot: true);

            return [
                'success' => true,
                'message' => 'npm cache redirected to /tmp/.npm and /var/www/.npm reset. '
                    .'In Terminal run exactly: '
                    .'cd /app/frontend && rm -rf node_modules && '
                    .'HOME=/tmp npm install --legacy-peer-deps --cache /tmp/.npm',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to fix npm cache: '.$e->getMessage()];
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
     * Switch Laravel cache (and session if database-backed) to file drivers.
     *
     * @return array{success: bool, message: string}
     */
    private function treatUseFileCache(Service $service): array
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

            $php = <<<'PHP'
$root = getenv('PROJECT_ROOT') ?: '/app';
$envPath = rtrim($root, '/').'/.env';
if (!is_file($envPath)) { fwrite(STDERR, "MISSING_ENV\n"); exit(1); }
$c = file_get_contents($envPath);
if ($c === false) { fwrite(STDERR, "read failed\n"); exit(1); }
$set = function (string $c, string $k, string $v): string {
    if (preg_match('/^'.preg_quote($k, '/').'=/m', $c)) {
        return preg_replace('/^'.preg_quote($k, '/').'=.*$/m', $k.'='.$v, $c, 1);
    }
    return rtrim($c)."\n".$k.'='.$v."\n";
};
$c = $set($c, 'CACHE_STORE', 'file');
$c = $set($c, 'CACHE_DRIVER', 'file');
if (preg_match('/^SESSION_DRIVER=(database|redis)/mi', $c)) {
    $c = $set($c, 'SESSION_DRIVER', 'file');
}
if (file_put_contents($envPath, $c) === false) { fwrite(STDERR, "write failed\n"); exit(1); }
echo "ok";
PHP;

            $script = 'set -e; export PROJECT_ROOT='.escapeshellarg($projectRoot).'; '
                .'php -r '.escapeshellarg($php).'; '
                .'cd '.escapeshellarg($projectRoot).'; '
                .'php artisan optimize:clear --no-interaction 2>/dev/null '
                .'|| php artisan config:clear --no-interaction';

            $init->dockerExecPublic($ssh, $deployment->container_name, $script, 120, asRoot: true);

            return [
                'success' => true,
                'message' => 'Switched CACHE_STORE/CACHE_DRIVER to file and cleared config. Reload the site.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to switch cache driver: '.$e->getMessage()];
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
            $slug = $service->effectiveContainerTemplate()?->slug
                ?? $service->product?->containerTemplate?->slug;

            if ($slug === 'wordpress') {
                return $this->treatFixWordPressPermissions($service);
            }

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
    private function treatFixWordPressPermissions(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $ssh = SSHService::forNode($deployment->node);
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $hostAppPath = $containerPath.'/app';

        try {
            app(WordPressContainerHardeningService::class)->ensureWritableFilesystem(
                $ssh,
                $hostAppPath,
                $containerPath,
                $deployment->container_name,
            );

            return [
                'success' => true,
                'message' => 'WordPress files are writable again. Retry media uploads or plugin installs.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to fix WordPress permissions: '.$e->getMessage()];
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
