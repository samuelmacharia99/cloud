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
            'clear_laravel_caches' => $this->treatClearLaravelCaches($service),
            'fix_storage_permissions' => $this->treatFixStoragePermissions($service),
            'restart_application' => $this->treatRestartApplication($service),
            'run_migrations' => $this->treatRunMigrations($service),
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
        ];
        $findings = [];

        if (! $deployment?->node || ! $deployment->isRunning()) {
            return ['findings' => $findings, 'checks' => $checks];
        }

        $deploymentService = app(ContainerDeploymentService::class);
        $databaseTemplate = $deploymentService->resolveDatabaseTemplateForService($service);
        $stack = strtolower((string) ($service->product?->containerTemplate?->slug ?? ''));
        $ssh = SSHService::forNode($deployment->node);

        try {
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
                    $tableCount = $deploymentService->countApplicationDatabaseTables(
                        $ssh,
                        $deployment->container_name,
                        (string) $databaseTemplate->type,
                        $probeEnv
                    );
                    $checks['table_count'] = $tableCount;

                    $hasArtisan = $this->containerHasArtisan($ssh, $deployment);

                    if ($tableCount === 0 && $hasArtisan && in_array($stack, ['laravel', 'php'], true)) {
                        $findings[] = [
                            'id' => 'live_empty_database',
                            'severity' => 'critical',
                            'title' => 'Live check: database has no tables',
                            'summary' => 'DB credentials work for "'.($probeEnv['DB_DATABASE'] ?? '').'", but the schema is empty. '
                                .'The app will keep returning 500 until migrations/seed run.',
                            'evidence' => ['table_count=0', 'DB_DATABASE='.($probeEnv['DB_DATABASE'] ?? '')],
                            'treat_action' => 'run_migrations',
                            'treat_label' => 'Run migrations',
                            'manual_steps' => [
                                'Click Run migrations, or in Terminal: php artisan migrate --force',
                            ],
                            'source' => 'live',
                        ];
                    }
                }
            }

            $httpStatus = $this->probeHttpStatus($ssh, $deployment);
            $checks['http_status'] = $httpStatus;

            if ($httpStatus !== null && $httpStatus >= 500) {
                $hasDbFinding = collect($findings)->contains(
                    fn ($f) => in_array($f['id'] ?? '', ['live_db_connection_failed', 'live_env_credential_drift', 'live_empty_database', 'live_missing_pdo'], true)
                );

                $appErrors = $this->readRecentApplicationErrors($ssh, $deployment);
                $evidence = array_values(array_filter([
                    'HTTP '.$httpStatus,
                    (string) ($deployment->getAccessUrl() ?? ''),
                    ...$appErrors,
                ]));

                if ($hasDbFinding) {
                    $findings[] = [
                        'id' => 'live_http_5xx',
                        'severity' => 'critical',
                        'title' => 'Live check: site returns HTTP '.$httpStatus,
                        'summary' => 'The public URL is returning HTTP '.$httpStatus.'. Fix the database findings above first, then re-scan.',
                        'evidence' => $evidence,
                        'treat_action' => 'sync_database_credentials',
                        'treat_label' => 'Repair DB credentials',
                        'manual_steps' => [
                            'Re-scan after applying the suggested fix.',
                            'In Terminal check: tail -n 80 storage/logs/laravel.log',
                        ],
                        'source' => 'live',
                    ];
                } elseif (($checks['table_count'] ?? null) === 0 && $this->containerHasArtisan($ssh, $deployment)) {
                    $findings[] = [
                        'id' => 'live_http_5xx',
                        'severity' => 'critical',
                        'title' => 'Live check: site returns HTTP '.$httpStatus.' (empty database)',
                        'summary' => 'Database credentials work, but there are no tables yet. Run migrations to clear the 500.',
                        'evidence' => $evidence,
                        'treat_action' => 'run_migrations',
                        'treat_label' => 'Run migrations',
                        'manual_steps' => [
                            'Click Run migrations.',
                            'If migrate fails, inspect the evidence / laravel.log.',
                        ],
                        'source' => 'live',
                    ];
                } else {
                    $looksLikeMissingTable = collect($appErrors)->contains(
                        fn ($line) => (bool) preg_match('/relation .* does not exist|Base table or view not found|no such table/i', (string) $line)
                    );

                    $findings[] = [
                        'id' => 'live_http_5xx',
                        'severity' => 'critical',
                        'title' => 'Live check: site returns HTTP '.$httpStatus,
                        'summary' => $looksLikeMissingTable
                            ? 'DB connects, but the app error looks like missing tables/migrations.'
                            : 'DB credentials look healthy, but the public URL still returns HTTP '.$httpStatus
                                .'. This is now an application error (not credential drift).',
                        'evidence' => $evidence,
                        'treat_action' => $looksLikeMissingTable
                            ? 'run_migrations'
                            : (in_array($stack, ['laravel', 'php'], true) ? 'clear_laravel_caches' : 'restart_application'),
                        'treat_label' => $looksLikeMissingTable
                            ? 'Run migrations'
                            : (in_array($stack, ['laravel', 'php'], true) ? 'Clear Laravel caches' : 'Restart application'),
                        'manual_steps' => [
                            'Read the evidence lines from the app log.',
                            'In Terminal: tail -n 120 storage/logs/laravel.log',
                            'Fix the application exception, then re-scan.',
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
     * @return list<string>
     */
    private function readRecentApplicationErrors(SSHService $ssh, $deployment): array
    {
        $scripts = [
            'if [ -f /app/backend/storage/logs/laravel.log ]; then tail -n 80 /app/backend/storage/logs/laravel.log; '
                .'elif [ -f /app/storage/logs/laravel.log ]; then tail -n 80 /app/storage/logs/laravel.log; fi',
        ];

        $lines = [];
        foreach ($scripts as $script) {
            try {
                $output = trim($ssh->exec(
                    'docker exec '.escapeshellarg($deployment->container_name)
                    .' sh -lc '.escapeshellarg($script),
                    20
                ));
                if ($output === '') {
                    continue;
                }

                foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if (preg_match('/(SQLSTATE|ERROR|Exception|FATAL|relation .* does not exist|Base table or view not found)/i', $line)) {
                        $lines[] = mb_substr($line, 0, 240);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_slice(array_values(array_unique($lines)), -5);
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
            $platformEnv = is_array($deployment->env_values) ? $deployment->env_values : [];
            $liveEnv = $this->readLiveAppEnvironment($ssh, $deployment);
            $rawEnv = $liveEnv === [] ? $platformEnv : array_merge($platformEnv, $liveEnv);

            // Keep a handle on the volume's original platform role for admin bootstrap.
            $canonical = $deploymentService->canonicalDatabaseIdentifiers($service);
            if (! empty($platformEnv['DB_USERNAME']) || ! empty($platformEnv['POSTGRES_USER'])) {
                $rawEnv['TALKSASA_PLATFORM_DB_USERNAME'] = (string) (
                    $platformEnv['DB_USERNAME']
                    ?? $platformEnv['POSTGRES_USER']
                );
            } else {
                $rawEnv['TALKSASA_PLATFORM_DB_USERNAME'] = $canonical['username'];
            }
            if (! empty($platformEnv['DB_PASSWORD']) || ! empty($platformEnv['POSTGRES_PASSWORD'])) {
                $rawEnv['TALKSASA_PLATFORM_DB_PASSWORD'] = (string) (
                    $platformEnv['DB_PASSWORD']
                    ?? $platformEnv['POSTGRES_PASSWORD']
                );
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

            $stack = strtolower((string) ($service->product?->containerTemplate?->slug ?? ''));
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
            $result = $this->runMigrationsQuietly($service, $ssh);
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
    private function runMigrationsQuietly(Service $service, SSHService $ssh): array
    {
        $deployment = $service->containerDeployment;
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
                .'php artisan migrate --force --no-interaction';

            $output = $init->dockerExecPublic($ssh, $deployment->container_name, $script, 300);

            return [
                'success' => true,
                'message' => 'Migrations completed. '.mb_substr(trim((string) $output), 0, 200),
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
