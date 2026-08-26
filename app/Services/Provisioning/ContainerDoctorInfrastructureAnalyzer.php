<?php

namespace App\Services\Provisioning;

/**
 * Correlates Application Hosting logs, Docker state, and node signals into
 * treatable Doctor findings. Used even when the app container is crash-looping
 * (the DB "running" flag is often stale in that case).
 */
class ContainerDoctorInfrastructureAnalyzer
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public function findings(string $logs, string $stack, array $snapshot = []): array
    {
        $haystack = $this->haystack($logs, $snapshot);
        $findings = [];
        $emitted = [];

        foreach ($this->signatures() as $signature) {
            $stacks = $signature['stacks'] ?? ['*'];
            if (! in_array('*', $stacks, true) && ! in_array($stack, $stacks, true)) {
                continue;
            }

            if (! ($signature['match'])($haystack, $snapshot, $stack)) {
                continue;
            }

            $id = (string) $signature['id'];
            if (isset($emitted[$id])) {
                continue;
            }
            $emitted[$id] = true;

            $evidence = $this->evidence($haystack, $signature['patterns'] ?? [], $snapshot);
            $findings[] = [
                'id' => $id,
                'severity' => $signature['severity'],
                'title' => $signature['title'],
                'summary' => $signature['summary'],
                'evidence' => $evidence,
                'treat_action' => $signature['treat_action'],
                'treat_label' => $signature['treat_label'],
                'manual_steps' => $signature['manual_steps'],
                'source' => 'live',
            ];
        }

        $ids = array_column($findings, 'id');
        if (in_array('nginx_boot_failed', $ids, true)) {
            $findings = array_values(array_filter(
                $findings,
                fn (array $f) => ! in_array($f['id'] ?? '', ['stale_php_runtime_image', 'container_crash_loop', 'php_builtin_dev_server'], true)
            ));
        } elseif (in_array('php_builtin_dev_server', $ids, true)) {
            $findings = array_values(array_filter(
                $findings,
                fn (array $f) => ($f['id'] ?? '') !== 'container_crash_loop'
            ));
        }

        return array_values($findings);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function haystack(string $logs, array $snapshot = []): string
    {
        $parts = [
            $logs,
            implode("\n", $snapshot['crash_logs'] ?? []),
            (string) ($snapshot['status'] ?? ''),
            (string) ($snapshot['cmd'] ?? ''),
            (string) ($snapshot['process_list'] ?? ''),
            (string) ($snapshot['image'] ?? ''),
            implode("\n", $snapshot['stopped'] ?? []),
        ];

        return implode("\n", array_filter($parts, fn ($p) => trim((string) $p) !== ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signatures(): array
    {
        return [
            [
                'id' => 'node_disk_exhausted',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => ['/No space left on device/i', '/ENOSPC/i'],
                'match' => function (string $haystack, array $snapshot): bool {
                    $percent = $snapshot['disk_percent'] ?? null;

                    return (is_int($percent) && $percent >= 95)
                        || preg_match('/No space left on device|ENOSPC/i', $haystack) === 1;
                },
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
                'id' => 'nginx_boot_failed',
                'severity' => 'critical',
                'stacks' => ['laravel', 'php'],
                'patterns' => [
                    '/\[emerg\]/i',
                    '/fastcgi_params/i',
                    '/nginx: \[emerg\]/i',
                    '/bind\(\) to 0\.0\.0\.0:\d+ failed/i',
                ],
                'match' => function (string $haystack): bool {
                    return preg_match('/\[emerg\]|nginx: \[emerg\]|open\(\) "\/tmp\/talksasa-php\/fastcgi_params"|bind\(\) to 0\.0\.0\.0:\d+ failed/i', $haystack) === 1;
                },
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
                    '/php-fpm\.sock/i',
                    '/connect\(\) to unix:[^\n]*php-fpm/i',
                ],
                'match' => function (string $haystack): bool {
                    if (preg_match('/\[emerg\]/i', $haystack) === 1) {
                        return false;
                    }

                    return preg_match('/connect\(\) failed[^\n]*php-fpm|No such file or directory[^\n]*php-fpm\.sock|php-fpm\.sock failed/i', $haystack) === 1;
                },
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
                    '/PHP \S+ Development Server/i',
                    '/falling back to php -S/i',
                    '/php artisan serve --host=/i',
                ],
                'match' => function (string $haystack, array $snapshot): bool {
                    if (preg_match('/\[emerg\]|fastcgi_params failed/i', $haystack) === 1) {
                        return false;
                    }

                    $processes = (string) ($snapshot['process_list'] ?? '');
                    if (preg_match('/php\s+-S\b/i', $processes) === 1) {
                        return true;
                    }

                    return preg_match('/PHP \S+ Development Server|falling back to php -S|php artisan serve --host=/i', $haystack) === 1;
                },
                'title' => 'PHP development server is handling web traffic',
                'summary' => 'This app is running PHP’s built-in server (`php -S` / `artisan serve`), which handles one request at a time. CSS/JS can also break if index.php was used as the router. Restart will not fix this.',
                'treat_action' => 'switch_php_production_runtime',
                'treat_label' => 'Switch to PHP-FPM',
                'manual_steps' => [
                    'Click Switch to PHP-FPM — rebuilds nginx + php-fpm and recreates the app container (database is kept).',
                ],
            ],
            [
                'id' => 'stale_php_runtime_image',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => [],
                'match' => function (string $haystack, array $snapshot): bool {
                    $expected = (string) ($snapshot['expected_image'] ?? '');
                    $image = (string) ($snapshot['image'] ?? '');
                    if ($expected === '' || $image === '') {
                        return false;
                    }

                    return $image !== $expected && str_contains($expected, '-r');
                },
                'title' => 'PHP runtime image is behind the platform',
                'summary' => 'This container is still on an older Talksasa PHP image. Boot crashes we already fixed (PATH, FastCGI params, php -S) stay broken until the current revision is rebuilt on the node.',
                'treat_action' => 'switch_php_production_runtime',
                'treat_label' => 'Rebuild PHP-FPM runtime',
                'manual_steps' => [
                    'Rebuild PHP-FPM runtime so the node pulls the current image tag.',
                ],
            ],
            [
                'id' => 'docker_network_missing',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => ['/network .*talksasa-net.* not found/i', '/network .* not found/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/network [^\n]*not found/i', $haystack) === 1;
                },
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
                'patterns' => ['/port is already allocated/i', '/Bind for 0\.0\.0\.0:\d+ failed/i', '/EADDRINUSE/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/port is already allocated|Bind for 0\.0\.0\.0:\d+ failed|EADDRINUSE|address already in use/i', $haystack) === 1;
                },
                'title' => 'Host port is already in use',
                'summary' => 'Another container (or a stale copy of this one) still holds the published port, so Compose cannot start this stack.',
                'treat_action' => 'recreate_application',
                'treat_label' => 'Recreate containers',
                'manual_steps' => [
                    'Recreate containers to drop stale port bindings. If it still fails, an operator must free the host port.',
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
                'match' => function (string $haystack): bool {
                    return preg_match('/SQLSTATE\[HY000\]\s*\[2002\]|SQLSTATE\[HY000\]\s*\[1049\]|Unknown database|Connection refused[^\n]*3306|getaddrinfo failed[^\n]*\bdb\b/i', $haystack) === 1;
                },
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
                'patterns' => ['/Connection refused[^\n]*6379/i', '/RedisException/i', '/php_network_getaddresses[^\n]*redis/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/RedisException|Connection refused[^\n]*6379|getaddrinfo failed[^\n]*redis/i', $haystack) === 1;
                },
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
                'patterns' => ['/vendor\/autoload\.php/i', '/Composer detected issues/i', '/Failed opening required[^\n]*vendor/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/Failed opening required[^\n]*vendor|vendor\/autoload\.php[^\n]*failed to open stream|Composer detected issues in your platform/i', $haystack) === 1;
                },
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
                'patterns' => ['/Allowed memory size of/i', '/Fatal error: Allowed memory size/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/Allowed memory size of \d+ bytes exhausted/i', $haystack) === 1;
                },
                'title' => 'PHP ran out of memory',
                'summary' => 'A request or artisan command hit the PHP memory_limit. Restart clears the crash; a larger plan or a cheaper query is the real fix.',
                'treat_action' => 'restart_application',
                'treat_label' => 'Restart application',
                'manual_steps' => [
                    'Restart to recover, then raise the plan if this repeats on normal traffic.',
                ],
            ],
            [
                'id' => 'mix_manifest_missing',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => ['/Mix manifest not found/i', '/Vite manifest not found/i', '/Unable to locate file in Vite manifest/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/Mix manifest not found|Vite manifest not found|Unable to locate file in Vite manifest/i', $haystack) === 1;
                },
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
                'id' => 'php_fpm_max_children',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php'],
                'patterns' => ['/server reached pm\.max_children/i', '/seems busy, spawning/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/server reached pm\.max_children|seems busy, spawning|max_children \(\d+\) already spawned/i', $haystack) === 1;
                },
                'title' => 'PHP-FPM ran out of workers',
                'summary' => 'All PHP-FPM children are busy, so extra requests 500. Concurrent Ultimate POS polls plus DataTables queries fill a small worker pool.',
                'treat_action' => 'tune_request_concurrency',
                'treat_label' => 'Relax session/cache locking',
                'manual_steps' => [
                    'Click Relax session/cache locking (cookie sessions + file cache).',
                    'If it still saturates workers, upgrade the plan.',
                ],
            ],
            [
                'id' => 'php_max_execution_time',
                'severity' => 'warning',
                'stacks' => ['laravel', 'php', 'wordpress', '*'],
                'patterns' => ['/Maximum execution time of \d+ seconds exceeded/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/Maximum execution time of \d+ seconds exceeded/i', $haystack) === 1;
                },
                'title' => 'A request exceeded PHP max_execution_time',
                'summary' => 'A heavy page ran longer than max_execution_time. Concurrent polls then 500 while that worker is stuck.',
                'treat_action' => 'tune_request_concurrency',
                'treat_label' => 'Relax session/cache locking',
                'manual_steps' => [
                    'Relax session/cache locking so other tabs are not blocked on the slow query.',
                ],
            ],
            [
                'id' => 'compose_unset_variable',
                'severity' => 'info',
                'stacks' => ['*'],
                'patterns' => ['/variable is not set\. Defaulting to a blank string/i'],
                'match' => function (string $haystack): bool {
                    return preg_match('/variable is not set\. Defaulting to a blank string/i', $haystack) === 1;
                },
                'title' => 'Docker Compose is interpolating empty environment variables',
                'summary' => 'Compose warns when ${MAIL_USERNAME} (and similar) are not in the project .env. This does not take the site down.',
                'treat_action' => 'fix_compose_interpolation',
                'treat_label' => 'Fill Compose env defaults',
                'manual_steps' => [
                    'Click Fill Compose env defaults.',
                    'Set real SMTP values in the Environment tab if the app should send mail.',
                ],
            ],
            [
                'id' => 'db_sidecar_down',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => [],
                'match' => function (string $haystack, array $snapshot): bool {
                    return ($snapshot['db_sidecar_running'] ?? null) === false;
                },
                'title' => 'Database sidecar is not running',
                'summary' => 'The MySQL/Postgres container for this app is stopped or restarting. The site cannot query until that sidecar stays up.',
                'treat_action' => 'recreate_application',
                'treat_label' => 'Recreate containers',
                'manual_steps' => [
                    'Recreate containers (keeps the database volume).',
                    'If MySQL keeps restarting, check host disk and the DB sidecar logs.',
                ],
            ],
            [
                'id' => 'container_crash_loop',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => ['/Restarting \(\d+\)/i'],
                'match' => function (string $haystack, array $snapshot): bool {
                    if (! empty($snapshot['oom'])) {
                        return false;
                    }
                    if (($snapshot['restarting'] ?? false) === true) {
                        return true;
                    }

                    return preg_match('/Restarting \(\d+\)/i', $haystack) === 1
                        && preg_match('/\[emerg\]|falling back to php -S/i', $haystack) !== 1;
                },
                'title' => 'Application container is crash-looping',
                'summary' => 'Docker Compose is restarting the app container. Nothing stays bound to the published port until the boot error in Logs is fixed.',
                'treat_action' => 'recreate_application',
                'treat_label' => 'Recreate containers',
                'manual_steps' => [
                    'Read the last boot error in Logs, then Recreate containers.',
                    'If this is a Laravel/PHP stack after a runtime change, use Rebuild PHP-FPM runtime instead.',
                ],
            ],
            [
                'id' => 'oom_killed',
                'severity' => 'critical',
                'stacks' => ['*'],
                'patterns' => ['/oom-kill/i', '/Out of memory/i', '/Cannot allocate memory/i'],
                'match' => function (string $haystack, array $snapshot): bool {
                    return ! empty($snapshot['oom'])
                        || preg_match('/oom-kill|Out of memory|Cannot allocate memory/i', $haystack) === 1;
                },
                'title' => 'Container was killed because it ran out of RAM',
                'summary' => 'The kernel OOM-killed this app (or a build inside it). Restart may come back; a larger plan is needed if it repeats.',
                'treat_action' => 'restart_application',
                'treat_label' => 'Restart application',
                'manual_steps' => [
                    'Restart, then upgrade the plan if OOM continues.',
                    'Avoid npm/composer while the site is under traffic on small plans.',
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $patterns
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function evidence(string $haystack, array $patterns, array $snapshot): array
    {
        $lines = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $haystack, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $lines[] = mb_substr(trim($match), 0, 240);
                }
            }
        }

        foreach (['status', 'image', 'expected_image', 'cmd'] as $key) {
            if (! empty($snapshot[$key]) && is_string($snapshot[$key])) {
                $lines[] = $key.': '.mb_substr($snapshot[$key], 0, 180);
            }
        }

        if (($snapshot['publishes_port'] ?? null) === false && ! empty($snapshot['assigned_port'])) {
            $lines[] = 'host port '.$snapshot['assigned_port'].' is not published';
        }

        if (is_int($snapshot['disk_percent'] ?? null)) {
            $lines[] = 'node disk '.$snapshot['disk_percent'].'% used';
        }

        $lines = array_values(array_unique(array_filter($lines)));

        return array_slice($lines, 0, 6);
    }
}
