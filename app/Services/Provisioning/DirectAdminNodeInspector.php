<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Services\SSH\SSHService;

/**
 * What is actually happening on a shared hosting server.
 *
 * The platform has polled these nodes every two minutes for months and read six
 * numbers: an uptime string, memory, one partition, the core count and the load
 * average. None of those go wrong when httpd dies, when MySQL stops accepting
 * connections, when the DirectAdmin task queue wedges, or when a partition runs
 * out of inodes while reporting plenty of free space. Every one of those takes
 * every site on the box down at once, and the first report came from a customer.
 *
 * Nothing here assumes what is installed. A DirectAdmin box is usually
 * AlmaLinux, where the web server is httpd and the database is mysqld or
 * mariadb; some are Debian, where it is apache2. The node is asked what it has
 * before it is asked whether that is running, because a check that assumes the
 * wrong name reports a healthy server with a dead web server on it.
 */
class DirectAdminNodeInspector
{
    /** One command, not twelve. A poll runs every two minutes against every node. */
    private const TIMEOUT_SECONDS = 25;

    /** Marks the sections of the single batched command's output. */
    private const SECTION = '===TALKSASA===';

    /**
     * Service units worth knowing about, by the roles they fill. The first one
     * present on the node wins, so a box with mariadb and a box with mysqld are
     * both answered without the caller caring which.
     *
     * @var array<string, list<string>>
     */
    private const ROLES = [
        'web' => ['httpd', 'apache2', 'nginx', 'litespeed', 'openlitespeed'],
        'database' => ['mysqld', 'mariadb', 'mysql'],
        'mail' => ['exim'],
        'imap' => ['dovecot'],
        'dns' => ['named', 'bind9'],
        'panel' => ['directadmin'],
        'ssh' => ['sshd', 'ssh'],
    ];

    /**
     * The unit names this inspector will ever report, by role.
     *
     * Public because the doctor's repairs are restricted to these: a repair
     * that accepts any service name from a request is a remote root shell
     * wearing a button.
     *
     * @return array<string, list<string>>
     */
    public static function roleUnits(): array
    {
        return self::ROLES;
    }

    /**
     * @return array<string, mixed> everything the doctor needs, or a single
     *                              'reachable' => false when the node could not
     *                              be asked at all
     */
    public function inspect(Node $node, ?SSHService $ssh = null): array
    {
        $owned = $ssh === null;
        $ssh ??= SSHService::forNode($node);

        try {
            $raw = $ssh->exec($this->command(), self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            // Unreachable is its own answer. Reporting a node as healthy
            // because nothing could be read is how a dead server stays green.
            return [
                'reachable' => false,
                'error' => mb_substr(trim($e->getMessage()), 0, 300),
                'services' => [],
                'filesystems' => [],
            ];
        } finally {
            if ($owned) {
                $ssh->disconnect();
            }
        }

        $sections = $this->split($raw);

        return [
            'reachable' => true,
            'error' => null,
            'init' => trim($sections['init'] ?? '') !== '' ? 'systemd' : 'sysv',
            'services' => $this->services($sections),
            'filesystems' => $this->filesystems($sections),
            'memory' => $this->memory($sections),
            'load' => $this->load($sections),
            'task_queue' => $this->taskQueue($sections),
            'uptime_seconds' => $this->uptimeSeconds($sections),
        ];
    }

    /**
     * Everything in one round trip.
     *
     * The previous probe issued six separate execs every two minutes per node,
     * each paying its own SSH channel setup. Adding a dozen service checks on
     * top of that shape would have made the poll slower than the interval it
     * runs on.
     */
    public function command(): string
    {
        $m = self::SECTION;
        $units = implode('|', array_merge(...array_values(self::ROLES)));

        return implode(' ; ', [
            "echo '{$m}init'",
            'command -v systemctl >/dev/null 2>&1 && echo systemd || true',
            "echo '{$m}units'",
            'if command -v systemctl >/dev/null 2>&1; then '
                // Raw, not reduced. systemctl prints UNIT LOAD ACTIVE SUB
                // DESCRIPTION, and reshaping it here would mean the parser and
                // the command have to agree about a format neither of them owns.
                .'systemctl list-units --type=service --all --no-legend --no-pager 2>/dev/null; '
                .'else '
                .'for s in '.str_replace('|', ' ', $units).'; do '
                .'if [ -x /etc/init.d/$s ]; then '
                .'if service $s status >/dev/null 2>&1; then echo "$s.service loaded active running"; '
                .'else echo "$s.service loaded active dead"; fi; fi; done; fi',
            "echo '{$m}started'",
            'if command -v systemctl >/dev/null 2>&1; then '
                .'for s in '.str_replace('|', ' ', $units).'; do '
                .'p=$(systemctl show -p ActiveEnterTimestampMonotonic --value $s 2>/dev/null); '
                .'[ -n "$p" ] && echo "$s $p"; done; fi',
            "echo '{$m}df'",
            'df -PB1 2>/dev/null | tail -n +2',
            "echo '{$m}inodes'",
            'df -PiT 2>/dev/null | tail -n +2',
            "echo '{$m}mem'",
            'free -b 2>/dev/null | grep -Ei "^(Mem|Swap):"',
            "echo '{$m}load'",
            'cat /proc/loadavg',
            "echo '{$m}cores'",
            'grep -c ^processor /proc/cpuinfo',
            "echo '{$m}uptime'",
            'cut -d. -f1 /proc/uptime',
            "echo '{$m}queue'",
            'q=/usr/local/directadmin/data/task.queue; '
                .'if [ -f "$q" ]; then wc -l < "$q"; stat -c %Y "$q" 2>/dev/null || echo 0; else echo skip; fi',
            "echo '{$m}end'",
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function split(string $raw): array
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (str_starts_with(trim($line), self::SECTION)) {
                $current = substr(trim($line), strlen(self::SECTION));
                $sections[$current] = '';

                continue;
            }

            if ($current !== null) {
                $sections[$current] .= $line."\n";
            }
        }

        return $sections;
    }

    /**
     * One entry per role the node actually has, never per name we hoped for.
     *
     * @param  array<string, string>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function services(array $sections): array
    {
        $units = [];
        foreach (preg_split('/\R/', $sections['units'] ?? '') ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $name = preg_replace('/\.service$/', '', (string) ($parts[0] ?? ''));
            if ($name === '' || $name === null) {
                continue;
            }

            // UNIT LOAD ACTIVE SUB DESCRIPTION…
            $units[$name] = [
                'active' => strtolower((string) ($parts[2] ?? '')),
                'sub' => strtolower((string) ($parts[3] ?? '')),
            ];
        }

        $started = [];
        foreach (preg_split('/\R/', $sections['started'] ?? '') ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) >= 2 && is_numeric($parts[1])) {
                // Monotonic microseconds since boot. Zero means it has never
                // started, which is different from having started long ago.
                $started[$parts[0]] = (int) $parts[1];
            }
        }

        $monotonicNow = max([0, ...array_values($started)]);

        $services = [];
        foreach (self::ROLES as $role => $candidates) {
            foreach ($candidates as $name) {
                if (! isset($units[$name])) {
                    continue;
                }

                $enter = $started[$name] ?? null;
                $services[$role] = [
                    'unit' => $name,
                    'running' => $units[$name]['sub'] === 'running',
                    'state' => $units[$name]['sub'] ?: $units[$name]['active'],
                    'seconds_since_start' => $enter !== null && $enter > 0 && $monotonicNow > 0
                        ? max(0, (int) (($monotonicNow - $enter) / 1_000_000))
                        : null,
                ];

                break;
            }
        }

        return $services;
    }

    /**
     * Space and inodes together, because they fail independently. A mail spool
     * can be out of inodes while the partition reports forty per cent free, and
     * a check that reads only one of them calls that healthy.
     *
     * @param  array<string, string>  $sections
     * @return list<array<string, mixed>>
     */
    private function filesystems(array $sections): array
    {
        $inodes = [];
        foreach (preg_split('/\R/', $sections['inodes'] ?? '') ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            // filesystem type inodes used free use% mount
            if (count($parts) < 7) {
                continue;
            }

            $inodes[end($parts)] = [
                'type' => $parts[1],
                'used_percent' => (int) rtrim((string) $parts[5], '%'),
            ];
        }

        $rows = [];
        foreach (preg_split('/\R/', $sections['df'] ?? '') ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 6) {
                continue;
            }

            $mount = end($parts);
            $type = $inodes[$mount]['type'] ?? '';

            // Pseudo filesystems are always full or always empty and mean
            // nothing to an operator.
            if (in_array($type, ['tmpfs', 'devtmpfs', 'squashfs', 'overlay', 'iso9660'], true)) {
                continue;
            }

            $rows[] = [
                'mount' => $mount,
                'total_bytes' => (int) $parts[1],
                'used_bytes' => (int) $parts[2],
                'used_percent' => (int) rtrim((string) $parts[4], '%'),
                'inode_used_percent' => $inodes[$mount]['used_percent'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $sections
     * @return array<string, int>
     */
    private function memory(array $sections): array
    {
        $memory = ['total' => 0, 'used' => 0, 'swap_total' => 0, 'swap_used' => 0];

        foreach (preg_split('/\R/', $sections['mem'] ?? '') ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 3) {
                continue;
            }

            if (stripos($parts[0], 'mem') === 0) {
                $memory['total'] = (int) $parts[1];
                $memory['used'] = (int) $parts[2];
            } elseif (stripos($parts[0], 'swap') === 0) {
                $memory['swap_total'] = (int) $parts[1];
                $memory['swap_used'] = (int) $parts[2];
            }
        }

        return $memory;
    }

    /**
     * Load means nothing without the core count it is measured against, and the
     * platform has been storing it without one.
     *
     * @param  array<string, string>  $sections
     * @return array<string, float|int>
     */
    private function load(array $sections): array
    {
        $parts = preg_split('/\s+/', trim($sections['load'] ?? '')) ?: [];
        $cores = max(1, (int) trim($sections['cores'] ?? '1'));

        return [
            'one' => (float) ($parts[0] ?? 0),
            'five' => (float) ($parts[1] ?? 0),
            'fifteen' => (float) ($parts[2] ?? 0),
            'cores' => $cores,
            'per_core' => round(((float) ($parts[0] ?? 0)) / $cores, 2),
        ];
    }

    /**
     * DirectAdmin's own work queue. When dataskq stops draining it, nothing the
     * panel schedules happens: no account creation, no backups, no SSL renewal,
     * and no error anywhere that a customer or an operator would ever see.
     *
     * @param  array<string, string>  $sections
     * @return array<string, mixed>|null
     */
    private function taskQueue(array $sections): ?array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $sections['queue'] ?? '') ?: []),
            fn (string $line): bool => $line !== '',
        ));

        if ($lines === [] || $lines[0] === 'skip') {
            return null;
        }

        $modifiedAt = isset($lines[1]) && is_numeric($lines[1]) ? (int) $lines[1] : null;

        return [
            'length' => (int) $lines[0],
            'age_seconds' => $modifiedAt !== null && $modifiedAt > 0 ? max(0, time() - $modifiedAt) : null,
        ];
    }

    /**
     * @param  array<string, string>  $sections
     */
    private function uptimeSeconds(array $sections): ?int
    {
        $value = trim($sections['uptime'] ?? '');

        return is_numeric($value) ? (int) $value : null;
    }
}
