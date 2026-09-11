<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

class ContainerRuntimeInspector
{
    /**
     * @return array{
     *     missing: bool,
     *     running: bool,
     *     state: string,
     *     oom_killed: bool,
     *     exit_code: int|null,
     *     restarting: bool,
     *     restart_count: int
     * }
     */
    public function inspect(SSHService $ssh, string $containerName, bool $retry = true): array
    {
        $safeName = escapeshellarg($containerName);
        // Restarting and RestartCount ride along on the inspect that was already
        // being made. Without them a caller can only see that a container is
        // running, which a crash-looping one is, for a second, between restarts.
        $output = trim($ssh->exec(
            "docker inspect --type container --format '{{.State.Status}}|{{.State.Running}}|{{.State.OOMKilled}}|{{.State.ExitCode}}|{{.State.Restarting}}|{{.RestartCount}}' {$safeName} 2>/dev/null || echo ''",
            10,
            $retry
        ));

        if ($output === '') {
            return [
                'missing' => true,
                'running' => false,
                'state' => 'unknown',
                'oom_killed' => false,
                'exit_code' => null,
                'restarting' => false,
                'restart_count' => 0,
            ];
        }

        // Padded to six so an older node, or a docker that answers with fewer
        // fields, degrades to the previous four rather than throwing.
        [$state, $runningRaw, $oomRaw, $exitCodeRaw, $restartingRaw, $restartCountRaw] = array_pad(
            explode('|', $output, 6),
            6,
            '',
        );
        $state = trim($state) !== '' ? trim($state) : 'unknown';

        return [
            'missing' => false,
            'running' => strtolower(trim($runningRaw)) === 'true',
            'state' => $state,
            'oom_killed' => strtolower(trim($oomRaw)) === 'true',
            'exit_code' => is_numeric(trim($exitCodeRaw)) ? (int) trim($exitCodeRaw) : null,
            'restarting' => strtolower(trim($restartingRaw)) === 'true' || $state === 'restarting',
            'restart_count' => is_numeric(trim($restartCountRaw)) ? (int) trim($restartCountRaw) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $inspect
     */
    public function syncDeploymentStatus(ContainerDeployment $deployment, array $inspect, ?string $detail = null): void
    {
        if (($inspect['missing'] ?? false) === true) {
            $status = 'stopped';
            $output = $detail ?? 'Container not found on node';
        } else {
            $status = ($inspect['running'] ?? false) ? 'running' : 'stopped';
            $output = $detail ?? json_encode([
                'state' => $inspect['state'] ?? 'unknown',
                'running' => $inspect['running'] ?? false,
                'oom_killed' => $inspect['oom_killed'] ?? false,
                'exit_code' => $inspect['exit_code'] ?? null,
            ]);
        }

        $statusChanged = $deployment->status !== $status;
        $detailChanged = $deployment->last_status_check_output !== $output;
        $staleHeartbeat = $deployment->last_status_check_at === null
            || $deployment->last_status_check_at->lt(now()->subMinutes(15));

        // Metrics runs every five minutes. Avoid rewriting the deployment row when
        // nothing meaningful changed and the heartbeat is still fresh.
        if (! $statusChanged && ! $detailChanged && ! $staleHeartbeat) {
            return;
        }

        $deployment->update([
            'status' => $status,
            'last_status_check_at' => now(),
            'last_status_check_output' => $output,
        ]);
    }
}
