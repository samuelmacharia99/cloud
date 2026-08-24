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

        // Recreating / Vite runtime repair can revive a crash-looping stack.
        if (! $deployment->isRunning()
            && ! in_array($action, ['recreate_application', 'fix_vite_production_runtime'], true)) {
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
            'fix_wordpress_media_processing' => $this->treatFixWordPressMediaProcessing($service),
            'regenerate_wordpress_thumbnails' => $this->treatRegenerateWordPressThumbnails($service),
            'fix_wordpress_site_url' => $this->treatFixWordPressSiteUrl($service),
            'refresh_domain_proxy' => $this->treatRefreshDomainProxy($service),
            'restart_application' => $this->treatRestartApplication($service),
            'recreate_application' => $this->treatRecreateApplication($service),
            'fix_vite_production_runtime' => $this->treatFixViteProductionRuntime($service),
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
            'upstream_reachable' => null,
            'upstream_local_status' => null,
            'containers_stopped' => null,
            'spa_runtime_api_mismatch' => null,
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

                $proxyFinding = $this->staleProxyVhostFinding($ssh, $service, $deployment, $checks);
                if ($proxyFinding !== null) {
                    $findings[] = $proxyFinding;
                }

                $media = $this->probeWordPressMedia($ssh, $deployment);
                if ($media !== null) {
                    $checks['wordpress_image_editor'] = $media['editor'];
                    $checks['wordpress_missing_thumbnails'] = $media['missing_sizes'];

                    $liveUrl = app(WordPressAdminLoginService::class)->resolvePublicBaseUrl($service);
                    foreach ($this->wordPressMediaFindings($media, $liveUrl) as $finding) {
                        $findings[] = $finding;
                    }
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

            if ($stack === 'nodejs') {
                $apiMismatch = $this->spaRuntimeApiMismatchFinding($ssh, $deployment, $checks);
                if ($apiMismatch !== null) {
                    $findings[] = $apiMismatch;
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

                $upstream = $this->withBootstrapState($ssh, $deployment, $this->probeUpstream($ssh, $deployment));
                $checks['upstream_reachable'] = $upstream['reachable'];
                $checks['upstream_local_status'] = $upstream['local_status'];
                $checks['containers_stopped'] = $upstream['stopped'];
                $checks['bootstrap_in_progress'] = is_string($upstream['bootstrapping']);

                if (! $upstream['reachable'] && $upstream['assigned_port'] !== null && is_string($upstream['bootstrapping'])) {
                    $findings[] = [
                        'id' => 'live_bootstrap_in_progress',
                        'severity' => 'warning',
                        'title' => 'Live check: the app is still installing and building',
                        'summary' => 'The proxy returned HTTP '.$httpStatus.' because the container is still running its '
                            .'dependency install and production build, so nothing is listening on '
                            .'127.0.0.1:'.$upstream['assigned_port'].' yet. First builds routinely take several minutes. '
                            .'No repair is needed — the app starts answering as soon as the build finishes.',
                        'evidence' => $this->upstreamEvidence($upstream, $httpStatus, (string) ($deployment->getAccessUrl() ?? '')),
                        'manual_steps' => [
                            'Watch the Logs tab until the build output stops and the start command runs.',
                            'Re-run Doctor after the build completes if the site still fails.',
                            'Out-of-memory kills during the build show as "Killed" in Logs — upgrade the plan if that happens.',
                        ],
                        'source' => 'live',
                    ];
                } elseif (! $upstream['reachable'] && $upstream['assigned_port'] !== null) {
                    $findings[] = [
                        'id' => 'live_upstream_unreachable',
                        'severity' => 'critical',
                        'title' => 'Live check: proxy cannot reach the app (HTTP '.$httpStatus.')',
                        'summary' => 'The web proxy returned HTTP '.$httpStatus.' because nothing answered on the app port '
                            .'(127.0.0.1:'.$upstream['assigned_port'].') on the node. This is not an application exception — '
                            .'the container is stopped, crash-looping on boot, or no longer publishing that port, '
                            .'so restarting alone will not help until the boot failure is fixed.',
                        'evidence' => $this->upstreamEvidence($upstream, $httpStatus, (string) ($deployment->getAccessUrl() ?? '')),
                        'treat_action' => 'recreate_application',
                        'treat_label' => 'Recreate containers',
                        'manual_steps' => [
                            'Recreate containers re-runs docker compose up -d, which restart cannot do for missing containers or changed ports.',
                            'Read the boot error in Logs — a crash-looping container repeats the same startup error.',
                            'If the app crashes on boot, fix the start command or missing environment variables, then recreate.',
                        ],
                        'source' => 'live',
                    ];
                } elseif ($hasEmptyDb) {
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
                    $looksLikeMissingViewCache = collect($appErrors)->contains(
                        fn ($line) => (bool) preg_match('/Please provide a valid cache path/i', (string) $line)
                    );

                    $hasTables = ($checks['table_count'] ?? null) !== null && (int) $checks['table_count'] > 0;

                    if ($looksLikeMissingViewCache) {
                        $treatAction = 'fix_storage_permissions';
                        $treatLabel = 'Create Laravel cache directories';
                    } elseif ($looksLikeMissingCacheLocks) {
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

                    $summary = $looksLikeMissingViewCache
                        ? 'Laravel Blade compiled-view path is missing (storage/framework/views). DirectAdmin exports skip those cache dirs, so GET / 500s until they exist.'
                        : ($looksLikeMissingCacheLocks
                        ? 'DB connects and has tables, but Laravel is using database cache without a cache_locks table — that commonly 500s GET /.'
                        : ($looksLikeMissingTable
                            ? 'DB connects, but the app error looks like missing tables/migrations.'
                            : 'The container is up and answering on its port, but the app itself returns HTTP '.$httpStatus
                                .' (tables: '.((string) ($checks['table_count'] ?? '?')).'). '
                                .'This is an application exception — clearing caches alone will not clear this card until the URL returns 2xx/3xx.'));

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
                    'live_upstream_unreachable',
                    'live_bootstrap_in_progress',
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
     * A 502/503/504 from the edge proxy means the upstream port never answered, which is a
     * different failure from an application exception. Probe the published host port and the
     * compose containers so the finding names the real cause.
     *
     * @return array{
     *     assigned_port: ?int,
     *     local_status: ?int,
     *     reachable: bool,
     *     containers: list<array{name: string, state: string, status: string, ports: string}>,
     *     stopped: list<string>,
     *     publishes_port: ?bool,
     *     crash_logs: list<string>
     * }
     */
    private function probeUpstream(SSHService $ssh, $deployment): array
    {
        $port = (int) ($deployment->assigned_port ?? 0);
        $probe = [
            'assigned_port' => $port > 0 ? $port : null,
            'local_status' => null,
            'reachable' => false,
            'containers' => [],
            'stopped' => [],
            'publishes_port' => null,
            'crash_logs' => [],
            'bootstrapping' => null,
        ];

        if ($port > 0) {
            try {
                $code = trim($ssh->exec(
                    'curl -s -o /dev/null -w "%{http_code}" --max-time 8 '
                    .escapeshellarg('http://127.0.0.1:'.$port.'/').' || true',
                    15
                ));
                if (preg_match('/^\d{3}$/', $code) === 1) {
                    $probe['local_status'] = (int) $code;
                    // curl reports 000 when the TCP connection itself fails.
                    $probe['reachable'] = (int) $code >= 100;
                }
            } catch (\Throwable) {
                // Leave as unreachable; container state below explains why.
            }
        }

        $stoppedNames = [];

        try {
            $rows = trim($ssh->exec(
                'docker ps -a --filter '
                .escapeshellarg('label=com.docker.compose.project='.$deployment->container_name)
                .' --format '.escapeshellarg('{{.Names}}|{{.State}}|{{.Status}}|{{.Ports}}'),
                20
            ));

            foreach (preg_split("/\r\n|\n|\r/", $rows) ?: [] as $row) {
                $parts = explode('|', trim($row));
                if (($parts[0] ?? '') === '') {
                    continue;
                }

                $container = [
                    'name' => $parts[0],
                    'state' => strtolower(trim($parts[1] ?? '')),
                    'status' => trim($parts[2] ?? ''),
                    'ports' => trim($parts[3] ?? ''),
                ];
                $probe['containers'][] = $container;

                if ($container['state'] !== 'running') {
                    $stoppedNames[] = $container['name'];
                    $probe['stopped'][] = $container['name']
                        .' ('.($container['status'] !== '' ? $container['status'] : $container['state']).')';
                }
            }
        } catch (\Throwable) {
            // Container inventory is best effort.
        }

        if ($port > 0 && $probe['containers'] !== []) {
            $probe['publishes_port'] = collect($probe['containers'])->contains(
                fn (array $container) => str_contains($container['ports'], ':'.$port.'->')
            );
        }

        if (! $probe['reachable']) {
            $targets = $stoppedNames !== [] ? $stoppedNames : [$deployment->container_name];

            foreach (array_slice($targets, 0, 2) as $name) {
                try {
                    $logs = trim($ssh->exec(
                        'docker logs --tail 25 '.escapeshellarg($name).' 2>&1 || true',
                        20
                    ));

                    foreach (preg_split("/\r\n|\n|\r/", $logs) ?: [] as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $probe['crash_logs'][] = mb_substr($name.': '.$line, 0, 280);
                        }
                    }
                } catch (\Throwable) {
                    continue;
                }
            }

            $probe['crash_logs'] = array_slice(array_reverse($probe['crash_logs']), 0, 12);
            $probe['crash_logs'] = array_reverse($probe['crash_logs']);
        }

        return $probe;
    }

    /**
     * True when the container is started with `vite preview` (built SPA only) while the repo's
     * own start command is a server that owns /api routes. Those routes then answer 404.
     */
    public function spaRuntimeHidesApiRoutes(?string $composeContent, ?string $packageJson): bool
    {
        if (! is_string($composeContent) || ! str_contains($composeContent, 'vite preview')) {
            return false;
        }

        $runtime = app(ContainerApplicationRuntimeService::class);
        $start = $runtime->packageJsonStartScript($packageJson);
        if ($start === null) {
            return false;
        }

        // A bare Vite CLI start is SPA-only anyway, so preview serves exactly the same thing.
        if ($runtime->commandLooksLikeBareViteCli($start) || $runtime->commandLooksLikeVitePreview($start)) {
            return false;
        }

        return $runtime->commandLooksLikeViteDevServer($start);
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array<string, mixed>|null
     */
    private function spaRuntimeApiMismatchFinding(SSHService $ssh, $deployment, array &$checks): ?array
    {
        $hostAppPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';

        try {
            $packageJson = $ssh->exec(
                'head -c 65536 '.escapeshellarg($hostAppPath.'/package.json').' 2>/dev/null || true',
                20
            );
        } catch (\Throwable) {
            return null;
        }

        $mismatch = $this->spaRuntimeHidesApiRoutes(
            (string) $deployment->docker_compose_content,
            trim($packageJson) !== '' ? $packageJson : null
        );
        $checks['spa_runtime_api_mismatch'] = $mismatch;

        if (! $mismatch) {
            return null;
        }

        $start = app(ContainerApplicationRuntimeService::class)->packageJsonStartScript($packageJson);

        return [
            'id' => 'live_spa_runtime_hides_api',
            'severity' => 'critical',
            'title' => 'API routes are not being served',
            'summary' => 'The container is running `vite preview`, which only serves the built frontend. '
                .'Your app starts with "'.$start.'", and that server is what handles /api routes, '
                .'so API requests return 404 while the site itself loads normally. '
                .'Repair Vite runtime restores your own start command and keeps Vite installed.',
            'evidence' => array_values(array_filter([
                'compose start: npx vite preview',
                $start !== null ? 'package.json start: '.$start : null,
            ])),
            'treat_action' => 'fix_vite_production_runtime',
            'treat_label' => 'Repair Vite runtime',
            'manual_steps' => [
                'Click Repair Vite runtime — the container restarts with your start command and a full dependency install.',
                'No repo change is needed; keep serving the SPA and /api from the same server.',
            ],
            'source' => 'live',
        ];
    }

    /**
     * Vhosts written by older builds proxy over HTTP/1.0 with request buffering disabled,
     * so a media upload can stall at the edge and never reach PHP. Nothing appears in the
     * container log in that case — the request never arrives.
     *
     * @param  array<string, mixed>  $checks
     * @return array<string, mixed>|null
     */
    private function staleProxyVhostFinding(SSHService $ssh, Service $service, $deployment, array &$checks): ?array
    {
        $domain = $deployment->relationLoaded('domains')
            ? $deployment->domains->first(fn ($d) => in_array($d->status, ['active', 'pending'], true))
            : $deployment->domains()->whereIn('status', ['active', 'pending'])->first();

        if (! $domain || ! $domain->nginx_config_path) {
            return null;
        }

        try {
            $config = $ssh->exec('cat '.escapeshellarg($domain->nginx_config_path).' 2>/dev/null || true', 20);
        } catch (\Throwable) {
            return null;
        }

        if (trim($config) === '') {
            return null;
        }

        $current = app(NginxProxyService::class)->vhostIsCurrent($config);
        $checks['proxy_vhost_current'] = $current;

        if ($current) {
            return null;
        }

        return [
            'id' => 'live_stale_proxy_vhost',
            'severity' => 'warning',
            'title' => 'Web proxy for '.$domain->domain.' uses an outdated template',
            'summary' => 'This domain was bound by an older build that proxies over HTTP/1.0 with request '
                .'buffering disabled. Large uploads — WordPress media in particular — can hang or be rejected '
                .'before they reach the application, and nothing is written to the container log because the '
                .'request never arrives. Refreshing rewrites the vhost and reloads nginx.',
            'evidence' => array_values(array_filter([
                'vhost: '.$domain->nginx_config_path,
                str_contains($config, 'proxy_http_version 1.1') ? null : 'missing proxy_http_version 1.1',
                str_contains($config, 'proxy_request_buffering off') ? 'proxy_request_buffering off' : null,
            ])),
            'treat_action' => 'refresh_domain_proxy',
            'treat_label' => 'Refresh web proxy',
            'manual_steps' => [
                'Click Refresh web proxy, then retry the upload.',
                'Unbinding and re-binding the domain has the same effect.',
            ],
            'source' => 'live',
        ];
    }

    /**
     * WordPress renders the grey document icon for any attachment without generated image
     * sizes, so "broken" media is usually a metadata/editor problem rather than a failed
     * upload. Read the real state from inside the container.
     */
    private function wordPressMediaProbeScript(): string
    {
        return <<<'PHP'
@ini_set('display_errors', '0');
error_reporting(0);
require '/var/www/html/wp-load.php';
global $wpdb;
$upload = wp_upload_dir();
$basedir = rtrim((string) ($upload['basedir'] ?? ''), '/');
$images = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'");
$missing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND (m.meta_id IS NULL OR m.meta_value = '' OR m.meta_value NOT LIKE '%sizes%')");
$file = (string) $wpdb->get_var("SELECT m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wp_attached_file' AND p.post_mime_type LIKE 'image/%' ORDER BY m.post_id DESC LIMIT 1");
echo 'TALKSASA_WPMEDIA='.wp_json_encode([
    'gd' => extension_loaded('gd'),
    'imagick' => extension_loaded('imagick'),
    'editor' => (bool) wp_image_editor_supports(array('mime_type' => 'image/jpeg')),
    'home' => (string) get_option('home'),
    'siteurl' => (string) get_option('siteurl'),
    'basedir' => $basedir,
    'baseurl' => (string) ($upload['baseurl'] ?? ''),
    'uploads_error' => (string) ($upload['error'] ?: ''),
    'images' => $images,
    'missing_sizes' => $missing,
    'latest_file' => $file,
    'latest_file_exists' => ($file !== '' && $basedir !== '') ? file_exists($basedir.'/'.$file) : null,
]);
PHP;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function probeWordPressMedia(SSHService $ssh, $deployment): ?array
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        try {
            $output = $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -u www-data -T '.escapeshellarg($deployment->container_name)
                .' php -d display_errors=0 -r '.escapeshellarg($this->wordPressMediaProbeScript()),
                60
            );
        } catch (\Throwable) {
            return null;
        }

        if (preg_match('/TALKSASA_WPMEDIA=(\{.*\})/s', $output, $matches) !== 1) {
            return null;
        }

        $media = json_decode($matches[1], true);

        return is_array($media) ? $media : null;
    }

    /**
     * @param  array<string, mixed>  $media
     * @return list<array<string, mixed>>
     */
    private function wordPressMediaFindings(array $media, ?string $liveUrl): array
    {
        $findings = [];
        $missing = (int) ($media['missing_sizes'] ?? 0);
        $images = (int) ($media['images'] ?? 0);

        if (($media['editor'] ?? true) === false) {
            $findings[] = [
                'id' => 'live_wordpress_image_editor_missing',
                'severity' => 'critical',
                'title' => 'WordPress cannot process images',
                'summary' => 'PHP in this container has no working image library, so WordPress saves the original '
                    .'upload but never generates thumbnails. The Media Library then shows a grey document icon '
                    .'instead of the picture, and themes that request a sized image render nothing.',
                'evidence' => array_values(array_filter([
                    'GD: '.(($media['gd'] ?? false) ? 'loaded' : 'missing'),
                    'Imagick: '.(($media['imagick'] ?? false) ? 'loaded' : 'missing'),
                    $images > 0 ? $missing.' of '.$images.' images have no generated sizes' : null,
                ])),
                'treat_action' => 'fix_wordpress_media_processing',
                'treat_label' => 'Repair image processing',
                'manual_steps' => [
                    'Click Repair image processing — installs GD, restarts PHP, and rebuilds missing thumbnails.',
                    'Re-upload is not needed; the original files are still on disk.',
                ],
                'source' => 'live',
            ];
        } elseif ($missing > 0) {
            $findings[] = [
                'id' => 'live_wordpress_missing_thumbnails',
                'severity' => 'warning',
                'title' => $missing.' image'.($missing === 1 ? '' : 's').' have no generated sizes',
                'summary' => 'These attachments exist but never got their thumbnail set, which is why the Media '
                    .'Library shows a document icon and posts render an empty image. Regenerating rebuilds the '
                    .'sizes from the original files already on disk.',
                'evidence' => [$missing.' of '.$images.' image attachments are missing sizes'],
                'treat_action' => 'regenerate_wordpress_thumbnails',
                'treat_label' => 'Rebuild thumbnails',
                'manual_steps' => [
                    'Click Rebuild thumbnails, then reload the Media Library.',
                    'In Terminal the same fix is: wp media regenerate --yes --only-missing',
                ],
                'source' => 'live',
            ];
        }

        if (($media['latest_file_exists'] ?? null) === false) {
            $findings[] = [
                'id' => 'live_wordpress_media_files_missing',
                'severity' => 'critical',
                'title' => 'Uploaded media files are not on the application disk',
                'summary' => 'The database lists attachments, but the newest file is missing from the uploads '
                    .'directory. That happens when media was written into a container-only volume, or when a '
                    .'database was restored without its wp-content files. Redeploy the stack so wp-content is '
                    .'served from the application directory, then restore the files from a backup.',
                'evidence' => array_values(array_filter([
                    'expected file: '.rtrim((string) ($media['basedir'] ?? ''), '/').'/'.(string) ($media['latest_file'] ?? ''),
                    (string) ($media['uploads_error'] ?? '') !== '' ? 'uploads error: '.$media['uploads_error'] : null,
                ])),
                'manual_steps' => [
                    'Redeploy stack — wp-content is then bind-mounted from the application directory.',
                    'Restore wp-content/uploads from the Backups tab if the files were lost.',
                ],
                'source' => 'live',
            ];
        }

        $expected = $liveUrl;
        $home = rtrim((string) ($media['home'] ?? ''), '/');
        if ($expected !== null && $home !== '' && rtrim($expected, '/') !== $home) {
            $findings[] = [
                'id' => 'live_wordpress_site_url_mismatch',
                'severity' => 'warning',
                'title' => 'WordPress address does not match the live domain',
                'summary' => 'WordPress still builds URLs from '.$home.' while the site is served from '
                    .rtrim($expected, '/').'. Media and assets then load from the old address, so images appear '
                    .'broken and admin uploads can be redirected before they finish.',
                'evidence' => [
                    'WordPress home: '.$home,
                    'WordPress siteurl: '.rtrim((string) ($media['siteurl'] ?? ''), '/'),
                    'live URL: '.rtrim($expected, '/'),
                ],
                'treat_action' => 'fix_wordpress_site_url',
                'treat_label' => 'Fix site URLs',
                'manual_steps' => [
                    'Click Fix site URLs — updates home/siteurl and rewrites stored URLs (GUIDs untouched).',
                    'Clear any caching plugin afterwards.',
                ],
                'source' => 'live',
            ];
        }

        return $findings;
    }

    /**
     * Log lines proving the container is mid-bootstrap (dependency install or production
     * build). A first Vite/Next build easily runs for minutes, so an unreachable port
     * during that window is progress, not a failure.
     */
    private const BOOTSTRAP_LOG_PATTERNS = [
        '/npm (warn|notice|info)/i',
        '/idealTree|reify|audited \d+ packages|added \d+ packages|changed \d+ packages/i',
        '/(vite|next|nuxt|astro|tsc|webpack) build|building for production|creating an optimized production build/i',
        '/transforming\b|rendering chunks|computing gzip size/i',
        '/(collecting|downloading|installing) [a-z0-9._-]+/i',
        '/(bundle|gem) install|fetching gem/i',
    ];

    /**
     * @return string|null the log line that proves work is in progress
     */
    private function detectBootstrapActivity(SSHService $ssh, $deployment): ?string
    {
        try {
            $logs = trim($ssh->exec(
                'docker logs --since 120s --tail 20 '
                .escapeshellarg((string) $deployment->container_name).' 2>&1 || true',
                20
            ));
        } catch (\Throwable) {
            return null;
        }

        foreach (array_reverse(preg_split("/\r\n|\n|\r/", $logs) ?: []) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            foreach (self::BOOTSTRAP_LOG_PATTERNS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    return mb_substr($line, 0, 200);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private function withBootstrapState(SSHService $ssh, $deployment, array $probe): array
    {
        if (! $probe['reachable']) {
            $probe['bootstrapping'] = $this->detectBootstrapActivity($ssh, $deployment);
        }

        return $probe;
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function bootstrapProgressMessage(array $probe): string
    {
        return 'The container is still installing dependencies and building the app on '
            .'127.0.0.1:'.$probe['assigned_port'].' — this can take several minutes on the first '
            .'production build. Watch the Logs tab; the app answers as soon as the build finishes.'
            .(is_string($probe['bootstrapping']) ? ' Last log — '.$probe['bootstrapping'] : '');
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return list<string>
     */
    private function upstreamEvidence(array $probe, int $httpStatus, string $accessUrl): array
    {
        $localStatus = $probe['local_status'] === null || $probe['local_status'] === 0
            ? 'no response (connection refused)'
            : 'HTTP '.$probe['local_status'];

        return array_values(array_filter([
            'public URL: HTTP '.$httpStatus.($accessUrl !== '' ? ' — '.$accessUrl : ''),
            'app port 127.0.0.1:'.$probe['assigned_port'].' → '.$localStatus,
            $probe['stopped'] !== []
                ? 'not running: '.implode(', ', array_slice($probe['stopped'], 0, 4))
                : null,
            $probe['publishes_port'] === false
                ? 'no container publishes host port '.$probe['assigned_port']
                : null,
            ...$probe['crash_logs'],
        ]));
    }

    /**
     * Applications need a few seconds to bind their port after a restart or recreate.
     *
     * @return array<string, mixed>
     */
    private function waitForUpstream(SSHService $ssh, $deployment, int $attempts = 5): array
    {
        $probe = $this->probeUpstream($ssh, $deployment);

        for ($attempt = 1; $attempt < $attempts && ! $probe['reachable']; $attempt++) {
            sleep(3);
            $probe = $this->probeUpstream($ssh, $deployment);
        }

        return $this->withBootstrapState($ssh, $deployment, $probe);
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function upstreamFailureMessage(array $probe): string
    {
        if (is_string($probe['bootstrapping'] ?? null)) {
            return $this->bootstrapProgressMessage($probe);
        }

        $reasons = array_values(array_filter([
            $probe['stopped'] !== []
                ? 'not running: '.implode(', ', array_slice($probe['stopped'], 0, 3))
                : null,
            $probe['publishes_port'] === false
                ? 'no container publishes host port '.$probe['assigned_port']
                : null,
            $probe['crash_logs'] !== []
                ? 'last log — '.mb_substr((string) $probe['crash_logs'][count($probe['crash_logs']) - 1], 0, 200)
                : null,
        ]));

        return 'The app is still not answering on 127.0.0.1:'.$probe['assigned_port'].'. '
            .($reasons === []
                ? 'The container starts but never binds its port — check the start command and Logs.'
                : implode(' | ', $reasons));
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
                'id' => 'vite_missing_in_production',
                'severity' => 'critical',
                'stacks' => ['nodejs', '*'],
                'patterns' => [
                    '/Cannot find package [\'"]vite[\'"]/i',
                    '/ERR_MODULE_NOT_FOUND[^\n]*vite/i',
                    '/vite:\s+not found/i',
                    '/Cannot find module [\'"]vite[\'"]/i',
                ],
                'title' => 'Vite missing from production start',
                'summary' => 'This app’s start command needs Vite at runtime (`tsx server.ts` or `node dist/server.cjs`), but a production install stripped it. Talksasa keeps the app’s own server (so `/api` routes keep working) and reinstalls Vite plus the build.',
                'treat_action' => 'fix_vite_production_runtime',
                'treat_label' => 'Repair Vite runtime',
                'manual_steps' => [
                    'Click Repair Vite runtime — rebuilds, keeps your start command, installs Vite, and recreates the container.',
                    'No repo change is required for AI Studio / react-example style apps.',
                ],
            ],
            [
                'id' => 'wordpress_image_post_processing_failed',
                'severity' => 'warning',
                'stacks' => ['wordpress'],
                'patterns' => [
                    '/post-processing of the image failed/i',
                    '/The uploaded file could not be moved/i',
                    '/Could not create thumbnail/i',
                    '/image_resize_dimensions|wp_get_image_editor.*(failed|error)/i',
                ],
                'title' => 'WordPress could not build image sizes',
                'summary' => 'The upload was stored but WordPress failed while creating thumbnails, usually because PHP has no working image library or ran out of memory. Media then shows a document icon instead of the picture.',
                'treat_action' => 'fix_wordpress_media_processing',
                'treat_label' => 'Repair image processing',
                'manual_steps' => [
                    'Click Repair image processing — installs GD, restarts PHP, and rebuilds missing thumbnails.',
                    'Very large images can also exceed memory; try a smaller source file to confirm.',
                ],
            ],
            [
                'id' => 'vite_host_not_allowed',
                'severity' => 'critical',
                'stacks' => ['nodejs', '*'],
                'patterns' => [
                    '/Blocked request\.\s*This host[^\n]*is not allowed/i',
                    '/add [^\n]* to (preview|server)\.allowedHosts/i',
                ],
                'title' => 'Vite is blocking your domain',
                'summary' => 'Vite only answers for hostnames on its allowlist, and our proxy forwards your real domain. Talksasa passes every bound domain to the preview server, so re-applying the runtime clears this.',
                'treat_action' => 'fix_vite_production_runtime',
                'treat_label' => 'Allow bound domains',
                'manual_steps' => [
                    'Click Allow bound domains — the container restarts with your domains on the Vite allowlist.',
                    'If you added the domain seconds ago, bind it in the Domains tab first, then re-run this fix.',
                    'Repo-level alternative: set preview.allowedHosts in vite.config.',
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

            $appDirectory = app(ContainerAppDirectoryService::class);
            $hostAppPath = $appDirectory->hostAppPath($deployment);
            $appDirectory->ensureLaravelWritableLayoutOnHost($ssh, $hostAppPath);
            $appDirectory->normalizeLaravelPermissions($ssh, $deployment);
            $this->clearLaravelCachesQuietly($service, $ssh);

            return [
                'success' => true,
                'message' => 'Created Laravel cache/storage directories, fixed permissions, and cleared config. Re-scan after a reload.',
            ];
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
    private function treatRefreshDomainProxy(Service $service): array
    {
        try {
            app(WordPressContainerHardeningService::class)->ensureNginxUploadLimits($service);

            return [
                'success' => true,
                'message' => 'Web proxy refreshed for every bound domain. Retry the upload.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to refresh the web proxy: '.$e->getMessage()];
        }
    }

    /**
     * Install a PHP image library, reload PHP, then rebuild the thumbnails that were
     * skipped while WordPress had no image editor.
     *
     * @return array{success: bool, message: string}
     */
    private function treatFixWordPressMediaProcessing(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $ssh = SSHService::forNode($deployment->node);
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        try {
            app(ContainerPhpExtensionsService::class)->ensureExtensionInstalled($ssh, $deployment, 'gd');

            $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose restart '.escapeshellarg($deployment->container_name),
                180
            );

            $rebuilt = $this->regenerateWordPressThumbnails($ssh, $deployment);

            return [
                'success' => true,
                'message' => 'Image processing repaired. '.$rebuilt,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to repair image processing: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatRegenerateWordPressThumbnails(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $ssh = SSHService::forNode($deployment->node);

        try {
            return ['success' => true, 'message' => $this->regenerateWordPressThumbnails($ssh, $deployment)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to rebuild thumbnails: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    private function regenerateWordPressThumbnails(SSHService $ssh, $deployment): string
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        app(WordPressAppInstallationService::class)->ensureWpCli($ssh, $containerPath, $deployment->container_name);

        $output = $ssh->exec(
            'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u www-data -T '.escapeshellarg($deployment->container_name)
            .' sh -lc '.escapeshellarg(
                'wp media regenerate --yes --only-missing --skip-delete --path=/var/www/html 2>&1 | tail -n 3'
            ),
            600,
            false
        );

        $summary = trim((string) preg_replace('/\s+/', ' ', $output));

        return $summary !== ''
            ? 'Thumbnails rebuilt: '.mb_substr($summary, 0, 200)
            : 'Thumbnails rebuilt for images that were missing sizes.';
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatFixWordPressSiteUrl(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $target = app(WordPressAdminLoginService::class)->resolvePublicBaseUrl($service);
        if ($target === null) {
            return ['success' => false, 'message' => 'No public URL is bound to this site yet.'];
        }

        $target = rtrim($target, '/');
        $ssh = SSHService::forNode($deployment->node);
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        try {
            $media = $this->probeWordPressMedia($ssh, $deployment);
            $current = rtrim((string) ($media['home'] ?? ''), '/');

            if ($current === $target) {
                return ['success' => true, 'message' => 'WordPress already points at '.$target.'.'];
            }

            app(WordPressAppInstallationService::class)->ensureWpCli($ssh, $containerPath, $deployment->container_name);

            $commands = [
                'wp option update home '.escapeshellarg($target).' --path=/var/www/html',
                'wp option update siteurl '.escapeshellarg($target).' --path=/var/www/html',
            ];

            if ($current !== '') {
                // GUIDs must never be rewritten — feed readers treat them as permanent ids.
                $commands[] = 'wp search-replace '.escapeshellarg($current).' '.escapeshellarg($target)
                    .' --all-tables --precise --skip-columns=guid --report-changed-only --path=/var/www/html';
            }

            $commands[] = 'wp cache flush --path=/var/www/html || true';

            $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -u www-data -T '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg(implode(' && ', $commands)),
                300,
                false
            );

            return [
                'success' => true,
                'message' => 'WordPress now serves URLs from '.$target.'. Reload the site and clear any page cache.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to fix site URLs: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Repair Vite-dependent starts: rebuild, keep the app server (and /api routes), install Vite.
     *
     * @return array{success: bool, message: string}
     */
    private function treatFixViteProductionRuntime(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $ssh = SSHService::forNode($deployment->node);
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $hostAppPath = $containerPath.'/app';

        try {
            // Drop the SPA build so container bootstrap reinstalls with Vite present and rebuilds.
            @$ssh->exec(
                'rm -rf '.escapeshellarg($hostAppPath.'/dist').' '
                .escapeshellarg($hostAppPath.'/.vite').' '
                .escapeshellarg($hostAppPath.'/node_modules'),
                60
            );

            $message = app(ContainerDeploymentService::class)
                ->refreshApplicationRuntimeCompose($service, $deployment, $ssh);

            $deployment->refresh();
            if ((int) ($deployment->assigned_port ?? 0) > 0) {
                $probe = $this->waitForUpstream($ssh, $deployment, 12);
                if (! $probe['reachable']) {
                    return [
                        // A running install/build is the expected state here: the fix drops the
                        // stale install so the container rebuilds before the app server starts.
                        'success' => is_string($probe['bootstrapping']),
                        'message' => ($message !== '' ? $message.' ' : '')
                            .$this->upstreamFailureMessage($probe),
                    ];
                }
            }

            return [
                'success' => true,
                'message' => $message !== ''
                    ? $message
                    : 'Repaired Vite runtime, kept the app start command, and recreated the container.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Vite runtime repair failed: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    private function treatRestartApplication(Service $service): array
    {
        $deployment = $service->containerDeployment;

        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        try {
            app(ContainerDeploymentService::class)->restart($service);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Restart failed: '.$e->getMessage()];
        }

        if ((int) ($deployment->assigned_port ?? 0) <= 0) {
            return ['success' => true, 'message' => 'Application restart requested.'];
        }

        $ssh = SSHService::forNode($deployment->node);

        try {
            $probe = $this->waitForUpstream($ssh, $deployment);

            if ($probe['reachable']) {
                return ['success' => true, 'message' => 'Application restarted and is answering on its port again.'];
            }

            if (is_string($probe['bootstrapping'])) {
                return ['success' => true, 'message' => $this->bootstrapProgressMessage($probe)];
            }

            // `docker compose restart` cannot create a missing container or apply a changed
            // port mapping, so report the real state instead of a misleading success.
            return [
                'success' => false,
                'message' => $this->upstreamFailureMessage($probe).' Try Recreate containers.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => true,
                'message' => 'Application restart requested, but verification failed: '.$e->getMessage(),
            ];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatRecreateApplication(Service $service): array
    {
        $deployment = $service->containerDeployment;

        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $ssh = SSHService::forNode($deployment->node);

        try {
            // up -d recreates missing containers and applies the current port mapping,
            // which a plain restart cannot do. Volumes and the database are preserved.
            app(ContainerDeploymentService::class)->startComposeStack($ssh, $service, $deployment);

            if ((int) ($deployment->assigned_port ?? 0) <= 0) {
                return ['success' => true, 'message' => 'Containers recreated.'];
            }

            $probe = $this->waitForUpstream($ssh, $deployment, 12);

            if ($probe['reachable']) {
                return [
                    'success' => true,
                    'message' => 'Containers recreated and the app is answering on its port again.',
                ];
            }

            return [
                'success' => is_string($probe['bootstrapping']),
                'message' => $this->upstreamFailureMessage($probe),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Recreate failed: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }
}
