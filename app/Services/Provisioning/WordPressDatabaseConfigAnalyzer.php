<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

/**
 * Whether WordPress can reach the database, as opposed to whether the platform can.
 *
 * Doctor probed the connection with the credentials the platform stores, which
 * is the wrong set. The official image writes wp-config.php once, on first
 * boot, and nothing updates it afterwards, so a rotated password or a pinned
 * host lands in compose while WordPress keeps using what it was born with.
 * The probe then passes, the panel says the database is connected, and the
 * visitor still sees "Error establishing a database connection".
 *
 * A support tool that is confidently wrong is worse than one that says nothing,
 * so this asks WordPress's own question with WordPress's own credentials.
 */
class WordPressDatabaseConfigAnalyzer
{
    /** @var list<string> */
    private const FIELDS = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];

    /**
     * What WordPress resolves each define to at runtime.
     *
     * A literal define wins outright. `getenv_docker(KEY, default)` reads the
     * container's environment and falls back to the default written into the
     * file, which is exactly what PHP does when the site boots.
     *
     * @return array<string, string>
     */
    public function effectiveCredentials(SSHService $ssh, string $containerName): array
    {
        $config = app(ContainerDeploymentService::class)->readWordPressConfigFile($ssh, $containerName);
        if (trim($config) === '') {
            return [];
        }

        $resolved = [];

        foreach (self::FIELDS as $field) {
            $envPattern = "/define\\s*\\(\\s*['\"]{$field}['\"]\\s*,\\s*getenv_docker\\s*\\(\\s*['\"]([^'\"]+)['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/i";
            if (preg_match($envPattern, $config, $matches) === 1) {
                $fromEnv = $this->containerEnv($ssh, $containerName, $matches[1]);
                $value = $fromEnv !== '' ? $fromEnv : $matches[2];
                if ($value !== '') {
                    $resolved[$field] = $value;
                }

                continue;
            }

            $literalPattern = "/define\\s*\\(\\s*['\"]{$field}['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/i";
            if (preg_match($literalPattern, $config, $matches) === 1 && $matches[1] !== '') {
                $resolved[$field] = $matches[1];
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $platformEnv  what the platform believes the credentials are
     * @return array{findings: list<array<string, mixed>>, checks: array<string, mixed>}
     */
    public function analyze(
        SSHService $ssh,
        ContainerDeployment $deployment,
        array $platformEnv,
        ?bool $platformProbeOk,
    ): array {
        $empty = ['findings' => [], 'checks' => []];
        $containerName = (string) $deployment->container_name;

        $wordpress = $this->effectiveCredentials($ssh, $containerName);
        if ($wordpress === []) {
            // No wp-config yet, which is a first boot rather than a fault.
            return $empty;
        }

        $differences = $this->differences($wordpress, $platformEnv);
        $probe = $this->probe($ssh, $deployment, $wordpress);

        $checks = [
            'wordpress_db_ok' => $probe['ok'],
            'wordpress_db_error' => $probe['error'],
            'wordpress_config_differs' => $differences !== [],
        ];

        $host = trim((string) ($wordpress['DB_HOST'] ?? ''));
        $checks['wordpress_db_host'] = $host;

        // Checked before the probe result, because a shared name can resolve to
        // the right database this second and the wrong one next. A passing
        // probe is not evidence that this is fine.
        if ($host !== '' && $this->hostIsShared($host)) {
            return [
                'findings' => [$this->sharedHostFinding(
                    $host,
                    app(ContainerDeploymentService::class)->sidecarDnsHost($containerName),
                )],
                'checks' => $checks,
            ];
        }

        if (! $probe['ok']) {
            return [
                'findings' => [$this->unreachableFinding($differences, (string) $probe['error'], $platformProbeOk)],
                'checks' => $checks,
            ];
        }

        // Reported whatever the platform's own probe did or did not manage. It
        // was gated on that probe having succeeded, so on a service the platform
        // could not test at all — the exact state a broken site is in — a real
        // disagreement was found, shown in the checks row, and then dropped.
        if ($differences !== []) {
            return ['findings' => [$this->mismatchFinding($differences)], 'checks' => $checks];
        }

        return ['findings' => [], 'checks' => $checks];
    }

    /**
     * Which fields disagree, named but never valued: one of them is a password.
     *
     * @param  array<string, string>  $wordpress
     * @param  array<string, mixed>  $platformEnv
     * @return list<string>
     */
    public function differences(array $wordpress, array $platformEnv): array
    {
        $platform = [
            'DB_HOST' => (string) ($platformEnv['WORDPRESS_DB_HOST'] ?? $platformEnv['DB_HOST'] ?? ''),
            'DB_NAME' => (string) ($platformEnv['WORDPRESS_DB_NAME'] ?? $platformEnv['DB_DATABASE'] ?? $platformEnv['MYSQL_DATABASE'] ?? ''),
            'DB_USER' => (string) ($platformEnv['WORDPRESS_DB_USER'] ?? $platformEnv['DB_USERNAME'] ?? $platformEnv['MYSQL_USER'] ?? ''),
            'DB_PASSWORD' => (string) ($platformEnv['WORDPRESS_DB_PASSWORD'] ?? $platformEnv['DB_PASSWORD'] ?? $platformEnv['MYSQL_PASSWORD'] ?? ''),
        ];

        $differences = [];

        foreach (self::FIELDS as $field) {
            $theirs = trim((string) ($wordpress[$field] ?? ''));
            $ours = trim($platform[$field]);

            if ($theirs === '' || $ours === '') {
                continue;
            }

            if ($field === 'DB_HOST') {
                // "db:3306" and "db" are the same host to everyone but a string
                // comparison, and the port is never the thing that differs.
                $theirs = explode(':', $theirs, 2)[0];
                $ours = explode(':', $ours, 2)[0];
            }

            if ($theirs !== $ours) {
                $differences[] = $field;
            }
        }

        return $differences;
    }

    /**
     * @param  array<string, string>  $wordpress
     * @return array{ok: bool, error: ?string}
     */
    private function probe(SSHService $ssh, ContainerDeployment $deployment, array $wordpress): array
    {
        $host = trim((string) ($wordpress['DB_HOST'] ?? ''));
        if ($host === '') {
            // Nothing to dial. Not a failure we measured.
            return ['ok' => true, 'error' => null];
        }

        $deployments = app(ContainerDeploymentService::class);
        $port = 3306;
        if (preg_match('/^(.+):(\d+)$/', $host, $matched) === 1) {
            $host = $matched[1];
            $port = (int) $matched[2];
        }

        // The literal host, never a corrected one. The platform's own probe
        // rewrites an ambiguous name such as "mysql" to this stack's unique
        // sidecar DNS before dialling, which is helpful when you want to know
        // whether the database is alive and actively misleading when you want
        // to know whether WordPress can reach it. Asking WordPress's question
        // means dialling exactly the string WordPress holds, from inside the
        // container WordPress runs in.
        $script = $deployments->phpWordpressMysqliEvalScript(
            $host,
            $port,
            (string) ($wordpress['DB_NAME'] ?? ''),
            (string) ($wordpress['DB_USER'] ?? ''),
            (string) ($wordpress['DB_PASSWORD'] ?? ''),
        );

        try {
            $ssh->exec(
                $deployments->phpDatabaseProbeCommand((string) $deployment->container_name, $script, 'wordpress'),
                20,
            );

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => mb_substr(trim($e->getMessage()), -300)];
        }
    }

    /**
     * A host every WordPress sidecar on the shared network answers to.
     *
     * Inside one compose project "mysql" resolves to that project's own
     * database, which is why this can look fine for months. On a node where
     * every stack shares one network it resolves to whichever sidecar Docker
     * picked, so the site works, then does not, for no reason anybody can see.
     */
    public function hostIsShared(string $host): bool
    {
        return app(ContainerDeploymentService::class)->isAmbiguousSharedNetworkDatabaseHost($host);
    }

    /**
     * @return array<string, mixed>
     */
    public function sharedHostFinding(string $host, string $uniqueHost): array
    {
        return [
            'id' => 'wordpress_config_shared_database_host',
            'severity' => 'critical',
            'title' => 'wp-config.php points at the shared database hostname',
            'summary' => 'wp-config.php has DB_HOST set to "'.$host.'". Every WordPress sidecar on this node '
                .'answers to that name, so Docker resolves it to whichever database it feels like, and the site '
                .'shows "Error establishing a database connection" whenever that is not its own. It needs this '
                .'stack\'s own name, '.$uniqueHost.'. Repair writes it into wp-config.php and keeps the data.',
            'evidence' => [
                'wp-config DB_HOST='.$host,
                'this stack\'s database='.$uniqueHost,
            ],
            'treat_action' => 'sync_database_credentials',
            'treat_label' => 'Repair DB credentials',
            'manual_steps' => [
                'Click Repair DB credentials — it pins the unique database name in wp-config.php.',
                'The database volume is untouched; nothing is dropped or recreated.',
            ],
            'source' => 'live',
        ];
    }

    /**
     * @param  list<string>  $differences
     * @return array<string, mixed>
     */
    public function unreachableFinding(array $differences, string $error, ?bool $platformProbeOk): array
    {
        $named = $differences === []
            ? 'The values match what the platform stores, so the database itself refused them.'
            : 'wp-config.php disagrees with the platform on: '.implode(', ', $differences).'.';

        // Three states, not two. Null means the platform never tested a
        // database for this service, and claiming its credentials also failed
        // would be inventing a result nobody measured.
        $platformNote = match ($platformProbeOk) {
            true => ', while the ones the platform stores work',
            false => ', and so do the ones the platform stores',
            default => '',
        };

        return [
            'id' => 'wordpress_config_cannot_reach_database',
            'severity' => 'critical',
            'title' => 'WordPress cannot reach the database with its own settings',
            'summary' => 'The credentials inside wp-config.php fail to connect'.$platformNote.'. '
                .'WordPress reads that file, not the container environment, so the site shows '
                .'"Error establishing a database connection" whatever the panel says. '
                .$named.' Repair rewrites wp-config.php to credentials that authenticate and keeps the data.',
            'evidence' => array_values(array_filter([
                $differences === [] ? null : 'wp-config differs on '.implode(', ', $differences),
                $error !== '' ? mb_substr($error, 0, 300) : null,
                match ($platformProbeOk) {
                    true => 'platform credentials connect',
                    false => 'platform credentials also fail',
                    default => 'the platform has no database recorded for this service to test',
                },
            ])),
            'treat_action' => 'sync_database_credentials',
            'treat_label' => 'Repair DB credentials',
            'manual_steps' => [
                'Click Repair DB credentials — it finds a password that authenticates and writes it into wp-config.php.',
                'The database volume is untouched; nothing is dropped or recreated.',
            ],
            'source' => 'live',
        ];
    }

    /**
     * @param  list<string>  $differences
     * @return array<string, mixed>
     */
    public function mismatchFinding(array $differences): array
    {
        return [
            'id' => 'wordpress_config_database_mismatch',
            'severity' => 'warning',
            'title' => 'WordPress is using different database settings than the platform',
            'summary' => 'Both sets of credentials connect, but wp-config.php and the platform disagree on '
                .implode(', ', $differences).'. The site is therefore reading and writing a database that backups, '
                .'imports and this panel are not looking at. On a shared host that can also mean another '
                .'customer\'s MySQL answered.',
            'evidence' => array_map(
                static fn (string $field): string => $field.' differs between wp-config.php and the platform',
                $differences,
            ),
            'treat_action' => 'sync_database_credentials',
            'treat_label' => 'Repair DB credentials',
            'manual_steps' => [
                'Confirm which database holds the live content before repairing, because this points WordPress at the platform\'s one.',
                'Take a backup first if you are not sure.',
            ],
            'source' => 'live',
        ];
    }

    private function containerEnv(SSHService $ssh, string $containerName, string $key): string
    {
        try {
            return trim($ssh->exec(
                'docker exec '.escapeshellarg($containerName)
                .' printenv '.escapeshellarg($key).' 2>/dev/null || true',
                10
            ));
        } catch (\Throwable) {
            return '';
        }
    }
}
