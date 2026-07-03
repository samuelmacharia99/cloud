<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Models\ContainerDeployment;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\SSH\SSHService;

class AutoRestartContainersCommand extends BaseCronCommand
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';

    private const RESTART_TIMEOUT = 120;

    private const MAX_RESTART_ATTEMPTS = 3;

    public function __construct(
        private ContainerRuntimeInspector $runtimeInspector,
    ) {
        parent::__construct();
    }

    protected $signature = 'cron:auto-restart-containers';

    protected $description = 'Monitor and auto-restart failed containers (every 5 minutes)';

    protected function handleCron(): string
    {
        $deployments = ContainerDeployment::with('service.user', 'node')
            ->where('auto_restart', true)
            ->whereIn('status', ['running', 'stopped', 'failed', 'deploying'])
            ->whereHas('service', fn ($query) => $query->where('status', ServiceStatus::Active))
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
                    $deploymentService = app(ContainerDeploymentService::class);
                    $deploymentService->ensureComposeFileExists($ssh, $deployment);

                    $inspect = $this->runtimeInspector->inspect($ssh, $deployment->container_name);

                    if (($inspect['running'] ?? false) === true) {
                        if ($deployment->status !== 'running' || $deployment->restart_attempts > 0) {
                            $this->runtimeInspector->syncDeploymentStatus($deployment, $inspect);
                            $deployment->update(['restart_attempts' => 0]);
                        }

                        continue;
                    }

                    $this->line("  <fg=yellow>Restarting</> deployment {$deployment->id}...");

                    $deploymentService->startComposeStack($ssh, $deployment->service, $deployment);

                    sleep(3);

                    $inspect = $this->runtimeInspector->inspect($ssh, $deployment->container_name);

                    if (($inspect['running'] ?? false) === true) {
                        if ($deployment->restart_attempts > 0) {
                            app(NotificationService::class)->notifyContainerAutoRestarted(
                                $deployment->service,
                                $deployment->restart_attempts
                            );
                        }

                        $this->runtimeInspector->syncDeploymentStatus($deployment, $inspect);
                        $deployment->update([
                            'restart_attempts' => 0,
                            'last_restart_at' => now(),
                        ]);

                        $this->line("  <fg=green>✓ Restarted</> deployment {$deployment->id}");
                        $restarted++;
                    } else {
                        $deployment->increment('restart_attempts');
                        $deployment->refresh();

                        if ($deployment->restart_attempts >= self::MAX_RESTART_ATTEMPTS) {
                            $message = 'Container failed to restart after '.self::MAX_RESTART_ATTEMPTS.' attempts';
                            $this->runtimeInspector->syncDeploymentStatus($deployment, $inspect, $message);
                            $deployment->update(['status' => 'failed']);

                            $meta = is_array($deployment->service->service_meta)
                                ? $deployment->service->service_meta
                                : [];
                            $deployment->service->update([
                                'service_meta' => array_merge($meta, [
                                    'container_restart_exhausted_at' => now()->toIso8601String(),
                                    'container_restart_message' => $message,
                                ]),
                            ]);

                            $this->line("  <fg=red>✗ Failed</> deployment {$deployment->id} after {$deployment->restart_attempts} attempts");

                            app(NotificationService::class)->notifyContainerFailed(
                                $deployment->service,
                                $message
                            );
                            $notified++;
                            $failed++;
                        } else {
                            $this->line("  <fg=yellow>⚠ Restart attempt {$deployment->restart_attempts}</> for deployment {$deployment->id}");
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
