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
     *     exit_code: int|null
     * }
     */
    public function inspect(SSHService $ssh, string $containerName): array
    {
        $safeName = escapeshellarg($containerName);
        $output = trim($ssh->exec(
            "docker inspect --type container --format '{{.State.Status}}|{{.State.Running}}|{{.State.OOMKilled}}|{{.State.ExitCode}}' {$safeName} 2>/dev/null || echo ''",
            10
        ));

        if ($output === '') {
            return [
                'missing' => true,
                'running' => false,
                'state' => 'unknown',
                'oom_killed' => false,
                'exit_code' => null,
            ];
        }

        [$state, $runningRaw, $oomRaw, $exitCodeRaw] = array_pad(explode('|', $output, 4), 4, '');
        $state = trim($state) !== '' ? trim($state) : 'unknown';

        return [
            'missing' => false,
            'running' => strtolower(trim($runningRaw)) === 'true',
            'state' => $state,
            'oom_killed' => strtolower(trim($oomRaw)) === 'true',
            'exit_code' => is_numeric(trim($exitCodeRaw)) ? (int) trim($exitCodeRaw) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $inspect
     */
    public function syncDeploymentStatus(ContainerDeployment $deployment, array $inspect, ?string $detail = null): void
    {
        if (($inspect['missing'] ?? false) === true) {
            $deployment->update([
                'status' => 'stopped',
                'last_status_check_at' => now(),
                'last_status_check_output' => $detail ?? 'Container not found on node',
            ]);

            return;
        }

        $status = ($inspect['running'] ?? false) ? 'running' : 'stopped';
        $output = $detail ?? json_encode([
            'state' => $inspect['state'] ?? 'unknown',
            'running' => $inspect['running'] ?? false,
            'oom_killed' => $inspect['oom_killed'] ?? false,
            'exit_code' => $inspect['exit_code'] ?? null,
        ]);

        $deployment->update([
            'status' => $status,
            'last_status_check_at' => now(),
            'last_status_check_output' => $output,
        ]);
    }
}
