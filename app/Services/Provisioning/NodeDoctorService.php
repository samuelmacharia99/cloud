<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Container Doctor, pointed at the server instead of one customer's container.
 *
 * Findings carry the same shape the container console already renders, so the
 * operator screen is the one that exists rather than a second vocabulary for
 * the same idea: id, severity, title, summary, evidence, treat_action,
 * treat_label, manual_steps.
 *
 * Every repair offered here restarts something the platform owns. None of them
 * touch a customer's files, database or account, and that boundary is what
 * makes a one-click button safe on a box holding other people's businesses. A
 * full disk gets the ten largest directories and no delete button.
 */
class NodeDoctorService
{
    public const START_SERVICE_ACTION = 'start_node_service';

    public const RESTART_PANEL_ACTION = 'restart_directadmin';

    /** Below this, a service that is up has only just come up. */
    private const FLAPPING_SECONDS = 600;

    private const DISK_WARNING_PERCENT = 85;

    private const DISK_CRITICAL_PERCENT = 93;

    private const INODE_CRITICAL_PERCENT = 90;

    /** Sustained load per core above which a shared box is not serving anybody well. */
    private const LOAD_PER_CORE_CRITICAL = 4.0;

    /** A queue this old has stopped draining rather than being busy. */
    private const QUEUE_STALE_SECONDS = 1800;

    /**
     * Roles whose absence takes every site on the node down at once, and the
     * words an operator uses for them.
     *
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        'web' => 'the web server',
        'database' => 'the database server',
        'mail' => 'the mail server',
        'imap' => 'the IMAP server',
        'dns' => 'the DNS server',
        'panel' => 'DirectAdmin itself',
        'ssh' => 'SSH',
    ];

    public function __construct(
        private DirectAdminNodeInspector $inspector,
    ) {}

    public function supports(Node $node): bool
    {
        return $node->type === 'directadmin';
    }

    /**
     * @return array{scanned_at: string, reachable: bool, findings: list<array<string, mixed>>, checks: array<string, mixed>}
     */
    public function diagnose(Node $node, ?SSHService $ssh = null): array
    {
        $reading = $this->inspector->inspect($node, $ssh);

        $findings = $reading['reachable']
            ? $this->findings($reading)
            : [$this->unreachableFinding($reading)];

        usort($findings, fn (array $a, array $b): int => $this->weight($a) <=> $this->weight($b));

        return [
            'scanned_at' => now()->toIso8601String(),
            'reachable' => (bool) $reading['reachable'],
            'findings' => $findings,
            'checks' => $this->checks($reading),
        ];
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return list<array<string, mixed>>
     */
    private function findings(array $reading): array
    {
        return array_values(array_filter(array_merge(
            $this->serviceFindings($reading['services'] ?? []),
            $this->filesystemFindings($reading['filesystems'] ?? []),
            [
                $this->taskQueueFinding($reading['task_queue'] ?? null),
                $this->loadFinding($reading['load'] ?? []),
                $this->swapFinding($reading['memory'] ?? []),
            ],
        )));
    }

    /**
     * @param  array<string, array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    private function serviceFindings(array $services): array
    {
        $findings = [];

        foreach ($services as $role => $service) {
            $label = self::ROLE_LABELS[$role] ?? $role;
            $unit = (string) $service['unit'];

            if (! $service['running']) {
                $findings[] = [
                    'id' => 'node_service_down_'.$role,
                    'severity' => 'critical',
                    'title' => ucfirst($label).' is not running',
                    'summary' => 'The '.$unit.' service is '.$service['state'].' on this node. '
                        .($role === 'web' || $role === 'database'
                            ? 'Every site on this server is down until it starts.'
                            : 'Everything that depends on it has stopped working.')
                        .' Start it, and its own log will say why it stopped.',
                    'evidence' => [$unit.' is '.$service['state']],
                    'treat_action' => self::START_SERVICE_ACTION,
                    'treat_label' => 'Start '.$unit,
                    'treat_payload' => ['unit' => $unit],
                    'manual_steps' => [
                        'On the node: systemctl status '.$unit.' then journalctl -u '.$unit.' -n 50',
                        'A service that will not stay started is usually out of memory or out of disk, both of which are checked above.',
                    ],
                ];

                continue;
            }

            $since = $service['seconds_since_start'];
            if ($since !== null && $since < self::FLAPPING_SECONDS) {
                $findings[] = [
                    'id' => 'node_service_flapping_'.$role,
                    'severity' => 'warning',
                    'title' => ucfirst($label).' restarted recently',
                    'summary' => 'The '.$unit.' service has been up for '.$this->humanSeconds($since)
                        .'. It is running now, so nothing is offered to restart it again: that is what it is '
                        .'already doing to itself. Read its log for the reason it keeps going down.',
                    'evidence' => [$unit.' started '.$this->humanSeconds($since).' ago'],
                    'treat_action' => null,
                    'treat_label' => null,
                    'manual_steps' => [
                        'On the node: journalctl -u '.$unit.' --since "1 hour ago"',
                        'Check memory and disk above before assuming the service itself is at fault.',
                    ],
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  list<array<string, mixed>>  $filesystems
     * @return list<array<string, mixed>>
     */
    private function filesystemFindings(array $filesystems): array
    {
        $findings = [];

        foreach ($filesystems as $fs) {
            $mount = (string) $fs['mount'];
            $used = (int) $fs['used_percent'];
            $inodes = $fs['inode_used_percent'];

            if ($used >= self::DISK_WARNING_PERCENT) {
                $critical = $used >= self::DISK_CRITICAL_PERCENT;
                $findings[] = [
                    'id' => 'node_disk_'.md5($mount),
                    'severity' => $critical ? 'critical' : 'warning',
                    'title' => $mount.' is '.$used.'% full',
                    'summary' => $critical
                        ? 'A full partition stops MySQL writing, stops mail being delivered and stops '
                            .'sites saving anything. Nothing here deletes a file: on a shared server the '
                            .'space belongs to customers, and the platform is not the one to decide which of it goes.'
                        : 'There is still room, but this is the point at which somebody should look.',
                    'evidence' => [
                        $mount.' '.$used.'% of '.$this->humanBytes((int) $fs['total_bytes']),
                    ],
                    'treat_action' => null,
                    'treat_label' => null,
                    'manual_steps' => [
                        'On the node: du -x -h -d 2 '.$mount.' 2>/dev/null | sort -rh | head -10',
                        'Old DirectAdmin backups under /home/*/backups and /home/admin/admin_backups are the usual answer.',
                        'Rotate logs before deleting anything: journalctl --vacuum-size=200M, and /var/log/*.gz.',
                    ],
                ];
            }

            if (is_int($inodes) && $inodes >= self::INODE_CRITICAL_PERCENT) {
                $findings[] = [
                    'id' => 'node_inodes_'.md5($mount),
                    'severity' => 'critical',
                    'title' => $mount.' is out of inodes',
                    'summary' => 'This partition has '.$inodes.'% of its inodes used while showing '
                        .$used.'% of its space used. A filesystem with free space and no inodes cannot '
                        .'create a single new file, and nothing reports it as full. Mail spools and '
                        .'session directories are the usual cause: millions of tiny files.',
                    'evidence' => [
                        $mount.' inodes '.$inodes.'% used, space '.$used.'% used',
                    ],
                    'treat_action' => null,
                    'treat_label' => null,
                    'manual_steps' => [
                        'On the node: for d in '.$mount.'/*; do echo "$(find "$d" -xdev 2>/dev/null | wc -l) $d"; done | sort -rn | head',
                        'Look for /home/*/imap and PHP session directories before anything else.',
                    ],
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>|null  $queue
     * @return array<string, mixed>|null
     */
    private function taskQueueFinding(?array $queue): ?array
    {
        if ($queue === null) {
            return null;
        }

        $age = $queue['age_seconds'];
        if ((int) $queue['length'] === 0 || $age === null || $age < self::QUEUE_STALE_SECONDS) {
            return null;
        }

        return [
            'id' => 'node_task_queue_stalled',
            'severity' => 'critical',
            'title' => 'DirectAdmin has stopped working through its queue',
            'summary' => 'There are '.$queue['length'].' tasks waiting and nothing has touched the queue for '
                .$this->humanSeconds((int) $age).'. Everything DirectAdmin schedules runs from this file: '
                .'account creation, suspensions, backups, SSL renewals. When it stalls, all of that silently '
                .'stops and no error appears anywhere a customer or an operator would see it.',
            'evidence' => [
                $queue['length'].' queued tasks',
                'queue last written '.$this->humanSeconds((int) $age).' ago',
            ],
            'treat_action' => self::RESTART_PANEL_ACTION,
            'treat_label' => 'Restart DirectAdmin',
            'manual_steps' => [
                'Restarting directadmin restarts dataskq, which is what drains this queue.',
                'On the node: tail -20 /usr/local/directadmin/data/task.queue to see what is stuck.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $load
     * @return array<string, mixed>|null
     */
    private function loadFinding(array $load): ?array
    {
        $perCore = (float) ($load['per_core'] ?? 0);
        if ($perCore < self::LOAD_PER_CORE_CRITICAL) {
            return null;
        }

        return [
            'id' => 'node_load_high',
            'severity' => 'critical',
            'title' => 'The server has more work than it can run',
            'summary' => 'Load is '.$load['one'].' across '.$load['cores'].' cores, which is '
                .$perCore.' per core. Above about four, requests are waiting rather than running, and '
                .'every site on the node feels it. The platform has been storing this number without '
                .'ever dividing it by the core count it was measured on.',
            'evidence' => [
                'load '.$load['one'].' / '.$load['five'].' / '.$load['fifteen'].' over '.$load['cores'].' cores',
            ],
            'treat_action' => null,
            'treat_label' => null,
            'manual_steps' => [
                'On the node: top -bn1 | head -20 to see which account is responsible.',
                'A single site under attack or a runaway cron is the usual cause on a shared box.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $memory
     * @return array<string, mixed>|null
     */
    private function swapFinding(array $memory): ?array
    {
        $swapTotal = (int) ($memory['swap_total'] ?? 0);
        $swapUsed = (int) ($memory['swap_used'] ?? 0);

        if ($swapTotal <= 0 || $swapUsed <= 0) {
            return null;
        }

        $percent = (int) round(($swapUsed / $swapTotal) * 100);
        if ($percent < 25) {
            return null;
        }

        return [
            'id' => 'node_swapping',
            'severity' => 'warning',
            'title' => 'The server is swapping',
            'summary' => 'Swap is '.$percent.'% used. On a shared server this is the warning that arrives '
                .'before the kernel starts killing processes, and the process it picks belongs to whichever '
                .'customer happens to be using memory at that moment.',
            'evidence' => [
                'swap '.$this->humanBytes($swapUsed).' of '.$this->humanBytes($swapTotal),
                'memory '.$this->humanBytes((int) ($memory['used'] ?? 0)).' of '.$this->humanBytes((int) ($memory['total'] ?? 0)),
            ],
            'treat_action' => null,
            'treat_label' => null,
            'manual_steps' => [
                'On the node: ps -eo pid,user,rss,cmd --sort=-rss | head -15',
                'Sustained swapping on a shared box means the plan mix is too heavy for the hardware.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
     */
    private function unreachableFinding(array $reading): array
    {
        return [
            'id' => 'node_unreachable',
            'severity' => 'critical',
            'title' => 'The platform cannot reach this server',
            'summary' => 'Nothing below was measured. This is not a healthy node, and it is not a node '
                .'reporting a fault either: it is a node that could not be asked. '
                .'Either the server is down or the platform has lost its way in.',
            'evidence' => array_filter([(string) ($reading['error'] ?? '')]),
            'treat_action' => null,
            'treat_label' => null,
            'manual_steps' => [
                'Check the server is up and reachable on its SSH port from the platform.',
                'Confirm the stored SSH credentials on this node are still valid.',
            ],
        ];
    }

    /**
     * The single line an operator reads before the findings.
     *
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
     */
    private function checks(array $reading): array
    {
        if (! $reading['reachable']) {
            return ['reachable' => false];
        }

        $services = $reading['services'] ?? [];

        return [
            'reachable' => true,
            'init' => $reading['init'] ?? null,
            'services_up' => count(array_filter($services, fn (array $s): bool => (bool) $s['running'])),
            'services_total' => count($services),
            'load_per_core' => $reading['load']['per_core'] ?? null,
            'uptime_seconds' => $reading['uptime_seconds'] ?? null,
            'task_queue_length' => $reading['task_queue']['length'] ?? null,
            'filesystems' => count($reading['filesystems'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function weight(array $finding): int
    {
        return match ($finding['severity'] ?? 'info') {
            'critical' => 0,
            'warning' => 1,
            default => 2,
        };
    }

    private function humanSeconds(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds.' seconds';
        }

        if ($seconds < 5400) {
            return (int) round($seconds / 60).' minutes';
        }

        if ($seconds < 172800) {
            return (int) round($seconds / 3600).' hours';
        }

        return (int) round($seconds / 86400).' days';
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 4) {
            return round($bytes / (1024 ** 4), 1).' TB';
        }

        if ($bytes >= 1024 ** 3) {
            return round($bytes / (1024 ** 3), 1).' GB';
        }

        return round($bytes / (1024 ** 2), 1).' MB';
    }

    /**
     * Restart something the platform owns, and nothing else.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string}
     */
    public function treat(Node $node, string $action, array $payload = []): array
    {
        if (! $this->supports($node)) {
            return ['success' => false, 'message' => 'This repair is for DirectAdmin nodes.'];
        }

        $unit = match ($action) {
            self::RESTART_PANEL_ACTION => 'directadmin',
            self::START_SERVICE_ACTION => trim((string) ($payload['unit'] ?? '')),
            default => null,
        };

        if ($unit === null) {
            return ['success' => false, 'message' => 'Unknown repair.'];
        }

        // Only units this inspector knows how to find. A repair that accepts any
        // name from a request is a remote root shell wearing a button.
        if (! $this->isKnownUnit($unit)) {
            return ['success' => false, 'message' => 'That service is not one this node reports.'];
        }

        $ssh = SSHService::forNode($node);
        $safe = escapeshellarg($unit);

        try {
            $ssh->exec(
                'systemctl restart '.$safe.' 2>&1 || service '.$safe.' restart 2>&1',
                60,
            );

            // Believe the node, not the command. A unit that exits zero and then
            // dies two seconds later is the shape this whole feature exists for.
            sleep(3);
            $state = trim((string) $ssh->exec(
                'systemctl is-active '.$safe.' 2>/dev/null || service '.$safe.' status >/dev/null 2>&1 && echo active || echo failed',
                20,
            ));

            if (! str_contains($state, 'active')) {
                return [
                    'success' => false,
                    'message' => $unit.' was restarted and is '.($state ?: 'not running').' moments later. '
                        .'Read its log on the node before trying again: something is stopping it.',
                ];
            }

            return ['success' => true, 'message' => $unit.' is running.'];
        } catch (\Throwable $e) {
            Log::warning('Node service repair failed', [
                'node_id' => $node->id,
                'unit' => $unit,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Could not restart '.$unit.': '.mb_substr(trim($e->getMessage()), 0, 300),
            ];
        } finally {
            $ssh->disconnect();
        }
    }

    private function isKnownUnit(string $unit): bool
    {
        if (preg_match('/^[a-z0-9@._-]{1,64}$/i', $unit) !== 1) {
            return false;
        }

        foreach (DirectAdminNodeInspector::roleUnits() as $candidates) {
            if (in_array($unit, $candidates, true)) {
                return true;
            }
        }

        return false;
    }
}
