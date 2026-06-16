<?php

namespace App\Console\Commands;

use App\Models\ContainerDeployment;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\SSH\SSHService;

class AutoRestartContainersCommand extends BaseCronCommand
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';

    private const RESTART_TIMEOUT = 120;

    private const MAX_RESTART_ATTEMPTS = 3;

    protected $signature = 'cron:auto-restart-containers';

    protected $description = 'Monitor and auto-restart failed containers (every 5 minutes)';

    protected function handleCron(): string
    {
        $deployments = ContainerDeployment::with('service.user', 'node')
            ->where('status', 'running')
            ->where('auto_restart', true)
            ->get();

        if ($deployments->isEmpty()) {
            return 'No containers with auto-restart enabled.';
        }

        $restarted = 0;
        $failed = 0;
        $notified = 0;

        foreach ($deployments as $deployment) {
            try {
                if (! $deployment->node || ! $deployment->node->is_active) {
                    continue;
                }

                $ssh = SSHService::forNode($deployment->node);

                try {
                    $deploymentService = new ContainerDeploymentService;
                    $deploymentService->ensureComposeFileExists($ssh, $deployment);

                    $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
                    $statusCmd = "cd {$containerPath} && docker compose -f docker-compose.yml ps --format json 2>/dev/null | jq -r '.[0].State // \"error\"'";
                    $status = trim($ssh->exec($statusCmd));

                    if ($status !== 'running') {
                        $this->line("  <fg=yellow>Restarting</> deployment {$deployment->id}...");

                        $ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml restart", self::RESTART_TIMEOUT);

                        sleep(2);

                        $newStatus = trim($ssh->exec($statusCmd));

                        if ($newStatus === 'running') {
                            if ($deployment->restart_attempts > 0) {
                                app(NotificationService::class)->notifyContainerAutoRestarted(
                                    $deployment->service,
                                    $deployment->restart_attempts
                                );
                            }

                            $deployment->update([
                                'restart_attempts' => 0,
                                'last_restart_at' => now(),
                                'last_status_check_at' => now(),
                            ]);

                            $this->line("  <fg=green>✓ Restarted</> deployment {$deployment->id}");
                            $restarted++;
                        } else {
                            $deployment->increment('restart_attempts');

                            if ($deployment->restart_attempts >= self::MAX_RESTART_ATTEMPTS) {
                                $deployment->service->update(['status' => 'failed']);
                                $this->line("  <fg=red>✗ Failed</> deployment {$deployment->id} after {$deployment->restart_attempts} attempts");

                                app(NotificationService::class)->notifyContainerFailed(
                                    $deployment->service,
                                    'Container failed to restart after '.self::MAX_RESTART_ATTEMPTS.' attempts'
                                );
                                $notified++;
                                $failed++;
                            } else {
                                $this->line("  <fg=yellow>⚠ Restart attempt {$deployment->restart_attempts}</> for deployment {$deployment->id}");
                            }
                        }
                    }
                } finally {
                    $ssh->disconnect();
                }
            } catch (\Exception $e) {
                $this->line("  <fg=red>✗ Error</> checking deployment {$deployment->id}: {$e->getMessage()}");
                \Log::error("Auto-restart check failed for deployment {$deployment->id}", ['error' => $e->getMessage()]);
                $failed++;
            }
        }

        return "Auto-restart complete: {$restarted} restarted, {$failed} failed, {$notified} notified.";
    }
}
