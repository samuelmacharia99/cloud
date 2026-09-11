<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decide what is new, write it down, and tell somebody once.
 *
 * A poll that alerts on every run is a poll nobody reads. A node down at three
 * in the morning would send thirty Telegram messages before anybody woke up,
 * and the thirty-first would be muted along with everything after it. So this
 * compares each scan against the last one and acts only on the difference:
 * a finding that has appeared, and a finding that has gone.
 *
 * The comparison lives in the cache rather than the events table because it is
 * a working note, not a record. Losing it costs one duplicate alert; querying
 * the table on every poll for every node costs a query per node forever.
 */
class NodeIncidentRecorder
{
    private const STATE_PREFIX = 'node_doctor_state:';

    /** Long enough to survive a cache restart between two-minute polls. */
    private const STATE_TTL_HOURS = 24;

    public function __construct(
        private TelegramMonitorBridge $telegram,
    ) {}

    /**
     * @param  array{findings: list<array<string, mixed>>, reachable: bool, checks: array<string, mixed>}  $diagnosis
     * @return array{opened: list<string>, resolved: list<string>}
     */
    public function reconcile(Node $node, array $diagnosis): array
    {
        $key = self::STATE_PREFIX.$node->id;
        $previous = (array) Cache::get($key, []);

        $current = [];
        foreach ($diagnosis['findings'] as $finding) {
            if (($finding['severity'] ?? '') === 'info') {
                continue;
            }

            $current[(string) $finding['id']] = $finding;
        }

        $opened = array_values(array_diff(array_keys($current), array_keys($previous)));
        $resolved = array_values(array_diff(array_keys($previous), array_keys($current)));

        foreach ($opened as $id) {
            $this->open($node, $current[$id]);
        }

        foreach ($resolved as $id) {
            $this->resolve($node, $id, (array) ($previous[$id] ?? []));
        }

        Cache::put(
            $key,
            array_map(
                fn (array $finding): array => [
                    'title' => $finding['title'] ?? '',
                    'severity' => $finding['severity'] ?? 'warning',
                ],
                $current,
            ),
            now()->addHours(self::STATE_TTL_HOURS),
        );

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Node $node, string $event, string $severity = 'info', array $payload = []): void
    {
        try {
            NodeEvent::create([
                'node_id' => $node->id,
                'event' => $event,
                'severity' => $severity,
                'payload' => $payload ?: null,
                'recorded_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A record of work is never the work. A full disk on the platform's
            // own database must not stop it reporting a full disk on a node.
            Log::warning("Could not record node event '{$event}'", [
                'node_id' => $node->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function open(Node $node, array $finding): void
    {
        $severity = (string) ($finding['severity'] ?? 'warning');

        $this->record($node, 'node_issue_opened', $severity, [
            'finding' => $finding['id'] ?? null,
            'title' => $finding['title'] ?? null,
            'evidence' => $finding['evidence'] ?? [],
        ]);

        if ($severity !== 'critical') {
            // A warning belongs on the screen an operator opens, not on the
            // phone of whoever is asleep.
            return;
        }

        $this->alert($node, $finding);
    }

    /**
     * @param  array<string, mixed>  $previous
     */
    private function resolve(Node $node, string $id, array $previous): void
    {
        $this->record($node, 'node_issue_resolved', 'info', [
            'finding' => $id,
            'title' => $previous['title'] ?? null,
        ]);

        if (($previous['severity'] ?? '') !== 'critical') {
            return;
        }

        // Exactly one recovery message, and only for something that woke
        // somebody up. An all-clear nobody was waiting for is still noise.
        $this->telegram->systemAlert('Node recovered: '.$node->name, [
            'Node' => $node->name,
            'Host' => (string) $node->hostname,
            'Cleared' => (string) ($previous['title'] ?? $id),
        ]);
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function alert(Node $node, array $finding): void
    {
        $fingerprint = sha1($node->id.'|'.($finding['id'] ?? ''));
        $cooldownKey = 'node_doctor_alert:'.$fingerprint;

        // Belt and braces over the transition check above. A cache flush would
        // otherwise make every finding look new again.
        if (! Cache::add($cooldownKey, true, now()->addMinutes($this->cooldownMinutes()))) {
            return;
        }

        $this->telegram->systemAlert('Node problem: '.$node->name, array_filter([
            'Node' => $node->name,
            'Host' => (string) $node->hostname,
            'Problem' => (string) ($finding['title'] ?? 'Unknown'),
            'Detail' => mb_substr((string) ($finding['summary'] ?? ''), 0, 400),
            'Evidence' => implode(' · ', array_slice((array) ($finding['evidence'] ?? []), 0, 3)),
        ]));
    }

    private function cooldownMinutes(): int
    {
        return max(5, (int) config('cron.node_doctor.alert_cooldown_minutes', 60));
    }
}
