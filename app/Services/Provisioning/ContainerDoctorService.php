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

        $live = $this->collectLiveFindings($service, $logs);
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

        // Recreate / runtime rebuild / restart can revive a crash-looping stack.
        if (! $deployment->isRunning()
            && ! in_array($action, [
                'recreate_application',
                'fix_vite_production_runtime',
                'switch_php_production_runtime',
                'restart_application',
            ], true)) {
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
            'ensure_storage_link' => $this->treatEnsureStorageLink($service),
            'fix_wordpress_permissions' => $this->treatFixWordPressPermissions($service),
            'fix_wordpress_media_processing' => $this->treatFixWordPressMediaProcessing($service),
            'regenerate_wordpress_thumbnails' => $this->treatRegenerateWordPressThumbnails($service),
            'fix_wordpress_site_url' => $this->treatFixWordPressSiteUrl($service),
            'fix_laravel_app_url' => $this->treatFixLaravelAppUrl($service),
            'refresh_domain_proxy' => $this->treatRefreshDomainProxy($service),
            'restart_application' => $this->treatRestartApplication($service),
            'recreate_application' => $this->treatRecreateApplication($service),
            'fix_vite_production_runtime' => $this->treatFixViteProductionRuntime($service),
            'switch_php_production_runtime' => $this->treatSwitchPhpProductionRuntime($service),
            'run_migrations' => $this->treatRunMigrations($service),
            'migrate_fresh' => $this->treatMigrateFresh($service),
            'use_file_cache' => $this->treatUseFileCache($service),
            'tune_request_concurrency' => $this->treatTuneRequestConcurrency($service),
            'fix_compose_interpolation' => $this->treatFixComposeInterpolation($service),
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

            if (($rule['id'] ?? '') === 'mysql_unix_socket_missing' && $service) {
                $containerName = (string) ($service->containerDeployment?->container_name ?? '');
                if ($containerName !== '') {
                    $dns = app(ContainerDeploymentService::class)->sidecarDnsHost($containerName);
                    $last = count($findings) - 1;
                    $findings[$last]['summary'] = str_replace('{app}-db', $dns, (string) $findings[$last]['summary']);
                    $findings[$last]['manual_steps'] = array_map(
                        fn (string $step) => str_replace('{app}-db', $dns, $step),
                        $findings[$last]['manual_steps'] ?? []
                    );
                }
            }
        }

        if ($this->findingsContain($findings, ['nginx_boot_failed'])) {
            $findings = array_values(array_filter(
                $findings,
                fn (array $f) => ($f['id'] ?? '') !== 'php_builtin_dev_server'
            ));
        }

        if ($this->findingsContain($findings, ['mysql_unix_socket_missing'])) {
            $findings = array_values(array_filter(
                $findings,
                fn (array $f) => ($f['id'] ?? '') !== 'mysql_connection_refused'
            ));
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

        $databaseLooksHealthy = $database !== ''
            && $database === $canonical['database']
            && $database !== $username
            && ! preg_match('/^u\d+_s\d+$/', $database);

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

            // Do not mark mysql_access_denied / postgres password failures stale just because
            // env keys agree with each other. DirectAdmin conversions often have aligned
            // DB_PASSWORD/MYSQL_PASSWORD while `user`@`localhost` still rejects Docker overlay IPs.
        }
        unset($finding);

        usort($findings, function (array $a, array $b): int {
            $order = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
        });

        return $findings;
    }

    /**
     * Live probes against Docker state, on-disk .env, PDO connectivity, HTTP status, and empty schema.
     * Crash-looping stacks still get infrastructure inspection — docker exec probes are skipped
     * until the app container stays up.
     *
     * @return array{findings: list<array<string, mixed>>, checks: array<string, mixed>}
     */
    public function collectLiveFindings(Service $service, string $logs = ''): array
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
            'php_production_runtime' => null,
            'php_start_command' => null,
            'restarting' => null,
            'container_image' => null,
            'expected_image' => null,
            'disk_percent' => null,
            'publishes_port' => null,
            'session_driver' => null,
            'cache_store' => null,
            'http_2xx_count' => null,
            'http_5xx_count' => null,
        ];
        $findings = [];

        if (! $deployment?->node) {
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
        $containerReady = $deployment->isRunning();

        try {
            $upstream = $this->withBootstrapState($ssh, $deployment, $this->probeUpstream($ssh, $deployment));
            $snapshot = $this->inspectInfrastructureSnapshot($ssh, $service, $deployment, $upstream);
            $this->mergeInfrastructureSnapshotIntoChecks($checks, $snapshot, $upstream);
            $checks['upstream_reachable'] = $upstream['reachable'];
            $checks['upstream_local_status'] = $upstream['local_status'];
            $checks['containers_stopped'] = $upstream['stopped'];
            $checks['bootstrap_in_progress'] = is_string($upstream['bootstrapping'] ?? null);

            $findings = app(ContainerDoctorInfrastructureAnalyzer::class)->findings($logs, $stack, $snapshot);

            $containerReady = ($snapshot['running'] ?? false) === true
                && ($snapshot['restarting'] ?? false) !== true;

            if ($containerReady) {
                $proxyFinding = $this->staleProxyVhostFinding($ssh, $service, $deployment, $checks);
                if ($proxyFinding !== null) {
                    $findings[] = $this->withLaravelProxyBufferContext($proxyFinding, $stack);
                }
            }

            if ($containerReady && $stack === 'wordpress') {
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

            if ($containerReady && in_array($stack, ['laravel', 'php'], true)
                && ! $this->findingsContain($findings, ['nginx_boot_failed'])) {
                $phpFinding = $this->phpBuiltinDevServerFinding($ssh, $deployment, $stack, $checks);
                if ($phpFinding !== null) {
                    $findings[] = $phpFinding;
                }
            }

            if ($containerReady) {
                $platformEnv = is_array($deployment->env_values) ? $deployment->env_values : [];
                $liveEnv = $this->readLiveAppEnvironment($ssh, $deployment, $service);
                $checks['env_source'] = $liveEnv === [] ? 'platform' : 'app_dotenv';
                $mergedEnv = $liveEnv === [] ? $platformEnv : array_merge($platformEnv, $liveEnv);
                $checks['session_driver'] = strtolower(trim((string) ($mergedEnv['SESSION_DRIVER'] ?? '')));
                $checks['app_url'] = trim((string) ($mergedEnv['APP_URL'] ?? ''));
                $checks['asset_url'] = trim((string) ($mergedEnv['ASSET_URL'] ?? ''));
                $checks['cache_store'] = strtolower(trim((string) (
                    $mergedEnv['CACHE_STORE'] ?? $mergedEnv['CACHE_DRIVER'] ?? ''
                )));
                if ($deploymentService->envUsesMysqlUnixSocket($mergedEnv)
                    || $this->findingsContain($findings, ['mysql_unix_socket_missing'])) {
                    $unique = $deploymentService->sidecarDnsHost((string) $deployment->container_name);
                    $envStillSocket = $deploymentService->envUsesMysqlUnixSocket($mergedEnv);
                    $summary = $envStillSocket
                        ? ('Environment still points at a DirectAdmin unix socket (`/var/lib/mysql/mysql.sock`). '
                            .'That file is not in this container — MySQL is TCP at '.$unique
                            .'. Restart writes DB_HOST='.$unique.', rewrites hardcoded socketPath in app source, '
                            .'preloads a mysql TCP shim, and recreates the app (MySQL stays up). '
                            .'Do not Repair DB credentials or Reset database.')
                        : ('Environment already uses '.$unique.', but the process still opens `/var/lib/mysql/mysql.sock` — '
                            .'the app hardcodes the DirectAdmin socket (typical after Git pull). '
                            .'Restart rewrites those files, preloads a mysql TCP shim, and recreates the app only. '
                            .'Do not Reset database.');
                    $finding = [
                        'id' => 'mysql_unix_socket_missing',
                        'severity' => 'critical',
                        'title' => 'App is using a MySQL unix socket that does not exist in Docker',
                        'summary' => $summary,
                        'evidence' => array_values(array_filter([
                            trim((string) ($mergedEnv['DB_HOST'] ?? '')) !== '' ? 'DB_HOST='.(string) $mergedEnv['DB_HOST'] : null,
                            trim((string) ($mergedEnv['DB_SOCKET'] ?? '')) !== '' ? 'DB_SOCKET='.(string) $mergedEnv['DB_SOCKET'] : null,
                            'sidecar DNS='.$unique,
                            $envStillSocket ? 'env still uses unix socket' : 'env pinned; source still uses mysql.sock',
                        ])),
                        'treat_action' => 'restart_application',
                        'treat_label' => 'Restart application',
                        'manual_steps' => [
                            'Click Restart application — pins DB_HOST to '.$unique.', rewrites hardcoded sockets, preloads a TCP shim, recreates the app, and leaves MySQL running.',
                            'Re-scan. Logs should show a TCP connection, not ENOENT /var/lib/mysql/mysql.sock.',
                            'Do not Reset database.',
                        ],
                        'source' => 'live',
                    ];
                    $findings = array_values(array_filter(
                        $findings,
                        fn (array $f) => ($f['id'] ?? '') !== 'mysql_unix_socket_missing'
                    ));
                    $findings[] = $finding;
                }
                $runtimeSession = $this->probeLaravelSessionDriver($ssh, $deployment);
                if ($runtimeSession !== null) {
                    $checks['session_driver_runtime'] = $runtimeSession;
                }

                $runtimeCache = $this->probeLaravelCacheStore($ssh, $deployment);
                if ($runtimeCache !== null) {
                    $checks['cache_store_runtime'] = $runtimeCache;
                }

                $runtimeDbHost = $this->probeLaravelDatabaseHost($ssh, $deployment);
                if ($runtimeDbHost !== null) {
                    $checks['laravel_db_host'] = $runtimeDbHost;
                }

                if (in_array($stack, ['laravel', 'php'], true)
                    && $this->isAmbiguousLaravelDatabaseHost($runtimeDbHost)) {
                    $unique = app(ContainerDeploymentService::class)
                        ->sidecarDnsHost((string) $deployment->container_name);
                    $findings[] = [
                        'id' => 'stale_laravel_db_host',
                        'severity' => 'critical',
                        'title' => 'Laravel is still connecting to hostname db',
                        'summary' => 'Doctor PDO can reach this stack’s sidecar (unique DNS '.$unique
                            .'), but Laravel bootstrapped config still uses `db`. On talksasa-net that alias is shared by every site, so the public URL 1045s or 2002s while live checks say DB OK. '
                            .'Restart writes DB_HOST='.$unique.' into compose and recreates the app — MySQL stays up.',
                        'evidence' => [
                            'laravel database.host='.$runtimeDbHost,
                            'sidecar DNS='.$unique,
                        ],
                        'treat_action' => 'restart_application',
                        'treat_label' => 'Restart application',
                        'manual_steps' => [
                            'Click Restart application — pins DB_HOST to '.$unique.', deletes config cache, recreates the app, and leaves MySQL running.',
                            'Re-scan. laravel.log should show mysql:host='.$unique.' not mysql:host=db.',
                        ],
                        'source' => 'live',
                    ];
                }

                if (in_array($stack, ['laravel', 'php'], true)
                    && $runtimeSession === 'database'
                    && ($checks['session_driver'] ?? '') === 'cookie') {
                    $findings[] = [
                        'id' => 'stale_laravel_config_cache',
                        'severity' => 'critical',
                        'title' => 'Laravel is still using database sessions',
                        'summary' => '`.env` says SESSION_DRIVER=cookie, but PHP-FPM still has SESSION_DRIVER=database from the compose environment created with the container. '
                            .'Dotenv does not override existing env, so `/get-total-unread` 500s with `select * from sessions` while Doctor shows Session: cookie. '
                            .'Restart recreates the app container only (picks up cookie/file drivers) — MySQL is left running.',
                        'evidence' => [
                            'env SESSION_DRIVER=cookie',
                            'runtime session.driver=database',
                        ],
                        'treat_action' => 'restart_application',
                        'treat_label' => 'Restart application',
                        'manual_steps' => [
                            'Click Restart application — writes SESSION_DRIVER=file into compose, recreates the app container, and leaves MySQL running.',
                            'Re-scan. Live checks should show Session: cookie and runtime session.driver=cookie.',
                        ],
                        'source' => 'live',
                    ];
                }

                if (in_array($stack, ['laravel', 'php'], true)
                    && $runtimeCache === 'database') {
                    $envCache = (string) ($checks['cache_store'] ?? '');
                    $findings[] = [
                        'id' => 'stale_laravel_database_cache',
                        'severity' => 'critical',
                        'title' => 'Laravel is still using the database cache',
                        'summary' => 'php artisan cache:clear / optimize:clear run `delete from cache` and 1045 when MySQL rejects the overlay IP. '
                            .($envCache === 'file'
                                ? '`.env` already says CACHE_STORE=file, but PHP-FPM / docker exec still have CACHE_STORE=database from compose.'
                                : 'CACHE_STORE is still database. Switch to file so artisan and HTTP do not need the cache table.')
                            .' Terminal `cd logs` also fails until storage/logs exists and is owned by www-data.',
                        'evidence' => [
                            'env CACHE_STORE='.($envCache !== '' ? $envCache : '(unset)'),
                            'runtime cache.default=database',
                        ],
                        'treat_action' => 'use_file_cache',
                        'treat_label' => 'Switch cache to file',
                        'manual_steps' => [
                            'Click Switch cache to file — writes CACHE_STORE=file into compose, creates storage/logs, and recreates the app (MySQL stays up).',
                            'Then artisan cache:clear will use the file driver instead of DELETE FROM cache.',
                        ],
                        'source' => 'live',
                    ];
                }

                if (in_array($stack, ['laravel', 'php'], true)) {
                    $layout = $this->probeLaravelWritableLayout($ssh, $deployment);
                    $checks['storage_ok'] = $layout['ok'];
                    if (! $layout['ok']) {
                        $findings[] = [
                            'id' => 'live_storage_not_writable',
                            'severity' => 'warning',
                            'title' => 'www-data cannot write storage / logs',
                            'summary' => 'Laravel storage/logs or bootstrap/cache is missing or not writable by www-data. '
                                .'`cd logs` from /app fails until Doctor creates storage/logs and a logs symlink. '
                                .'This is separate from MySQL 1045 — Fix storage permissions does not need a working DB.',
                            'evidence' => $layout['evidence'],
                            'treat_action' => 'fix_storage_permissions',
                            'treat_label' => 'Fix storage permissions',
                            'manual_steps' => [
                                'Click Fix storage permissions — creates storage/logs, chowns www-data, and links /app/logs → storage/logs.',
                                'If artisan cache:clear still 1045s, click Switch cache to file (database cache is not a filesystem permission).',
                            ],
                            'source' => 'live',
                        ];
                    }
                }

                if ($databaseTemplate) {
                    $probeEnv = $this->envForRuntimeDatabaseProbe(
                        $this->overlayPanelDatabaseCredentials($platformEnv, $mergedEnv),
                        (string) $databaseTemplate->type
                    );
                    $probeEnv['DB_HOST'] = $deploymentService->applicationDatabaseHost(
                        $probeEnv,
                        (string) $deployment->container_name
                    );
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
                                    'Click Repair DB credentials — creates the missing DB, resets the role password, rewrites .env, and writes DB_* into compose so PHP-FPM matches GRANT.',
                                    'Do not Reset database — that wipes existing tables. Re-scan and Repair again if 1045 persists.',
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
            }

            $httpStatus = $this->probeHttpStatus($ssh, $deployment);
            $checks['http_status'] = $httpStatus;

            if (in_array($stack, ['laravel', 'php'], true)
                && $httpStatus === 403
                && ! $this->findingsContain($findings, ['laravel_docroot_not_public'])) {
                $cmd = (string) ($checks['php_start_command'] ?? '');
                $wrongRoot = (bool) preg_match('/talksasa-php-server[^\n]*\/app(?:["\s]|$)/', $cmd)
                    || (bool) preg_match('/directory index of "\/app\/" is forbidden/i', $logs);
                if ($wrongRoot) {
                    $findings[] = [
                        'id' => 'laravel_docroot_not_public',
                        'severity' => 'critical',
                        'title' => 'nginx is serving /app instead of /app/public',
                        'summary' => 'GET / returns HTTP 403 because talksasa-php-server’s document root is /app. DirectAdmin Laravel sites keep index.php in public/, so nginx refuses the directory listing and PHP never runs. Point nginx at /app/public — MySQL stays up.',
                        'evidence' => array_values(array_filter([
                            'HTTP 403',
                            $cmd !== '' ? 'start: '.$cmd : null,
                        ])),
                        'treat_action' => 'restart_application',
                        'treat_label' => 'Point nginx at public/',
                        'manual_steps' => [
                            'Click Point nginx at public/ — rewrites talksasa-php-server to public/ or public_html and recreates only the app (MySQL stays up).',
                            'Reload the site. Do not Reset database.',
                        ],
                        'source' => 'live',
                    ];
                }
            }

            if (in_array($stack, ['laravel', 'php'], true)
                && $httpStatus !== null
                && $httpStatus >= 200
                && $httpStatus < 400
                && ! $this->findingsContain($findings, ['laravel_docroot_not_public'])) {
                $liveUrl = (string) ($deployment->getAccessUrl() ?? '');
                $html = $liveUrl !== '' ? $this->probeHttpBody($ssh, $liveUrl) : null;
                if (! $this->findingsContain($findings, ['live_mixed_content_app_url'])) {
                    $mixedFinding = $this->laravelMixedContentFinding($checks, $html, $liveUrl);
                    if ($mixedFinding !== null) {
                        $findings[] = $mixedFinding;
                    }
                }
                if (! $this->findingsContain($findings, ['live_storage_assets_missing'])) {
                    $storageFinding = $this->missingStorageAssetsFinding($ssh, $logs, $html, $liveUrl);
                    if ($storageFinding !== null) {
                        $findings[] = $storageFinding;
                    }
                }
            }

            if (in_array($stack, ['laravel', 'php'], true)
                && $httpStatus !== null
                && $httpStatus < 500) {
                $loginUrl = $this->laravelLoginProbeUrl($deployment);
                $loginStatus = $loginUrl !== null ? $this->probeHttpStatusAt($ssh, $loginUrl) : null;
                if ($loginStatus !== null) {
                    $checks['http_status_home'] = $loginStatus;
                }
                $originStatus = $this->probeOriginLoginStatus($ssh, $deployment);
                if ($originStatus !== null) {
                    $checks['http_status_home_origin'] = $originStatus;
                }
                $homeStatus = in_array($originStatus, [502, 503], true) ? $originStatus : $loginStatus;
                if ($this->loginPathLooksLikeHeaderBufferFailure($httpStatus, $homeStatus)
                    && ! $this->findingsContain($findings, ['live_stale_proxy_vhost'])) {
                    $findings[] = [
                        'id' => 'login_proxy_header_too_big',
                        'severity' => 'critical',
                        'title' => 'Login URL 502s while the homepage works',
                        'summary' => 'GET / returns HTTP '.$httpStatus.', but /home returns HTTP '.$homeStatus
                            .' on the node nginx vhost. Ultimate POS login starts a session cookie larger than the default 4k/8k header buffer, so only that route 502s. Refresh web proxy rewrites 128k buffers and Restart switches sessions to file — MySQL stays up.',
                        'evidence' => [
                            'GET / → HTTP '.$httpStatus,
                            'GET /home (public) → HTTP '.($loginStatus ?? 'n/a'),
                            'GET /home (origin 127.0.0.1) → HTTP '.($originStatus ?? 'n/a'),
                            'SESSION_DRIVER='.(string) ($checks['session_driver_runtime'] ?? $checks['session_driver'] ?? 'unknown'),
                        ],
                        'treat_action' => 'refresh_domain_proxy',
                        'treat_label' => 'Refresh web proxy',
                        'manual_steps' => [
                            'Click Refresh web proxy — rewrites the nginx vhost with larger proxy buffers and reloads nginx.',
                            'Reload https://…/home. Do not Reset database.',
                        ],
                        'source' => 'live',
                    ];
                }
            }

            if ($httpStatus !== null && $httpStatus >= 500) {
                $hasEmptyDb = collect($findings)->contains(
                    fn ($f) => in_array($f['id'] ?? '', ['live_empty_database', 'live_db_config_drift'], true)
                );
                $hasCredentialDbIssue = collect($findings)->contains(
                    fn ($f) => in_array($f['id'] ?? '', ['live_db_connection_failed', 'live_env_credential_drift', 'live_missing_pdo'], true)
                );
                $hasSpecificInfra = $this->findingsContain($findings, [
                    'nginx_boot_failed',
                    'php_fpm_sock_missing',
                    'php_builtin_dev_server',
                    'container_crash_loop',
                    'oom_killed',
                    'node_disk_exhausted',
                    'port_already_allocated',
                    'docker_network_missing',
                    'missing_vendor_autoload',
                    'stale_php_runtime_image',
                    'stale_laravel_db_host',
                    'stale_laravel_config_cache',
                    'login_proxy_header_too_big',
                    'live_stale_proxy_vhost',
                    'laravel_docroot_not_public',
                    'mysql_unix_socket_missing',
                ]);

                $appErrors = $containerReady ? $this->readRecentApplicationErrors($ssh, $deployment) : [];
                $evidence = array_values(array_filter([
                    'HTTP '.$httpStatus,
                    (string) ($deployment->getAccessUrl() ?? ''),
                    ...$appErrors,
                ]));

                $checks['upstream_reachable'] = $upstream['reachable'];
                $checks['upstream_local_status'] = $upstream['local_status'];
                $checks['containers_stopped'] = $upstream['stopped'];
                $checks['bootstrap_in_progress'] = is_string($upstream['bootstrapping'] ?? null);

                if (! $upstream['reachable'] && $upstream['assigned_port'] !== null && is_string($upstream['bootstrapping'] ?? null)) {
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
                } elseif (! $upstream['reachable'] && $upstream['assigned_port'] !== null && ! $hasSpecificInfra) {
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
                    // live_db_connection_failed already tells the operator to repair credentials.
                    // A second HTTP 500 card with leftover laravel.log lines (cache path,
                    // connection refused, sessions table) looks like the treat did nothing.
                } elseif ($containerReady && ! $hasSpecificInfra) {
                    $treat = $this->resolveHttp500Treatment($checks, $appErrors, $stack);
                    $findings[] = [
                        'id' => 'live_http_5xx',
                        'severity' => 'critical',
                        'title' => 'Live check: site returns HTTP '.$httpStatus,
                        'summary' => $treat['summary'],
                        'evidence' => $evidence,
                        'treat_action' => $treat['treat_action'],
                        'treat_label' => $treat['treat_label'],
                        'manual_steps' => [
                            'In Terminal: tail -n 40 storage/logs/laravel.log',
                            'Re-scan after treating — leftover 1045/2002 lines in an old log tail are not the live cause when DB: connected.',
                            'This card stays until the public URL stops returning HTTP 5xx.',
                        ],
                        'source' => 'live',
                    ];
                }
            }

            $accessFinding = $this->intermittentAccessLogFinding(
                $logs,
                [
                    'SESSION_DRIVER' => (string) ($checks['session_driver'] ?? ''),
                    'CACHE_STORE' => (string) ($checks['cache_store'] ?? ''),
                ],
                $checks['session_driver_runtime'] ?? null
            );
            $accessSummary = $this->summarizeAccessLogs($logs);
            $checks['http_2xx_count'] = $accessSummary['status_2xx'];
            $checks['http_5xx_count'] = $accessSummary['status_5xx'];
            if ($accessFinding !== null && ! $this->findingsContain($findings, [
                'live_http_5xx',
                'live_db_connection_failed',
                'live_env_credential_drift',
            ])) {
                $findings[] = $accessFinding;
            }
        } catch (\Throwable $e) {
            if ($findings === []) {
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
            }
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
        $httpStatus = $checks['http_status'] ?? null;
        $httpOk = is_int($httpStatus) && $httpStatus >= 200 && $httpStatus < 400;

        $resolvedLogIds = [
            'postgres_password_auth_failed',
            'postgres_database_missing',
            'mysql_access_denied',
            'missing_pdo_pgsql',
        ];

        $logFindings = array_values(array_filter($logFindings, function (array $f) use ($dbOk, $liveFindings, $resolvedLogIds, $httpOk, $checks) {
            if (! empty($f['stale'])) {
                return false;
            }

            // Live Blade compile succeeded — leftover "valid cache path" lines are historical.
            if ($httpOk && ($f['id'] ?? '') === 'storage_permission_denied') {
                return false;
            }

            // Homepage 200 does not mean /get-total-unread stopped 500ing.

            if (($checks['php_production_runtime'] ?? null) === true
                && ($f['id'] ?? '') === 'php_builtin_dev_server') {
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
                    'live_db_config_drift',
                    'live_upstream_unreachable',
                    'live_bootstrap_in_progress',
                ], true)
            );

            if ($hasLiveDbSignal && in_array($f['id'] ?? '', $resolvedLogIds, true)) {
                return false;
            }

            if (($f['id'] ?? '') === 'intermittent_http_5xx') {
                if ($hasLiveDbSignal) {
                    return false;
                }
                if (collect($liveFindings)->contains(fn ($live) => ($live['id'] ?? '') === 'live_http_5xx')) {
                    return false;
                }
                $runtimeSession = strtolower(trim((string) ($checks['session_driver_runtime'] ?? '')));
                if ($dbOk && in_array($runtimeSession, ['cookie', 'array'], true)) {
                    return false;
                }
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
        $ids = array_column($merged, 'id');
        $drop = [];
        if (in_array('nginx_boot_failed', $ids, true)) {
            $drop = ['php_builtin_dev_server', 'container_crash_loop', 'live_upstream_unreachable', 'stale_php_runtime_image'];
        } elseif (in_array('mysql_unix_socket_missing', $ids, true)) {
            $drop = ['mysql_connection_refused', 'live_db_connection_failed'];
        } elseif (array_intersect($ids, [
            'php_builtin_dev_server',
            'php_fpm_sock_missing',
            'oom_killed',
            'node_disk_exhausted',
            'port_already_allocated',
            'docker_network_missing',
            'missing_vendor_autoload',
            'container_crash_loop',
        ]) !== []) {
            $drop = ['live_upstream_unreachable'];
        }

        if ($drop !== []) {
            $merged = array_values(array_filter(
                $merged,
                fn (array $f) => ! in_array($f['id'] ?? '', $drop, true)
            ));
        }

        usort($merged, function (array $a, array $b): int {
            $order = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
        });

        return $merged;
    }

    /**
     * Count 2xx vs 5xx from nginx / php-S access lines (ignores static assets).
     *
     * @return array{
     *     requests: int,
     *     status_2xx: int,
     *     status_5xx: int,
     *     mixed_paths: list<array{path: string, ok: int, fail: int, sample_fail_bytes: ?int}>
     * }
     */
    public function summarizeAccessLogs(string $logs): array
    {
        $byPath = [];
        $status2 = 0;
        $status5 = 0;
        $requests = 0;

        foreach (preg_split("/\r\n|\n|\r/", $logs) ?: [] as $line) {
            if (preg_match('/"([A-Z]+)\s+(\S+)\s+HTTP\/[\d.]+"\s+(\d{3})\s+(\d+|-)/', $line, $match) !== 1) {
                continue;
            }

            $path = explode('?', (string) $match[2], 2)[0];
            if ($path === '' || ! str_starts_with($path, '/')) {
                continue;
            }
            if (preg_match('/\.(css|js|mjs|map|png|jpe?g|gif|ico|svg|webp|woff2?|ttf|eot|ogg|mp3|wav)(\?|$)/i', $path) === 1) {
                continue;
            }

            $code = (int) $match[3];
            $bytes = $match[4] === '-' ? null : (int) $match[4];
            $requests++;
            $byPath[$path] ??= ['ok' => 0, 'fail' => 0, 'sample_fail_bytes' => null];

            if ($code >= 200 && $code < 400) {
                $status2++;
                $byPath[$path]['ok']++;
            } elseif ($code >= 500) {
                $status5++;
                $byPath[$path]['fail']++;
                $byPath[$path]['sample_fail_bytes'] ??= $bytes;
            }
        }

        $mixed = [];
        foreach ($byPath as $path => $counts) {
            if ($counts['ok'] >= 1 && $counts['fail'] >= 2) {
                $mixed[] = [
                    'path' => $path,
                    'ok' => $counts['ok'],
                    'fail' => $counts['fail'],
                    'sample_fail_bytes' => $counts['sample_fail_bytes'],
                ];
            }
        }

        usort($mixed, fn (array $a, array $b): int => $b['fail'] <=> $a['fail']);

        return [
            'requests' => $requests,
            'status_2xx' => $status2,
            'status_5xx' => $status5,
            'mixed_paths' => array_slice($mixed, 0, 8),
        ];
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, mixed>|null
     */
    public function intermittentAccessLogFinding(string $logs, array $env = [], ?string $runtimeSession = null): ?array
    {
        $summary = $this->summarizeAccessLogs($logs);
        if ($summary['mixed_paths'] === []) {
            return null;
        }

        $runtimeSession = strtolower(trim((string) $runtimeSession));
        if (in_array($runtimeSession, ['cookie', 'array'], true)) {
            return null;
        }

        $paths = $summary['mixed_paths'];
        $evidence = array_map(
            fn (array $row): string => $row['path'].' '.$row['ok'].'×2xx / '.$row['fail'].'×5xx'
                .($row['sample_fail_bytes'] !== null ? ' ('.$row['sample_fail_bytes'].'b 5xx)' : ''),
            $paths
        );

        $pollish = collect($paths)->contains(
            fn (array $row) => (bool) preg_match(
                '/get-total-unread|unread|notification|heartbeat|\/ping$|\/health/i',
                $row['path']
            )
        );

        $session = strtolower(trim((string) ($env['SESSION_DRIVER'] ?? '')));
        $cache = strtolower(trim((string) ($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? '')));
        $locking = $session === '' || in_array($session, ['file', 'database', 'redis'], true)
            || in_array($cache, ['database', 'redis'], true);

        $relaxed = $session === 'cookie' && ($cache === '' || $cache === 'file');
        $stillFailing = $summary['status_5xx'] >= 10 || count($paths) >= 1;
        if ($relaxed && ! $stillFailing) {
            return null;
        }

        $fpmStillDatabase = $runtimeSession === 'database';
        $treatAction = ($relaxed || ! $locking || $fpmStillDatabase) ? 'restart_application' : 'tune_request_concurrency';
        $treatLabel = $treatAction === 'tune_request_concurrency'
            ? 'Relax session/cache locking'
            : 'Restart application';

        $summaryText = $pollish
            ? 'The same routes return both HTTP 200 and HTTP 500 (often /get-total-unread while other tabs are open). '
                .'File or database sessions lock the worker, so Ultimate POS polling 500s while a DataTables query runs.'
            : 'Access logs show the same path succeeding and 500ing. That is worker exhaustion, a stale config cache, or session/cache locking — not a down database.';

        if ($relaxed) {
            $summaryText = $fpmStillDatabase
                ? '`.env` already has SESSION_DRIVER=cookie and file cache, but PHP-FPM / config cache still use database sessions. '
                    .'Dotenv does not override compose `environment`, so Restart must write cookie/file into compose, delete bootstrap/cache/config.php, and recreate the app (MySQL stays up).'
                : '`.env` already has SESSION_DRIVER=cookie and file cache. Mixed 200/500 in the 6h access log can be leftover from the DB outage. '
                    .'Restart writes cookie/file into compose, deletes bootstrap/cache/config.php, and recreates the app (MySQL stays up).';
        }

        if ($session !== '') {
            $summaryText .= ' SESSION_DRIVER='.$session
                .($cache !== '' ? ', CACHE_STORE='.$cache : '')
                .($runtimeSession !== '' ? ', runtime='.$runtimeSession : '').'.';
        }

        $manualSteps = $treatAction === 'restart_application'
            ? [
                'Click Restart application — writes SESSION_DRIVER=file into compose, deletes config cache, recreates the app, and leaves MySQL running.',
                'Re-scan. Live checks should show runtime session.driver=file. Older /login 500s in the 6h log window are leftover from the DB outage.',
            ]
            : [
                'Click Relax session/cache locking — writes cookie sessions + file cache into compose and recreates the app (MySQL stays up).',
                'If PHP-FPM still logs pm.max_children, upgrade the plan for more workers.',
                'In Terminal: tail -n 80 storage/logs/laravel.log around a failing /get-total-unread.',
            ];

        return [
            'id' => 'intermittent_http_5xx',
            'severity' => $summary['status_5xx'] >= 20 ? 'critical' : 'warning',
            'title' => 'Intermittent HTTP 500s on live traffic',
            'summary' => $summaryText,
            'evidence' => $evidence,
            'treat_action' => $treatAction,
            'treat_label' => $treatLabel,
            'manual_steps' => $manualSteps,
            'source' => 'live',
        ];
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
    private function readLiveAppEnvironment(SSHService $ssh, $deployment, ?Service $service = null): array
    {
        $base = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
        $relative = trim((string) (
            is_array(($service ?? $deployment->service)?->service_meta)
                ? (($service ?? $deployment->service)->service_meta['laravel_project_root'] ?? '')
                : ''
        ), '/');
        $paths = [];
        if ($relative !== '') {
            $paths[] = $base.'/'.$relative.'/.env';
        }
        $paths[] = $base.'/.env';
        $paths[] = $base.'/backend/.env';

        foreach (array_values(array_unique($paths)) as $path) {
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
     * What Laravel actually uses after bootstrap (config cache), not just .env.
     */
    public function probeLaravelSessionDriver(SSHService $ssh, $deployment): ?string
    {
        $php = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (string) config("session.driver");
PHP;
        $script = 'if [ -f /app/backend/artisan ]; then cd /app/backend; '
            .'elif [ -f /app/artisan ]; then cd /app; '
            .'else exit 1; fi; '
            .'php -r '.escapeshellarg($php);

        try {
            $driver = strtolower(trim($ssh->exec(
                'docker exec -u www-data '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg($script),
                25
            )));
        } catch (\Throwable) {
            return null;
        }

        if ($driver === '' || ! preg_match('/^[a-z0-9_-]+$/', $driver)) {
            return null;
        }

        return $driver;
    }

    /**
     * What Laravel actually uses for cache (config cache / compose env), not just .env.
     */
    public function probeLaravelCacheStore(SSHService $ssh, $deployment): ?string
    {
        $php = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (string) config("cache.default");
PHP;
        $script = 'if [ -f /app/backend/artisan ]; then cd /app/backend; '
            .'elif [ -f /app/artisan ]; then cd /app; '
            .'else exit 1; fi; '
            .'php -r '.escapeshellarg($php);

        try {
            $driver = strtolower(trim($ssh->exec(
                'docker exec -u www-data '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg($script),
                25
            )));
        } catch (\Throwable) {
            return null;
        }

        if ($driver === '' || ! preg_match('/^[a-z0-9_-]+$/', $driver)) {
            return null;
        }

        return $driver;
    }

    /**
     * Hostname Laravel's bootstrapped config uses (DATABASE_URL / compose), not Doctor PDO.
     */
    public function probeLaravelDatabaseHost(SSHService $ssh, $deployment): ?string
    {
        $php = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$default = (string) config("database.default");
echo (string) config("database.connections.{$default}.host");
PHP;
        $script = 'if [ -f /app/backend/artisan ]; then cd /app/backend; '
            .'elif [ -f /app/artisan ]; then cd /app; '
            .'else exit 1; fi; '
            .'php -r '.escapeshellarg($php);

        try {
            $host = strtolower(trim($ssh->exec(
                'docker exec -u www-data '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg($script),
                25
            )));
        } catch (\Throwable) {
            return null;
        }

        if ($host === '' || strlen($host) > 120 || ! preg_match('/^[a-z0-9._-]+$/', $host)) {
            return null;
        }

        return $host;
    }

    public function isAmbiguousLaravelDatabaseHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return false;
        }
        if (app(ContainerDeploymentService::class)->hostLooksLikeMysqlUnixSocket($host)) {
            return true;
        }
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        return $host === 'db' || $host === 'localhost' || $host === '127.0.0.1';
    }

    /**
     * @return array{ok: bool, evidence: list<string>}
     */
    public function probeLaravelWritableLayout(SSHService $ssh, $deployment): array
    {
        $script = app(ContainerAppDirectoryService::class)->laravelWritableLayoutProbeScript('/app');

        try {
            $output = trim($ssh->exec(
                'docker exec -u www-data '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg($script),
                20
            ));
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'evidence' => ['probe failed: '.mb_substr($e->getMessage(), 0, 180)],
            ];
        }

        if ($output === 'ok' || str_ends_with($output, "\nok")) {
            return ['ok' => true, 'evidence' => []];
        }

        $evidence = [];
        if (preg_match('/missing:(.*?)(?:denied:|$)/s', $output, $m) === 1) {
            $missing = trim($m[1]);
            if ($missing !== '') {
                $evidence[] = 'missing'.$missing;
            }
        }
        if (preg_match('/denied:(.*)$/s', $output, $m) === 1) {
            $denied = trim($m[1]);
            if ($denied !== '') {
                $evidence[] = 'not writable by www-data'.$denied;
            }
        }
        if ($evidence === [] && $output !== '') {
            $evidence[] = mb_substr($output, 0, 240);
        }

        return ['ok' => false, 'evidence' => $evidence !== [] ? $evidence : ['storage/logs not writable']];
    }

    /**
     * Fill host/user/database from DATABASE_URL when those DB_* keys are empty.
     * GRANT / panel DB_PASSWORD wins when it disagrees with a stale URL password.
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
                    $urlPassword = rawurldecode((string) $parts['pass']);
                    $existing = trim((string) ($probe['DB_PASSWORD'] ?? ''));
                    // GRANT / panel DB_PASSWORD wins when it disagrees with a stale DATABASE_URL.
                    // Repair can succeed on in-memory env while diagnose re-reads .env and 1045s.
                    if ($existing === '' || $existing === $urlPassword) {
                        $probe['DB_PASSWORD'] = $urlPassword;
                        if ($databaseType === 'postgresql') {
                            $probe['POSTGRES_PASSWORD'] = $urlPassword;
                        }
                        if (in_array($databaseType, ['mysql', 'mariadb'], true)) {
                            $probe['MYSQL_PASSWORD'] = $urlPassword;
                        }
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

    /**
     * Panel env_values are what GRANT used. Live .env can keep a stale DATABASE_URL
     * that previously overrode the aligned password, so Repair's in-memory probe
     * succeeded and the immediate re-diagnose 1045d.
     *
     * @param  array<string, mixed>  $panelEnv
     * @param  array<string, mixed>  $liveMerged
     * @return array<string, mixed>
     */
    public function overlayPanelDatabaseCredentials(array $panelEnv, array $liveMerged): array
    {
        $overlay = $liveMerged;
        foreach ([
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD',
            'POSTGRES_DB', 'POSTGRES_USER', 'POSTGRES_PASSWORD', 'DATABASE_URL', 'TALKSASA_DB_DNS',
        ] as $key) {
            if (isset($panelEnv[$key]) && (string) $panelEnv[$key] !== '') {
                $overlay[$key] = (string) $panelEnv[$key];
            }
        }

        return $overlay;
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

        return $this->newestUniqueLines($lines, 6);
    }

    /**
     * Prefer the newest log hits. Unique-then-tail kept a days-old
     * "valid cache path" / 2002 next to a live 1045 and misdiagnosed Repair DB.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public function newestUniqueLines(array $lines, int $limit = 6): array
    {
        $out = [];
        $seen = [];
        foreach (array_reverse($lines) as $line) {
            $line = trim((string) $line);
            if ($line === '' || isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $out[] = $line;
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_reverse($out);
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

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $ids
     */
    private function findingsContain(array $findings, array $ids): bool
    {
        return collect($findings)->contains(
            fn ($finding) => in_array($finding['id'] ?? '', $ids, true)
        );
    }

    /**
     * @param  array<string, mixed>  $upstream
     * @return array<string, mixed>
     */
    private function inspectInfrastructureSnapshot(SSHService $ssh, Service $service, $deployment, array $upstream): array
    {
        $name = (string) $deployment->container_name;
        $raw = trim($ssh->exec(
            'docker inspect --format '
            .escapeshellarg('{{.State.Status}}|||{{.State.Running}}|||{{.State.Restarting}}|||{{.State.OOMKilled}}|||{{.RestartCount}}|||{{.Config.Image}}|||{{json .Config.Cmd}}|||{{.State.Error}}')
            .' '.escapeshellarg($name).' 2>/dev/null || true',
            15
        ));

        $parts = $raw === '' ? [] : explode('|||', $raw, 8);
        $status = trim((string) ($parts[0] ?? ''));
        $running = strtolower((string) ($parts[1] ?? '')) === 'true';
        $restarting = strtolower((string) ($parts[2] ?? '')) === 'true'
            || str_contains(strtolower($status), 'restart');
        $oom = strtolower((string) ($parts[3] ?? '')) === 'true';
        $restartCount = is_numeric($parts[4] ?? null) ? (int) $parts[4] : 0;
        $image = trim((string) ($parts[5] ?? ''));
        $cmd = trim((string) ($parts[6] ?? ''), " \t\n\r\0\x0B\"[]");
        $stateError = trim((string) ($parts[7] ?? ''));

        foreach ($upstream['containers'] ?? [] as $container) {
            $containerStatus = strtolower((string) ($container['status'] ?? ''));
            if (str_contains($containerStatus, 'restarting')
                || strtolower((string) ($container['state'] ?? '')) === 'restarting') {
                $restarting = true;
            }
        }

        $processList = '';
        if ($running && ! $restarting) {
            $processList = trim($ssh->exec(
                'docker top '.escapeshellarg($name).' -eo args 2>/dev/null || true',
                15
            ));
        }

        $diskPercent = null;
        $df = trim($ssh->exec(
            "df -P /opt/talksasa 2>/dev/null | awk 'NR==2 {gsub(\"%\",\"\",\$5); print \$5}'"
            ." || df -P / 2>/dev/null | awk 'NR==2 {gsub(\"%\",\"\",\$5); print \$5}'",
            15
        ));
        if ($df !== '' && ctype_digit($df)) {
            $diskPercent = (int) $df;
        }

        $expectedImage = '';
        $template = $service->effectiveContainerTemplate() ?? $service->product?->containerTemplate;
        if ($template) {
            $provisioner = app(RuntimeImageProvisioner::class);
            if ($provisioner->usesRuntimeImage($template)) {
                $expectedImage = (string) ($provisioner->resolveImageReference(
                    $template,
                    $deployment->selected_version ?? null
                )['image'] ?? '');
            }
        }

        $dbSidecarRunning = null;
        foreach ($upstream['containers'] ?? [] as $container) {
            $cname = (string) ($container['name'] ?? '');
            if ($cname !== '' && (str_ends_with($cname, '-db') || str_contains($cname, '-db-'))) {
                $dbSidecarRunning = strtolower((string) ($container['state'] ?? '')) === 'running';
            }
        }

        return [
            'status' => $status !== '' ? $status : implode('; ', $upstream['stopped'] ?? []),
            'running' => $running,
            'restarting' => $restarting,
            'oom' => $oom,
            'restart_count' => $restartCount,
            'image' => $image,
            'expected_image' => $expectedImage,
            'cmd' => $cmd,
            'state_error' => $stateError,
            'process_list' => $processList,
            'disk_percent' => $diskPercent,
            'crash_logs' => $upstream['crash_logs'] ?? [],
            'stopped' => $upstream['stopped'] ?? [],
            'publishes_port' => $upstream['publishes_port'] ?? null,
            'assigned_port' => $upstream['assigned_port'] ?? $deployment->assigned_port,
            'db_sidecar_running' => $dbSidecarRunning,
        ];
    }

    /**
     * @param  array<string, mixed>  $checks
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $upstream
     */
    private function mergeInfrastructureSnapshotIntoChecks(array &$checks, array $snapshot, array $upstream): void
    {
        $checks['restarting'] = ($snapshot['restarting'] ?? false) === true;
        $checks['container_image'] = $snapshot['image'] ?? null;
        $checks['expected_image'] = $snapshot['expected_image'] ?? null;
        $checks['disk_percent'] = $snapshot['disk_percent'] ?? null;
        $checks['publishes_port'] = $snapshot['publishes_port'] ?? $upstream['publishes_port'] ?? null;
        $checks['php_start_command'] = ($snapshot['cmd'] ?? '') !== '' ? $snapshot['cmd'] : ($checks['php_start_command'] ?? null);

        $processes = (string) ($snapshot['process_list'] ?? '');
        if ($processes !== '') {
            $phpDashS = $this->processListUsesPhpBuiltinDevServer($processes);
            $nginxUp = $this->processListUsesPhpFpm($processes);
            $checks['php_production_runtime'] = $nginxUp && ! $phpDashS;
        }
    }

    private function probeHttpStatus(SSHService $ssh, $deployment): ?int
    {
        $url = $deployment->getAccessUrl();
        if (! is_string($url) || $url === '') {
            return null;
        }

        return $this->probeHttpStatusAt($ssh, $url);
    }

    public function laravelLoginProbeUrl($deployment): ?string
    {
        $url = $deployment->getAccessUrl();
        if (! is_string($url) || $url === '') {
            return null;
        }

        return rtrim($url, '/').'/home';
    }

    public function originLoginProbeCommand(string $domain, bool $ssl): string
    {
        $host = strtolower(ltrim($domain, '/'));
        if ($ssl) {
            return 'curl -sk -o /dev/null -w "%{http_code}" --max-time 12 --resolve '
                .escapeshellarg($host.':443:127.0.0.1').' '
                .escapeshellarg('https://'.$host.'/home').' || true';
        }

        return 'curl -s -o /dev/null -w "%{http_code}" --max-time 12 -H '
            .escapeshellarg('Host: '.$host).' '
            .escapeshellarg('http://127.0.0.1/home').' || true';
    }

    public function homepageBodyProbeCommand(string $url): string
    {
        return 'curl -sL --max-time 12 -A '.escapeshellarg('Talksasa-Doctor/1.0')
            .' '.escapeshellarg($url).' | head -c 200000 || true';
    }

    /**
     * CSS/JS still emitted as http:// on an https:// page — browsers block them (unstyled UI).
     *
     * @return list<string>
     */
    public function httpsPageHttpAssetUrls(string $html, string $pageUrl): array
    {
        $pageScheme = strtolower((string) parse_url($pageUrl, PHP_URL_SCHEME));
        $pageHost = $this->normalizedHttpHost((string) parse_url($pageUrl, PHP_URL_HOST));
        if ($pageScheme !== 'https' || $pageHost === '') {
            return [];
        }

        preg_match_all('/\b(?:href|src)=["\']([^"\']+)["\']/i', $html, $matches);
        $hits = [];
        foreach ($matches[1] as $url) {
            $url = trim((string) $url);
            if (preg_match('/^http:\/\//i', $url) !== 1) {
                continue;
            }
            $assetHost = $this->normalizedHttpHost((string) parse_url($url, PHP_URL_HOST));
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($assetHost !== $pageHost) {
                continue;
            }
            if (preg_match('/\.(css|js|mjs|woff2?|ttf|eot)(\?|$)/i', $path) !== 1) {
                continue;
            }
            $hits[] = $url;
            if (count($hits) >= 8) {
                break;
            }
        }

        return $hits;
    }

    public function appUrlIsHttpWhileLiveIsHttps(?string $appUrl, ?string $liveUrl): bool
    {
        $appScheme = strtolower((string) parse_url((string) $appUrl, PHP_URL_SCHEME));
        $liveScheme = strtolower((string) parse_url((string) $liveUrl, PHP_URL_SCHEME));
        $appHost = $this->normalizedHttpHost((string) parse_url((string) $appUrl, PHP_URL_HOST));
        $liveHost = $this->normalizedHttpHost((string) parse_url((string) $liveUrl, PHP_URL_HOST));

        return $liveScheme === 'https'
            && $appScheme === 'http'
            && $appHost !== ''
            && $appHost === $liveHost;
    }

    public function canonicalHttpsAppUrl(string $liveUrl): ?string
    {
        $host = $this->normalizedHttpHost((string) parse_url($liveUrl, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($liveUrl, PHP_URL_SCHEME));
        if ($host === '' || $scheme !== 'https') {
            return null;
        }

        return 'https://'.$host;
    }

    private function normalizedHttpHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array<string, mixed>|null
     */
    private function laravelMixedContentFinding(array $checks, ?string $html, string $liveUrl): ?array
    {
        $appUrl = (string) ($checks['app_url'] ?? '');
        $httpAssets = is_string($html) ? $this->httpsPageHttpAssetUrls($html, $liveUrl) : [];
        $insecureAppUrl = $this->appUrlIsHttpWhileLiveIsHttps($appUrl, $liveUrl);

        if (! $insecureAppUrl && $httpAssets === []) {
            return null;
        }

        $httpsUrl = $this->canonicalHttpsAppUrl($liveUrl) ?? $liveUrl;
        $appUrlAlreadyHttps = ! $insecureAppUrl && str_starts_with(strtolower($appUrl), 'https://');

        return [
            'id' => 'live_mixed_content_app_url',
            'severity' => 'critical',
            'title' => 'HTTPS page loads HTTP CSS and JavaScript',
            'summary' => $appUrlAlreadyHttps
                ? 'GET / returns HTTP 200 and APP_URL is already '.$appUrl
                    .', but Laravel still emits http:// CSS/JS because PHP sees HTTP behind Cloudflare/node nginx. '
                    .'asset() uses the request scheme unless ASSET_URL is set. Set ASSET_URL='.$httpsUrl.' — MySQL stays up.'
                : 'GET / returns HTTP 200, but Laravel still emits http:// asset URLs (APP_URL='
                    .($appUrl !== '' ? $appUrl : 'http').'). Browsers block those on https://, so the site looks unstyled. '
                    .'Set APP_URL and ASSET_URL to '.$httpsUrl.' — MySQL stays up.',
            'evidence' => array_values(array_filter([
                $appUrl !== '' ? 'APP_URL='.$appUrl : null,
                'ASSET_URL='.((string) ($checks['asset_url'] ?? '') !== '' ? $checks['asset_url'] : 'unset'),
                'live URL='.$liveUrl,
                ...array_slice($httpAssets, 0, 5),
            ])),
            'treat_action' => 'fix_laravel_app_url',
            'treat_label' => 'Force HTTPS assets',
            'manual_steps' => [
                'Click Force HTTPS assets — writes ASSET_URL='.$httpsUrl.' into .env and compose so CSS/JS are https, then recreates only the app.',
                'Reload the site. Do not Reset database.',
            ],
            'source' => 'live',
        ];
    }

    /**
     * @return list<string>
     */
    public function storageMediaUrlsFromHtml(string $html, string $liveUrl = ''): array
    {
        preg_match_all('/(?:src|href)=["\']([^"\']*\/storage\/[^"\']+)["\']/i', $html, $attr);
        preg_match_all('/url\((["\']?)([^)\'"]*\/storage\/[^)\'"]+)\1\)/i', $html, $css);
        $found = array_merge($attr[1] ?? [], $css[2] ?? []);
        $base = rtrim($liveUrl, '/');
        $urls = [];
        foreach ($found as $url) {
            $url = html_entity_decode(trim((string) $url), ENT_QUOTES);
            if ($url === '') {
                continue;
            }
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            } elseif (str_starts_with($url, '/')) {
                $url = $base !== '' ? $base.$url : $url;
            }
            if (! str_contains($url, '/storage/')) {
                continue;
            }
            $urls[] = $url;
            if (count($urls) >= 8) {
                break;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<array{path: string, count: int}>
     */
    public function staticAsset404s(string $logs): array
    {
        $counts = [];
        foreach (preg_split("/\r\n|\n|\r/", $logs) ?: [] as $line) {
            if (preg_match('/"([A-Z]+)\s+(\S+)\s+HTTP\/[\d.]+"\s+(404)\s+(\d+|-)/', $line, $match) !== 1) {
                continue;
            }
            $path = explode('?', (string) $match[2], 2)[0];
            if ($path === '' || ! str_starts_with($path, '/')) {
                continue;
            }
            $isStorage = str_starts_with($path, '/storage/');
            $isStatic = preg_match('/\.(css|js|mjs|png|jpe?g|gif|ico|svg|webp|woff2?|ttf|eot|mp4)(\?|$)/i', $path) === 1;
            if (! $isStorage && ! $isStatic) {
                continue;
            }
            $counts[$path] = ($counts[$path] ?? 0) + 1;
        }

        $rows = [];
        foreach ($counts as $path => $count) {
            $rows[] = ['path' => $path, 'count' => $count];
        }
        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, 12);
    }

    /**
     * @param  list<string>  $urls
     */
    public function assetStatusProbeCommand(array $urls): string
    {
        $urls = array_values(array_filter(array_slice($urls, 0, 8)));
        if ($urls === []) {
            return 'true';
        }

        $args = implode(' ', array_map('escapeshellarg', $urls));

        return 'curl -sL -o /dev/null -w "%{http_code} %{url_effective}\n" --max-time 8 '.$args.' || true';
    }

    /**
     * @return list<array{url: string, status: int}>
     */
    public function parseAssetStatusLines(string $output): array
    {
        $rows = [];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^(\d{3})\s+(\S+)/', $line, $match) !== 1) {
                continue;
            }
            $rows[] = ['url' => $match[2], 'status' => (int) $match[1]];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function missingStorageAssetsFinding(SSHService $ssh, string $logs, ?string $html, string $liveUrl): ?array
    {
        $fromHtml = is_string($html) ? $this->storageMediaUrlsFromHtml($html, $liveUrl) : [];
        $probed = [];
        if ($fromHtml !== []) {
            try {
                $output = (string) $ssh->exec($this->assetStatusProbeCommand($fromHtml), 25);
                $probed = $this->parseAssetStatusLines($output);
            } catch (\Throwable) {
                $probed = [];
            }
        }
        $missing = array_values(array_filter(
            $probed,
            fn (array $row) => ($row['status'] ?? 0) === 404
        ));
        $log404s = $this->staticAsset404s($logs);
        $storageLog404s = array_values(array_filter(
            $log404s,
            fn (array $row) => str_starts_with((string) $row['path'], '/storage/')
        ));

        if ($fromHtml !== [] && $probed !== []) {
            if ($missing === []) {
                return null;
            }
            $storageLog404s = [];
        }

        if ($missing === [] && $storageLog404s === []) {
            return null;
        }

        $evidence = [
            ...array_map(
                fn (array $row) => 'GET '.($row['url'] ?? '').' → 404',
                array_slice($missing, 0, 6)
            ),
            ...array_map(
                fn (array $row) => 'log '.$row['path'].' 404×'.$row['count'],
                array_slice($storageLog404s, 0, 4)
            ),
        ];

        return [
            'id' => 'live_storage_assets_missing',
            'severity' => 'warning',
            'title' => 'Images under /storage/ return 404',
            'summary' => 'The HTML is 200, but Spatie/Laravel media URLs like /storage/media/48/2brkitchen.jpg 404. DirectAdmin imports often skip the public/storage symlink, so nginx hands those files to Laravel. Link public/storage — MySQL stays up. If files were never copied, restore media from backup after linking.',
            'evidence' => array_values(array_filter($evidence)),
            'treat_action' => 'ensure_storage_link',
            'treat_label' => 'Link public/storage',
            'manual_steps' => [
                'Click Link public/storage — creates public/storage → storage/app/public (or public_html/storage).',
                'Reload the gallery. Do not Reset database.',
            ],
            'source' => 'live',
        ];
    }

    private function probeHttpBody(SSHService $ssh, string $url): ?string
    {
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        try {
            $body = (string) $ssh->exec($this->homepageBodyProbeCommand($url), 20);

            return $body !== '' ? $body : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function loginPathLooksLikeHeaderBufferFailure(?int $rootStatus, ?int $loginStatus): bool
    {
        return $rootStatus !== null
            && $rootStatus < 500
            && $rootStatus >= 200
            && $loginStatus !== null
            && in_array($loginStatus, [502, 503], true);
    }

    private function probeHttpStatusAt(SSHService $ssh, string $url): ?int
    {
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

    private function probeOriginLoginStatus(SSHService $ssh, $deployment): ?int
    {
        $domain = $deployment->relationLoaded('domains')
            ? $deployment->domains->first(fn ($d) => in_array($d->status, ['active', 'pending'], true))
            : $deployment->domains()->whereIn('status', ['active', 'pending'])->first();

        if (! $domain || ! filled($domain->domain)) {
            return null;
        }

        try {
            $code = trim($ssh->exec(
                $this->originLoginProbeCommand((string) $domain->domain, (bool) $domain->ssl_enabled),
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

        if (! $domain) {
            return null;
        }

        $nginx = app(NginxProxyService::class);
        $configPath = $nginx->vhostConfigPath($ssh, $domain);

        try {
            $config = $ssh->exec('cat '.escapeshellarg($configPath).' 2>/dev/null || true', 20);
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
                'vhost: '.$configPath,
                str_contains($config, 'proxy_http_version 1.1') ? null : 'missing proxy_http_version 1.1',
                str_contains($config, 'proxy_request_buffering off') ? 'proxy_request_buffering off' : null,
                str_contains($config, 'proxy_buffer_size 128k') ? null : 'missing proxy_buffer_size 128k',
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
     * Cookie-session Laravel logins 502 on the old 4k/8k vhost even when GET / is 200.
     *
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    public function withLaravelProxyBufferContext(array $finding, string $stack): array
    {
        if (! in_array($stack, ['laravel', 'php'], true)) {
            return $finding;
        }

        $finding['severity'] = 'critical';
        $finding['title'] = 'Web proxy header buffer is too small for login';
        $finding['summary'] = 'GET / can be 200 while /home 502s. Ultimate POS starts a cookie session on login; '
            .'the encrypted Set-Cookie is larger than this vhost’s default 4k/8k nginx buffer. '
            .'Doctor’s homepage check stays green. Refresh web proxy rewrites 32k buffers — MySQL stays up.';
        $finding['manual_steps'] = [
            'Click Refresh web proxy — rewrites the nginx vhost and reloads nginx.',
            'Reload /home. Do not Reset database.',
        ];

        return $finding;
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
                'id' => 'nginx_boot_failed',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/\[emerg\].*fastcgi_params/i',
                    '/nginx: \[emerg\]/i',
                    '/open\(\) "\/tmp\/talksasa-php\/fastcgi_params" failed/i',
                    '/bind\(\) to 0\.0\.0\.0:\d+ failed/i',
                ],
                'title' => 'nginx failed to start (container crash-loop)',
                'summary' => 'The PHP runtime’s nginx process exited on boot, so Compose keeps restarting the container and the published port never stays up. This is a Talksasa runtime config problem, not an application code bug. Rebuild onto the current PHP-FPM image.',
                'treat_action' => 'switch_php_production_runtime',
                'treat_label' => 'Rebuild PHP-FPM runtime',
                'manual_steps' => [
                    'Click Rebuild PHP-FPM runtime — rebuilds the node image and recreates the app container (database is kept).',
                    'The first rebuild on a host can take several minutes.',
                ],
            ],
            [
                'id' => 'php_fpm_sock_missing',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/connect\(\) failed[^\n]*php-fpm/i',
                    '/No such file or directory[^\n]*php-fpm\.sock/i',
                    '/php-fpm\.sock failed/i',
                ],
                'title' => 'nginx cannot reach PHP-FPM',
                'summary' => 'nginx started but the FastCGI socket is missing, so every request 502s. Rebuild the PHP-FPM runtime so php-fpm and nginx share the same socket path.',
                'treat_action' => 'switch_php_production_runtime',
                'treat_label' => 'Rebuild PHP-FPM runtime',
                'manual_steps' => [
                    'Rebuild PHP-FPM runtime, then re-scan Doctor.',
                ],
            ],
            [
                'id' => 'php_builtin_dev_server',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/PHP \S+ Development Server \(http:\/\//i',
                    '/php artisan serve --host=/i',
                    '/nginx\/php-fpm unavailable[^\n]*falling back to php -S/i',
                ],
                'title' => 'PHP development server is handling web traffic',
                'summary' => 'This app is running PHP’s built-in server (`php -S` / `artisan serve`), which handles one request at a time. Dashboard pages that load CSS/JS plus several AJAX calls will feel slow even when MySQL is healthy. Restart will not fix this.',
                'treat_action' => 'switch_php_production_runtime',
                'treat_label' => 'Switch to PHP-FPM',
                'manual_steps' => [
                    'Click Switch to PHP-FPM — rebuilds the Laravel/PHP runtime with nginx + php-fpm and recreates the app container (database is kept).',
                    'The first time on a host this rebuilds the image and can take several minutes.',
                    'Re-scan Doctor after it finishes; logs should show nginx + php-fpm instead of “Development Server”.',
                ],
            ],
            [
                'id' => 'laravel_docroot_not_public',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/directory index of "\/app\/" is forbidden/i',
                ],
                'title' => 'nginx is serving /app instead of /app/public',
                'summary' => 'DirectAdmin Laravel sites keep index.php under public/. This stack started talksasa-php-server with docroot /app, so GET / 403s (directory index forbidden) and /home is a 404 — PHP never runs. Point nginx at /app/public; MySQL stays up.',
                'treat_action' => 'restart_application',
                'treat_label' => 'Point nginx at public/',
                'manual_steps' => [
                    'Click Point nginx at public/ — rewrites talksasa-php-server to public/ or public_html and recreates only the app (MySQL stays up).',
                    'Reload the site. Do not Reset database.',
                ],
            ],
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
                    'Do not Reset database — that wipes existing tables. Re-scan and Repair again if auth still fails.',
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
                    'Do not Reset database if tables already exist — that wipes data. Re-scan and Repair again if the database name is still wrong.',
                ],
            ],
            [
                'id' => 'mysql_unix_socket_missing',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php', 'nodejs', 'wordpress', '*'],
                'patterns' => [
                    '/ENOENT[^\n]*mysql\.sock/i',
                    '/mysql\.sock[^\n]*ENOENT/i',
                    '/Can\'t connect to local MySQL server through socket/i',
                    '/SQLSTATE\[HY000\]\s*\[2002\][^\n]*mysql\.sock/i',
                ],
                'title' => 'App is using a MySQL unix socket that does not exist in Docker',
                'summary' => 'The app is connecting via `/var/lib/mysql/mysql.sock` (DirectAdmin localhost socket). '
                    .'That file does not exist in Docker — the sidecar is TCP at this stack’s unique hostname (`{app}-db`). '
                    .'Restart pins DB_HOST, rewrites hardcoded socketPath in app.js/config, preloads a mysql TCP shim, and recreates the app only. '
                    .'Do not Repair DB credentials (GRANT is fine) and do not Reset database.',
                'treat_action' => 'restart_application',
                'treat_label' => 'Restart application',
                'manual_steps' => [
                    'Click Restart application — writes unique DB_HOST, rewrites hardcoded sockets, preloads a TCP shim, recreates the app, and leaves MySQL running.',
                    'Re-scan. Logs should no longer show ENOENT /var/lib/mysql/mysql.sock.',
                    'Do not Reset database.',
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
                'summary' => 'MySQL rejected the username/password from the app container IP. DirectAdmin users are often granted on localhost only; Docker connects as user@10.201.x.x. Repair creates user@% with the Environment password.',
                'treat_action' => 'sync_database_credentials',
                'treat_label' => 'Repair DB credentials',
                'manual_steps' => [
                    'Click Repair DB credentials (grants the app user from %, resets the password, rewrites .env, and writes DB_* into compose).',
                    'If artisan cache:clear failed with `delete from cache`, click Switch cache to file — that is not a filesystem permission.',
                    'Do not Reset database — that wipes existing tables. Re-scan and Repair again if 1045 persists.',
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
                'id' => 'artisan_cache_uses_database',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/SQL:\s*delete from [`\']cache[`\']/i',
                    '/cache:clear[\s\S]{0,1200}Access denied for user/i',
                    '/optimize:clear[\s\S]{0,1200}Access denied for user/i',
                ],
                'title' => 'Artisan cache:clear is using the database driver',
                'summary' => 'php artisan cache:clear / optimize:clear ran `delete from cache` and MySQL rejected the overlay IP. '
                    .'That is CACHE_STORE=database in the container env — not a missing storage/logs directory. '
                    .'Switch cache to file so artisan does not need MySQL. Then Fix storage permissions if `cd logs` still fails.',
                'treat_action' => 'use_file_cache',
                'treat_label' => 'Switch cache to file',
                'manual_steps' => [
                    'Click Switch cache to file — writes CACHE_STORE=file into compose, creates storage/logs, recreates the app (MySQL stays up).',
                    'Do not Reset database. Repair DB credentials if HTTP still 1045s on real tables.',
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
                    'Click Fix storage permissions (creates cache dirs). Cache clear uses the file driver so a broken MySQL user cannot fail the treatment.',
                    'If logs also show Access denied for user, click Repair DB credentials.',
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
                'id' => 'mysql_connection_refused',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php', 'wordpress', 'nodejs', '*'],
                'patterns' => [
                    '/SQLSTATE\[HY000\]\s*\[2002\]/i',
                    '/Connection refused.*3306/i',
                    '/php_network_getaddresses: getaddrinfo failed.*db/i',
                    '/SQLSTATE\[HY000\] \[1049\]/i',
                    '/Unknown database/i',
                ],
                'title' => 'Application cannot reach the database sidecar',
                'summary' => 'PHP connected to host "db" but MySQL refused the connection, the hostname did not resolve, or the schema is missing. Often the sidecar is still booting or credentials drifted after a redeploy.',
                'treat_action' => 'sync_database_credentials',
                'treat_label' => 'Repair DB credentials',
                'manual_steps' => [
                    'Wait a few seconds if MySQL just restarted, then Repair DB credentials.',
                    'If the sidecar is crash-looping, Recreate containers (keep database).',
                ],
            ],
            [
                'id' => 'redis_connection_refused',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php', 'nodejs', '*'],
                'patterns' => [
                    '/Connection refused[^\n]*6379/i',
                    '/RedisException/i',
                    '/php_network_getaddresses[^\n]*redis/i',
                ],
                'title' => 'Redis is configured but not reachable',
                'summary' => 'The app SESSION/CACHE/QUEUE driver points at Redis, but this stack has no Redis sidecar. Switch those drivers to file/database, or the site will 500.',
                'treat_action' => 'use_file_cache',
                'treat_label' => 'Switch cache to file',
                'manual_steps' => [
                    'Switch cache to file from Doctor. If tabs still 500 on /get-total-unread, use Relax session/cache locking (cookie sessions).',
                ],
            ],
            [
                'id' => 'missing_vendor_autoload',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/Failed opening required[^\n]*vendor/i',
                    '/vendor\/autoload\.php[^\n]*failed to open stream/i',
                ],
                'title' => 'Composer dependencies are missing',
                'summary' => 'PHP cannot boot because vendor/ is incomplete. Git pull without composer install, or a killed composer run, causes this.',
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'On the Git tab: Pull latest (runs composer install).',
                    'Or in Terminal: composer install --no-dev --optimize-autoloader',
                ],
            ],
            [
                'id' => 'php_fatal_memory',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php', 'wordpress', '*'],
                'patterns' => [
                    '/Allowed memory size of \d+ bytes exhausted/i',
                ],
                'title' => 'PHP ran out of memory',
                'summary' => 'A request or artisan command hit the PHP memory_limit. Restart clears the crash; a larger plan or a cheaper query is the real fix.',
                'treat_action' => 'restart_application',
                'treat_label' => 'Restart application',
                'manual_steps' => [
                    'Restart to recover, then raise the plan if this repeats on normal traffic.',
                ],
            ],
            [
                'id' => 'php_fpm_max_children',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/server reached pm\.max_children/i',
                    '/seems busy, spawning/i',
                    '/max_children \(\d+\) already spawned/i',
                ],
                'title' => 'PHP-FPM ran out of workers',
                'summary' => 'All PHP-FPM children are busy, so extra requests 500. Ultimate POS tabs that poll /get-total-unread plus DataTables queries fill a small worker pool, especially with file session locks.',
                'treat_action' => 'tune_request_concurrency',
                'treat_label' => 'Relax session/cache locking',
                'manual_steps' => [
                    'Click Relax session/cache locking (cookie sessions + file cache, no Redis).',
                    'If it still saturates workers, upgrade the plan so PHP-FPM can start more children.',
                ],
            ],
            [
                'id' => 'php_max_execution_time',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php', 'wordpress', '*'],
                'patterns' => [
                    '/Maximum execution time of \d+ seconds exceeded/i',
                ],
                'title' => 'A request exceeded PHP max_execution_time',
                'summary' => 'A heavy page (often a DataTables AJAX draw) ran longer than max_execution_time. Concurrent polls then 500 while that worker is stuck.',
                'treat_action' => 'tune_request_concurrency',
                'treat_label' => 'Relax session/cache locking',
                'manual_steps' => [
                    'Relax session/cache locking so other tabs are not blocked on the slow query.',
                    'In Terminal: tail -n 80 storage/logs/laravel.log for the slow route.',
                ],
            ],
            [
                'id' => 'compose_unset_variable',
                'severity' => 'info',
                'stacks' => ['*'],
                'patterns' => [
                    '/The \\\?"([A-Z][A-Z0-9_]+)\\\"? variable is not set\. Defaulting to a blank string/i',
                    '/variable is not set\. Defaulting to a blank string/i',
                ],
                'title' => 'Docker Compose is interpolating empty environment variables',
                'summary' => 'Compose warns when docker-compose.yml contains ${MAIL_USERNAME} (and similar) that are not in the project .env. This does not take the site down, but mail/redis settings can silently become blank.',
                'treat_action' => 'fix_compose_interpolation',
                'treat_label' => 'Fill Compose env defaults',
                'manual_steps' => [
                    'Click Fill Compose env defaults — writes missing MAIL_* keys next to docker-compose.yml.',
                    'Set real SMTP values in the Environment tab if the app should send mail.',
                ],
            ],
            [
                'id' => 'mix_manifest_missing',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/Mix manifest not found/i',
                    '/Vite manifest not found/i',
                    '/Unable to locate file in Vite manifest/i',
                ],
                'title' => 'Frontend assets were not built',
                'summary' => 'Laravel cannot find public/mix-manifest.json or the Vite manifest. The HTML loads but CSS/JS 404. Build assets in the container or deploy compiled public/build.',
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'In Terminal: npm ci && npm run build (from the app root).',
                    'Or commit public/build and pull again.',
                ],
            ],
            [
                'id' => 'docker_network_missing',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => [
                    '/network .*talksasa-net.* not found/i',
                    '/network [^\n]*not found/i',
                ],
                'title' => 'Docker network is missing on this host',
                'summary' => 'Compose cannot attach the app to talksasa-net. Recreate the stack after the shared bridge exists on the node.',
                'treat_action' => 'recreate_application',
                'treat_label' => 'Recreate containers',
                'manual_steps' => [
                    'Recreate containers. If it still fails, the container host needs the talksasa-net bridge (node bootstrap).',
                ],
            ],
            [
                'id' => 'port_already_allocated',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => [
                    '/port is already allocated/i',
                    '/Bind for 0\.0\.0\.0:\d+ failed/i',
                    '/EADDRINUSE/i',
                    '/address already in use/i',
                ],
                'title' => 'Host port is already in use',
                'summary' => 'Another container (or a stale copy of this one) still holds the published port, so Compose cannot start this stack.',
                'treat_action' => 'recreate_application',
                'treat_label' => 'Recreate containers',
                'manual_steps' => [
                    'Recreate containers to drop stale port bindings. If it still fails, an operator must free the host port.',
                ],
            ],
            [
                'id' => 'node_disk_exhausted',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => [
                    '/No space left on device/i',
                    '/ENOSPC/i',
                ],
                'title' => 'Container host is out of disk',
                'summary' => 'The node has little or no free disk. New containers crash, MySQL cannot write, and image rebuilds fail until space is freed.',
                'treat_action' => null,
                'treat_label' => null,
                'manual_steps' => [
                    'Free space on the container host (old images, logs, backups under /opt/talksasa).',
                    'Then recreate the application. Doctor cannot free host disk from the customer console.',
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
     * True when compose still starts PHP with php -S / artisan serve.
     */
    public function composeUsesPhpBuiltinDevServer(?string $compose): bool
    {
        $compose = (string) $compose;
        if ($compose === '') {
            return false;
        }

        if (str_contains($compose, 'talksasa-php-server')) {
            return false;
        }

        if (preg_match('/artisan serve|php artisan serve/i', $compose) === 1) {
            return true;
        }

        if (preg_match('/php\s+-S\b/', $compose) === 1) {
            return true;
        }

        $hasPhp = preg_match('/^\s*-\s*[\'"]?php[\'"]?\s*$/m', $compose) === 1;
        $hasDashS = preg_match('/^\s*-\s*[\'"]?-S[\'"]?\s*$/m', $compose) === 1;

        return $hasPhp && $hasDashS;
    }

    public function commandLooksLikePhpBuiltinDevServer(string $command): bool
    {
        $command = trim($command);
        if ($command === '') {
            return false;
        }

        if (preg_match('/\bphp-fpm\b|\bnginx\b/', $command) === 1
            && preg_match('/php\s+-S\b|artisan serve|Development Server/i', $command) !== 1) {
            return false;
        }

        return preg_match('/php\s+-S\b|artisan serve|Development Server|falling back to php -S/i', $command) === 1;
    }

    public function processListUsesPhpBuiltinDevServer(string $processList): bool
    {
        return preg_match('/php\s+-S\b|artisan serve/i', $processList) === 1;
    }

    public function processListUsesPhpFpm(string $processList): bool
    {
        return preg_match('/\bphp-fpm\b|\bnginx:/i', $processList) === 1
            || preg_match('/nginx: master process/i', $processList) === 1;
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array<string, mixed>|null
     */
    private function phpBuiltinDevServerFinding($ssh, $deployment, string $stack, array &$checks): ?array
    {
        $liveCmd = trim($ssh->exec(
            'docker inspect -f '.escapeshellarg('{{range .Config.Cmd}}{{.}} {{end}}')
            .' '.escapeshellarg($deployment->container_name).' 2>/dev/null || true',
            15
        ));
        $processList = trim($ssh->exec(
            'docker top '.escapeshellarg($deployment->container_name).' -eo args 2>/dev/null || true',
            15
        ));
        $checks['php_start_command'] = $liveCmd !== '' ? $liveCmd : null;

        $phpDashS = $this->processListUsesPhpBuiltinDevServer($processList)
            || $this->commandLooksLikePhpBuiltinDevServer($liveCmd);
        $nginxUp = $this->processListUsesPhpFpm($processList);

        $usingBuiltin = $phpDashS
            || ($liveCmd === '' && $processList === '' && $this->composeUsesPhpBuiltinDevServer((string) $deployment->docker_compose_content));

        // talksasa-php-server in Cmd is not enough: the wrapper may have fallen back to php -S.
        $checks['php_production_runtime'] = $nginxUp && ! $phpDashS;

        if (! $usingBuiltin) {
            return null;
        }

        return [
            'id' => 'php_builtin_dev_server',
            'severity' => 'warning',
            'title' => 'PHP development server is handling web traffic',
            'summary' => 'This '.$stack.' app is running PHP’s built-in server (`php -S` / `artisan serve`), which handles one request at a time. '
                .'CSS/JS may also break if index.php was used as the php -S router. Restart will not fix this.',
            'evidence' => array_values(array_filter([
                $liveCmd !== '' ? 'cmd: '.mb_substr($liveCmd, 0, 220) : null,
                $phpDashS && str_contains($liveCmd, 'talksasa-php-server')
                    ? 'wrapper fell back to php -S (nginx/php-fpm not on PATH)'
                    : 'PHP Development Server is single-threaded',
                $processList !== '' ? 'ps: '.mb_substr(preg_replace('/\s+/', ' ', $processList) ?? $processList, 0, 220) : null,
            ])),
            'treat_action' => 'switch_php_production_runtime',
            'treat_label' => 'Switch to PHP-FPM',
            'manual_steps' => [
                'Click Switch to PHP-FPM — rebuilds the runtime with nginx + php-fpm and recreates the app container (database is kept).',
                'The first time on a host this rebuilds the image and can take several minutes.',
            ],
            'source' => 'live',
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
            $liveEnv = $this->readLiveAppEnvironment($ssh, $deployment, $service);
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
            $envVars = $deploymentService->pinApplicationDatabaseHost(
                $envVars,
                (string) $deployment->container_name,
                (string) $databaseTemplate->type
            );
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
                $envVars['SESSION_DRIVER'] = 'file';
                $envVars['CACHE_STORE'] = 'file';
                $envVars['CACHE_DRIVER'] = 'file';
                $deployment->update(['env_values' => array_merge(
                    is_array($deployment->env_values) ? $deployment->env_values : [],
                    $envVars
                )]);
                $meta = is_array($service->service_meta) ? $service->service_meta : [];
                $metaEnv = is_array($meta['env_values'] ?? null) ? $meta['env_values'] : [];
                $meta['env_values'] = array_merge($metaEnv, $envVars);
                $service->update(['service_meta' => $meta]);
                $deployment->refresh();

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

                $this->healLaravelRuntimeAfterDatabaseRepair($service, $ssh);
                try {
                    $deploymentService->restartAppService($ssh, $deployment);
                } catch (\Throwable $e) {
                    \Log::warning('Doctor could not recycle app container after DB repair', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $deploymentService->syncMysqlSidecarCredentials($ssh, $containerPath, $syncEnv);
                } catch (\Throwable $e) {
                    \Log::warning('Doctor could not re-GRANT after app recreate', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
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
                $hostHint = '';
                try {
                    $hosts = $deploymentService->mysqlListUserHosts(
                        $ssh,
                        $containerPath,
                        $deploymentService->resolveMysqlComposeServiceName($envVars),
                        (string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? ''),
                        (string) ($envVars['DB_USERNAME'] ?? $envVars['MYSQL_USER'] ?? '')
                    );
                    if ($hosts !== []) {
                        $hostHint = ' mysql.user Host values: '.implode(', ', $hosts).'.';
                    }
                } catch (\Throwable) {
                }

                return [
                    'success' => false,
                    'message' => $message.' Live connection still fails: '.($probe['error'] ?? 'unknown error')
                        .'. Host-specific MySQL accounts (user@overlay-ip) were dropped and user@% recreated.'
                        .$hostHint
                        .' App DB_HOST is now '.($envVars['DB_HOST'] ?? 'db')
                        .' (not the shared-network alias `db`).'
                        .' Do not Reset database — that wipes existing tables. Re-scan Doctor and click Repair again if 1045 persists.',
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
            $liveEnv = $this->readLiveAppEnvironment($ssh, $deployment, $service);
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

    /**
     * Choose the HTTP 500 treat from live PDO + the newest app-log lines.
     *
     * Leftover 1045/2002 lines must not win when `db_ok` is already true — that
     * is what kept offering Repair DB after GRANT succeeded and then crashed
     * the sidecar.
     *
     * @param  array<string, mixed>  $checks
     * @param  list<string>  $appErrors
     * @return array{treat_action: string, treat_label: string, summary: string}
     */
    public function resolveHttp500Treatment(array $checks, array $appErrors, string $stack = 'laravel'): array
    {
        $dbOk = ($checks['db_ok'] ?? null) === true;
        $hasTables = ($checks['table_count'] ?? null) !== null && (int) $checks['table_count'] > 0;
        $httpStatus = $checks['http_status'] ?? 500;
        $haystack = implode("\n", $appErrors);

        $looksLikeMissingCacheLocks = (bool) preg_match('/cache_locks/i', $haystack);
        $looksLikeMissingTable = (bool) preg_match('/relation .* does not exist|Base table or view not found|no such table/i', $haystack);
        $looksLikeMissingViewCache = (bool) preg_match('/Please provide a valid cache path/i', $haystack);
        $looksLikeDbAuth = ! $dbOk && $this->looksLikeDatabaseAuthFailure($haystack);

        if ($looksLikeDbAuth) {
            return [
                'treat_action' => 'sync_database_credentials',
                'treat_label' => 'Repair DB credentials',
                'summary' => 'The live database probe failed and the app log shows MySQL/Postgres rejected the username or password '
                    .'(often leftover `user`@`<overlay-ip>` after a DirectAdmin import). Repair drops those shadow accounts, '
                    .'recreates `user`@`%`, and reloads PHP-FPM so workers pick up .env.',
            ];
        }

        if ($looksLikeMissingViewCache) {
            return [
                'treat_action' => 'fix_storage_permissions',
                'treat_label' => 'Create Laravel cache directories',
                'summary' => 'Laravel Blade compiled-view path is missing (storage/framework/views). DirectAdmin exports skip those '
                    .'cache dirs, so GET / 500s until they exist. Live DB is '.($dbOk ? 'connected' : 'not confirmed')
                    .' — leftover 1045 lines in laravel.log are not the current cause.',
            ];
        }

        if ($looksLikeMissingCacheLocks) {
            return [
                'treat_action' => 'use_file_cache',
                'treat_label' => 'Switch cache to file',
                'summary' => 'DB connects and has tables, but Laravel is using database cache without a cache_locks table — that commonly 500s GET /.',
            ];
        }

        if ($looksLikeMissingTable) {
            return [
                'treat_action' => $hasTables ? 'run_migrations' : 'migrate_fresh',
                'treat_label' => $hasTables ? 'Run migrations' : 'Rebuild schema (migrate:fresh)',
                'summary' => 'DB connects, but the app error looks like missing tables/migrations.',
            ];
        }

        if (in_array($stack, ['laravel', 'php'], true) && $hasTables && $dbOk) {
            $staleSessions = (bool) preg_match('/select \* from [`\']sessions[`\']/i', $haystack)
                || (($checks['session_driver_runtime'] ?? '') === 'database');
            $laravelHost = strtolower(trim((string) ($checks['laravel_db_host'] ?? '')));
            $usesSharedDbAlias = $this->isAmbiguousLaravelDatabaseHost($laravelHost)
                || (bool) preg_match('/mysql:host=db[;\'"]/', $haystack);

            if ($usesSharedDbAlias) {
                return [
                    'treat_action' => 'restart_application',
                    'treat_label' => 'Restart application',
                    'summary' => 'Live PDO works (tables: '.(string) ($checks['table_count'] ?? '?')
                        .') because Doctor uses this stack’s unique sidecar DNS, but Laravel still connects to `mysql:host=db`. '
                        .'That alias is shared on talksasa-net, which is why the public URL 500s (1045/2002) while this card says DB OK. '
                        .'Restart writes DB_HOST to the unique *-db name and recreates the app — MySQL stays up.',
                ];
            }

            return [
                'treat_action' => 'restart_application',
                'treat_label' => 'Restart application',
                'summary' => $staleSessions
                    ? 'Live PDO works (tables: '.(string) ($checks['table_count'] ?? '?')
                        .') but PHP-FPM is still querying `sessions` (cached SESSION_DRIVER=database) or leftover `user`@`10.%` accounts. '
                        .'Restart recycles the app container only — it will not bounce MySQL (that 2002s the site).'
                    : 'Live PDO works (tables: '.(string) ($checks['table_count'] ?? '?')
                        .') but the public URL still returns HTTP '.$httpStatus
                        .'. Restart recycles the app container only; MySQL stays up. Leftover 2002 lines are from a previous sidecar bounce.',
            ];
        }

        if (in_array($stack, ['laravel', 'php'], true)) {
            return [
                'treat_action' => 'clear_laravel_caches',
                'treat_label' => 'Clear Laravel caches',
                'summary' => 'The container is up and answering on its port, but the app itself returns HTTP '.$httpStatus
                    .' (tables: '.((string) ($checks['table_count'] ?? '?')).').',
            ];
        }

        return [
            'treat_action' => 'restart_application',
            'treat_label' => 'Restart application',
            'summary' => 'The container is up and answering on its port, but the app itself returns HTTP '.$httpStatus
                .' (tables: '.((string) ($checks['table_count'] ?? '?')).'). '
                .'This is an application exception — this card stays until the URL returns 2xx/3xx.',
        ];
    }

    /**
     * After GRANT + .env rewrite: create cache dirs and force cookie/file drivers.
     */
    private function healLaravelRuntimeAfterDatabaseRepair(Service $service, SSHService $ssh): void
    {
        $deployment = $service->containerDeployment;
        $appDirectory = app(ContainerAppDirectoryService::class);

        try {
            $appDirectory->ensureLaravelWritableLayoutOnHost($ssh, $appDirectory->hostAppPath($deployment));
            $appDirectory->normalizeLaravelPermissions($ssh, $deployment);
        } catch (\Throwable $e) {
            \Log::warning('Doctor storage layout repair failed after DB sync', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }

        $projectRoot = $this->resolveArtisanProjectRoot($ssh, $deployment);
        $php = <<<'PHP'
$root = getenv('PROJECT_ROOT') ?: '/app';
$envPath = rtrim($root, '/').'/.env';
if (!is_file($envPath)) { echo "missing-env"; exit(0); }
$c = file_get_contents($envPath);
if ($c === false) { echo "read-failed"; exit(0); }
$set = function (string $c, string $k, string $v): string {
    if (preg_match('/^'.preg_quote($k, '/').'=/m', $c)) {
        return preg_replace('/^'.preg_quote($k, '/').'=.*$/m', $k.'='.$v, $c, 1);
    }
    return rtrim($c)."\n".$k.'='.$v."\n";
};
$c = $set($c, 'CACHE_STORE', 'file');
$c = $set($c, 'CACHE_DRIVER', 'file');
$c = $set($c, 'SESSION_DRIVER', 'file');
if (file_put_contents($envPath, $c) === false) { echo "write-failed"; exit(0); }
echo "ok";
PHP;

        $init = app(LaravelAppInitializationService::class);
        try {
            $init->dockerExecPublic(
                $ssh,
                $deployment->container_name,
                'export PROJECT_ROOT='.escapeshellarg($projectRoot).'; php -r '.escapeshellarg($php),
                30,
                asRoot: true
            );
        } catch (\Throwable) {
        }

        try {
            app(ContainerDeploymentService::class)
                ->persistLaravelRuntimeDriversOnCompose($ssh, $deployment);
        } catch (\Throwable $e) {
            \Log::warning('Doctor could not patch compose session/cache drivers', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->clearLaravelCachesQuietly($service, $ssh);
        } catch (\Throwable) {
        }
    }

    private function dropMysqlShadowHostsForService(Service $service, SSHService $ssh): void
    {
        $deployment = $service->containerDeployment;
        $databaseTemplate = app(ContainerDeploymentService::class)->resolveDatabaseTemplateForService($service);
        if (! $databaseTemplate || ! in_array((string) $databaseTemplate->type, ['mysql', 'mariadb'], true)) {
            return;
        }

        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        $live = $this->readLiveAppEnvironment($ssh, $deployment, $service);
        if ($live !== []) {
            $env = array_merge($env, $live);
        }

        $username = (string) ($env['DB_USERNAME'] ?? $env['MYSQL_USER'] ?? '');
        if ($username === '') {
            return;
        }

        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        app(ContainerDeploymentService::class)->mysqlDropShadowHosts(
            $ssh,
            $containerPath,
            app(ContainerDeploymentService::class)->resolveMysqlComposeServiceName($env),
            (string) ($env['MYSQL_ROOT_PASSWORD'] ?? $env['DB_PASSWORD'] ?? ''),
            $username
        );
    }

    public function phpFpmReloadScript(): string
    {
        return 'master=$(pgrep -o php-fpm 2>/dev/null || true); '
            .'if [ -n "$master" ]; then kill -USR2 "$master"; echo php-fpm-reloaded; '
            .'else echo no-php-fpm; fi';
    }

    /**
     * Clear compiled/config/view/route caches without querying MySQL.
     *
     * `php artisan optimize:clear` / `cache:clear` run DELETE FROM `cache` when
     * CACHE_STORE=database (common on DirectAdmin Laravel). That 1045s when the
     * sidecar user is localhost-only and the app connects from 10.201.x.x.
     */
    public function laravelCacheClearScript(string $projectRoot): string
    {
        $root = escapeshellarg($projectRoot);

        return 'cd '.$root.'; '
            .'rm -f bootstrap/cache/config.php bootstrap/cache/packages.php '
            .'bootstrap/cache/services.php bootstrap/cache/events.php '
            .'bootstrap/cache/routes.php bootstrap/cache/routes-v7.php '
            .'2>/dev/null || true; '
            .'CACHE_STORE=file CACHE_DRIVER=file php artisan config:clear --no-interaction || true; '
            .'CACHE_STORE=file CACHE_DRIVER=file php artisan event:clear --no-interaction || true; '
            .'CACHE_STORE=file CACHE_DRIVER=file php artisan view:clear --no-interaction || true; '
            .'CACHE_STORE=file CACHE_DRIVER=file php artisan route:clear --no-interaction || true; '
            .'CACHE_STORE=file CACHE_DRIVER=file php artisan cache:clear --no-interaction || true; '
            .'echo ok';
    }

    private function resolveArtisanProjectRoot(SSHService $ssh, $deployment): string
    {
        $locator = 'if [ -f /app/backend/artisan ]; then echo /app/backend; '
            .'elif [ -f /app/artisan ]; then echo /app; '
            .'else echo /app; fi';

        return trim($ssh->exec(
            'docker exec -u www-data '.escapeshellarg($deployment->container_name)
            .' sh -lc '.escapeshellarg($locator),
            15
        )) ?: '/app';
    }

    private function clearLaravelCachesQuietly(Service $service, SSHService $ssh): void
    {
        $deployment = $service->containerDeployment;
        $projectRoot = $this->resolveArtisanProjectRoot($ssh, $deployment);

        app(LaravelAppInitializationService::class)->dockerExecPublic(
            $ssh,
            $deployment->container_name,
            $this->laravelCacheClearScript($projectRoot),
            120
        );
    }

    private function looksLikeDatabaseAuthFailure(string $message): bool
    {
        return (bool) preg_match('/Access denied for user|SQLSTATE\[HY000\]\s*\[1045\]|password authentication failed/i', $message);
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
            $projectRoot = $this->resolveArtisanProjectRoot($ssh, $deployment);
            $init->dockerExecPublic(
                $ssh,
                $deployment->container_name,
                $this->laravelCacheClearScript($projectRoot),
                120
            );

            return ['success' => true, 'message' => 'Laravel caches cleared (file driver — no database cache table).'];
        } catch (\Throwable $e) {
            if ($this->looksLikeDatabaseAuthFailure($e->getMessage())) {
                $repair = $this->treatSyncDatabaseCredentials($service);
                if ($repair['success']) {
                    return [
                        'success' => true,
                        'message' => 'Cache clear needed a working DB user. '.$repair['message'],
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Cache files were not fully cleared because MySQL rejected the app user. '
                        .'Click Repair DB credentials next. '.$repair['message'],
                ];
            }

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
            $projectRoot = $this->resolveArtisanProjectRoot($ssh, $deployment);

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
$c = $set($c, 'SESSION_DRIVER', 'file');
if (file_put_contents($envPath, $c) === false) { fwrite(STDERR, "write failed\n"); exit(1); }
echo "ok";
PHP;

            $script = 'export PROJECT_ROOT='.escapeshellarg($projectRoot).'; '
                .'php -r '.escapeshellarg($php).'; '
                .$this->laravelCacheClearScript($projectRoot);

            $init->dockerExecPublic($ssh, $deployment->container_name, $script, 120, asRoot: true);

            $deploymentService = app(ContainerDeploymentService::class);
            try {
                $appDirectory = app(ContainerAppDirectoryService::class);
                $appDirectory->ensureLaravelWritableLayoutOnHost($ssh, $appDirectory->hostAppPath($deployment));
                $appDirectory->normalizeLaravelPermissions($ssh, $deployment);
            } catch (\Throwable) {
            }
            $deploymentService->persistLaravelRuntimeDriversOnCompose($ssh, $deployment);
            try {
                $deploymentService->restartAppService($ssh, $deployment);
            } catch (\Throwable $e) {
                \Log::warning('Doctor could not recreate app after switching cache to file', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => 'Switched CACHE_STORE to file in .env and compose, created storage/logs, and recreated the app (MySQL left running). '
                    .'artisan cache:clear will no longer run DELETE FROM cache. Reload the site.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to switch cache driver: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Cookie sessions + file cache so concurrent Ultimate POS AJAX is not serialized
     * on a session file / cache_locks row.
     *
     * @return array{success: bool, message: string}
     */
    private function treatTuneRequestConcurrency(Service $service): array
    {
        $deployment = $service->containerDeployment;
        $ssh = SSHService::forNode($deployment->node);
        $init = app(LaravelAppInitializationService::class);

        try {
            $projectRoot = $this->resolveArtisanProjectRoot($ssh, $deployment);

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
$c = $set($c, 'SESSION_DRIVER', 'file');
if (preg_match('/^QUEUE_CONNECTION=(redis|database)/mi', $c)) {
    $c = $set($c, 'QUEUE_CONNECTION', 'sync');
}
if (preg_match('/^BROADCAST_DRIVER=(redis|pusher)/mi', $c) || preg_match('/^BROADCAST_CONNECTION=(redis|pusher)/mi', $c)) {
    $c = $set($c, 'BROADCAST_DRIVER', 'log');
    $c = $set($c, 'BROADCAST_CONNECTION', 'log');
}
if (file_put_contents($envPath, $c) === false) { fwrite(STDERR, "write failed\n"); exit(1); }
echo "ok";
PHP;

            $script = 'export PROJECT_ROOT='.escapeshellarg($projectRoot).'; '
                .'php -r '.escapeshellarg($php).'; '
                .$this->laravelCacheClearScript($projectRoot);

            $init->dockerExecPublic($ssh, $deployment->container_name, $script, 120, asRoot: true);

            $deploymentService = app(ContainerDeploymentService::class);
            $env = is_array($deployment->env_values) ? $deployment->env_values : [];
            $env['SESSION_DRIVER'] = 'file';
            $env['CACHE_STORE'] = 'file';
            $env['CACHE_DRIVER'] = 'file';
            $deployment->update(['env_values' => $env]);
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $metaEnv = is_array($meta['env_values'] ?? null) ? $meta['env_values'] : [];
            $meta['env_values'] = array_merge($metaEnv, [
                'SESSION_DRIVER' => 'file',
                'CACHE_STORE' => 'file',
                'CACHE_DRIVER' => 'file',
            ]);
            $service->update(['service_meta' => $meta]);
            app(ContainerEnvironmentService::class)
                ->syncDotEnvFile($ssh, $service, $deployment->fresh(), $env);

            try {
                $appDirectory = app(ContainerAppDirectoryService::class);
                $appDirectory->ensureLaravelWritableLayoutOnHost($ssh, $appDirectory->hostAppPath($deployment));
                $appDirectory->purgeLaravelConfigCacheOnHost($ssh, $appDirectory->hostAppPath($deployment));
            } catch (\Throwable) {
            }

            $deploymentService->persistLaravelRuntimeDriversOnCompose($ssh, $deployment->fresh());
            try {
                $deploymentService->restartAppService($ssh, $deployment);
            } catch (\Throwable $e) {
                \Log::warning('Doctor could not recreate app after relaxing session/cache locking', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => 'Set SESSION_DRIVER=file and CACHE_STORE=file in .env and compose, deleted config cache, and recreated the app (MySQL left running). '
                    .'PHP-FPM will now see cookie/file — dotenv cannot override compose. Reload the site.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to relax session/cache locking: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Write missing ${VAR} keys next to docker-compose.yml so Compose stops
     * interpolating MAIL_USERNAME (and similar) as unset.
     *
     * @return array{success: bool, message: string}
     */
    private function treatFixComposeInterpolation(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $ssh = SSHService::forNode($deployment->node);
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $composePath = $containerPath.'/docker-compose.yml';
        $composeEnvPath = $containerPath.'/.env';

        try {
            $yaml = '';
            try {
                $yaml = (string) $ssh->exec('cat '.escapeshellarg($composePath).' 2>/dev/null || true', 15);
            } catch (\Throwable) {
                $yaml = (string) ($deployment->docker_compose_content ?? '');
            }

            $keys = [];
            if (preg_match_all('/\$\{([A-Z][A-Z0-9_]*)(?::-?[^{}]*)?\}/', $yaml, $matches) > 0) {
                $keys = array_values(array_unique($matches[1]));
            }
            foreach (['MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'] as $mailKey) {
                $keys[] = $mailKey;
            }
            $keys = array_values(array_unique($keys));

            $existing = '';
            try {
                $exists = trim($ssh->exec('test -f '.escapeshellarg($composeEnvPath).' && echo yes || echo no', 10));
                if ($exists === 'yes') {
                    $existing = (string) $ssh->exec('cat '.escapeshellarg($composeEnvPath), 15);
                }
            } catch (\Throwable) {
                $existing = '';
            }

            $parsed = $this->parseEnvFileContent($existing);
            $appEnv = $this->readLiveAppEnvironment($ssh, $deployment, $service);
            $lines = $existing === '' || str_ends_with($existing, "\n") ? $existing : $existing."\n";
            $added = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $parsed) && trim((string) $parsed[$key]) !== '') {
                    continue;
                }
                $value = array_key_exists($key, $parsed)
                    ? (string) $parsed[$key]
                    : (string) ($appEnv[$key] ?? '');
                if (! array_key_exists($key, $parsed)) {
                    $lines .= $key.'='.$value."\n";
                    $added[] = $key;
                }
            }

            if ($added === []) {
                return [
                    'success' => true,
                    'message' => 'Compose project .env already has the usual MAIL_* keys. Re-scan if the warning persists.',
                ];
            }

            $ssh->upload($lines, $composeEnvPath);

            return [
                'success' => true,
                'message' => 'Wrote empty/default Compose interpolation keys: '.implode(', ', $added)
                    .'. The warning should stop on the next compose command. Set real SMTP values in Environment to send mail.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to fill Compose env defaults: '.$e->getMessage()];
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

            try {
                app(ContainerDeploymentService::class)
                    ->persistLaravelRuntimeDriversOnCompose($ssh, $deployment);
                app(ContainerDeploymentService::class)->restartAppService($ssh, $deployment);
            } catch (\Throwable) {
            }

            try {
                $this->clearLaravelCachesQuietly($service, $ssh);
            } catch (\Throwable $e) {
                if (! $this->looksLikeDatabaseAuthFailure($e->getMessage())) {
                    return ['success' => false, 'message' => 'Failed to fix permissions: '.$e->getMessage()];
                }

                $repair = $this->treatSyncDatabaseCredentials($service);
                if ($repair['success']) {
                    return [
                        'success' => true,
                        'message' => 'Fixed storage permissions. Also repaired DB credentials so artisan can run: '
                            .$repair['message'],
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Created Laravel cache/storage directories and fixed permissions. '
                        .'Cache clear still cannot reach MySQL ('.mb_substr($e->getMessage(), 0, 180).'). '
                        .'Click Repair DB credentials next.',
                ];
            }

            return [
                'success' => true,
                'message' => 'Created Laravel cache/storage directories, linked /app/logs → storage/logs, fixed www-data ownership, and cleared config. Re-scan after a reload.',
            ];
        } catch (\Throwable $e) {
            if ($this->looksLikeDatabaseAuthFailure($e->getMessage())) {
                $repair = $this->treatSyncDatabaseCredentials($service);

                return [
                    'success' => $repair['success'],
                    'message' => $repair['success']
                        ? 'Permissions needed a working DB user. '.$repair['message']
                        : 'Failed to fix permissions because MySQL rejected the app user. '.$repair['message'],
                ];
            }

            return ['success' => false, 'message' => 'Failed to fix permissions: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function treatEnsureStorageLink(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $ssh = SSHService::forNode($deployment->node);
        $appDirectory = app(ContainerAppDirectoryService::class);
        $init = app(LaravelAppInitializationService::class);

        try {
            $result = $appDirectory->ensurePublicStorageLink($ssh, $appDirectory->hostAppPath($deployment));
            try {
                $projectRoot = $this->resolveArtisanProjectRoot($ssh, $deployment);
                $init->dockerExecPublic(
                    $ssh,
                    $deployment->container_name,
                    'cd '.escapeshellarg($projectRoot).'; php artisan storage:link --force --no-interaction || true',
                    60
                );
            } catch (\Throwable) {
            }

            $hint = $result !== '' ? ' ('.$result.')' : '';

            return [
                'success' => true,
                'message' => 'Linked public/storage'.$hint.'. Reload the gallery. If images still 404, the media files were not imported — restore them from backup. Do not Reset database.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to link public/storage: '.$e->getMessage()];
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
            app(NginxProxyService::class)->refreshBoundDomainVhosts($service, force: true);

            return [
                'success' => true,
                'message' => 'Web proxy rewritten with 128k header buffers. Retry /home — Restart also switches sessions to file so login no longer depends on cookie size.',
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
     * DirectAdmin Laravel .env often keeps APP_URL=http://domain while Cloudflare serves https://.
     * asset() then emits mixed-content CSS/JS. Rewrite APP_URL and recreate only the app.
     *
     * @return array{success: bool, message: string}
     */
    private function treatFixLaravelAppUrl(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $target = $this->canonicalHttpsAppUrl((string) ($deployment->getAccessUrl() ?? ''));
        if ($target === null) {
            return ['success' => false, 'message' => 'Bind an HTTPS domain before rewriting APP_URL.'];
        }

        $ssh = SSHService::forNode($deployment->node);
        $deploymentService = app(ContainerDeploymentService::class);
        $envService = app(ContainerEnvironmentService::class);
        $init = app(LaravelAppInitializationService::class);

        try {
            $env = is_array($deployment->env_values) ? $deployment->env_values : [];
            $overrides = [
                'APP_URL' => $target,
                'ASSET_URL' => $target,
            ];
            foreach (['FRONTEND_URL', 'VITE_APP_URL'] as $key) {
                $current = trim((string) ($env[$key] ?? ''));
                if ($current !== '' && $this->appUrlIsHttpWhileLiveIsHttps($current, $target)) {
                    $overrides[$key] = $target;
                }
            }

            $env = array_merge($env, $overrides);
            $deployment->update(['env_values' => $env]);
            $envService->syncDotEnvFile($ssh, $service, $deployment, $overrides);
            $deploymentService->persistLaravelRuntimeDriversOnCompose($ssh, $deployment, $overrides);

            try {
                $projectRoot = $this->resolveArtisanProjectRoot($ssh, $deployment);
                $init->dockerExecPublic(
                    $ssh,
                    $deployment->container_name,
                    $this->laravelCacheClearScript($projectRoot),
                    120
                );
            } catch (\Throwable) {
            }

            $deploymentService->restartAppService($ssh, $deployment);

            return [
                'success' => true,
                'message' => 'APP_URL and ASSET_URL are now '.$target.'. Reloaded the app container (MySQL left running). Hard-reload the site so CSS/JS load over HTTPS.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to set HTTPS APP_URL: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
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
     * Rebuild the PHP runtime with nginx + php-fpm and recreate the app container.
     *
     * @return array{success: bool, message: string}
     */
    private function treatSwitchPhpProductionRuntime(Service $service): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }

        $ssh = SSHService::forNode($deployment->node);

        try {
            $message = app(ContainerDeploymentService::class)
                ->refreshPhpProductionRuntime($service, $deployment, $ssh);

            if ($message === '') {
                return ['success' => false, 'message' => 'Could not rewrite the PHP start command for this stack.'];
            }

            $deployment->refresh();
            if ((int) ($deployment->assigned_port ?? 0) > 0) {
                $probe = $this->waitForUpstream($ssh, $deployment, 12);
                if (! $probe['reachable']) {
                    return [
                        'success' => is_string($probe['bootstrapping']),
                        'message' => $message.' '.$this->upstreamFailureMessage($probe),
                    ];
                }
            }

            return [
                'success' => true,
                'message' => $message.' Concurrent requests no longer queue behind PHP’s development server.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'PHP-FPM switch failed: '.$e->getMessage()];
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

        $ssh = SSHService::forNode($deployment->node);
        $deploymentService = app(ContainerDeploymentService::class);

        try {
            $this->healLaravelRuntimeAfterDatabaseRepair($service, $ssh);
            $this->dropMysqlShadowHostsForService($service, $ssh);
        } catch (\Throwable $e) {
            \Log::warning('Doctor pre-restart heal failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $ssh->disconnect();
        }

        try {
            $deploymentService->restart($service);
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
                $slug = strtolower((string) (
                    $service->effectiveContainerTemplate()?->slug
                    ?? $service->product?->containerTemplate?->slug
                    ?? ''
                ));
                $message = in_array($slug, ['laravel', 'php'], true)
                    ? 'Application container recreated with cookie/file drivers (database sidecar left running). Reload the site.'
                    : 'Application container recreated (database sidecar left running). Reload the site.';

                return ['success' => true, 'message' => $message];
            }

            if (is_string($probe['bootstrapping'])) {
                return ['success' => true, 'message' => $this->bootstrapProgressMessage($probe)];
            }

            // `docker compose restart` cannot apply a changed compose environment or
            // port mapping. Recreate (`up --no-deps --force-recreate`) is what Restart uses.
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
