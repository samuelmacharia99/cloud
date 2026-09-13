<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Enums\StackMemberKind;
use App\Models\ContainerDeployment;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\Provisioning\StackMember;
use App\Services\Provisioning\StackMemberResolver;
use App\Services\Provisioning\StackMemberStateService;
use App\Services\SSH\SSHService;
use Illuminate\Support\Str;

class AutoRestartContainersCommand extends BaseCronCommand
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';

    private const RESTART_TIMEOUT = 120;

    private const MAX_RESTART_ATTEMPTS = 3;

    public function __construct(
        private ContainerRuntimeInspector $runtimeInspector,
        private ?StackMemberResolver $members = null,
        private ?StackMemberStateService $memberStates = null,
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
                    $sidecarDown = $this->embeddedDatabaseSidecarNeedsStart($ssh, $deployment);

                    if (($inspect['running'] ?? false) === true && ! $sidecarDown) {
                        if ($deployment->status !== 'running' || $deployment->restart_attempts > 0) {
                            $this->runtimeInspector->syncDeploymentStatus($deployment, $inspect);
                            $deployment->update(['restart_attempts' => 0]);
                        }

                        continue;
                    }

                    $reason = ($inspect['running'] ?? false) !== true
                        ? 'app container not running'
                        : 'database sidecar not running';
                    $this->line("  <fg=yellow>Restarting</> deployment {$deployment->id} ({$reason})...");

                    $deploymentService->startComposeStack($ssh, $deployment->service, $deployment);

                    $waitSeconds = $this->restartWaitSeconds($deployment);
                    $deadline = time() + $waitSeconds;
                    $inspect = ['running' => false];
                    $sidecarDown = true;

                    do {
                        sleep(5);
                        $inspect = $this->runtimeInspector->inspect($ssh, $deployment->container_name);
                        $sidecarDown = $this->embeddedDatabaseSidecarNeedsStart($ssh, $deployment);

                        if (($inspect['running'] ?? false) === true && ! $sidecarDown) {
                            break;
                        }
                    } while (time() < $deadline);

                    if (($inspect['running'] ?? false) === true && ! $sidecarDown) {
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
                        $this->refreshMemberStates($ssh, $deployment);

                        $this->line("  <fg=green>✓ Restarted</> deployment {$deployment->id}");
                        $restarted++;
                    } else {
                        $diagnostics = $this->captureRestartDiagnostics($ssh, $deployment);
                        $deployment->increment('restart_attempts');
                        $deployment->refresh();

                        \Log::warning('Container auto-restart still down after wait', [
                            'deployment_id' => $deployment->id,
                            'container' => $deployment->container_name,
                            'wait_seconds' => $waitSeconds,
                            'diagnostics' => $diagnostics,
                        ]);

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
                                    'container_restart_diagnostics' => Str::limit($diagnostics, 2000),
                                ]),
                            ]);

                            $this->line("  <fg=red>✗ Failed</> deployment {$deployment->id} after {$deployment->restart_attempts} attempts");
                            $this->line('    '.$diagnostics);

                            app(NotificationService::class)->notifyContainerFailed(
                                $deployment->service,
                                $message
                            );
                            $notified++;
                            $failed++;
                        } else {
                            $this->line("  <fg=yellow>⚠ Restart attempt {$deployment->restart_attempts}</> for deployment {$deployment->id}");
                            $this->line('    '.$diagnostics);
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

    /**
     * Every database the stack runs (WordPress's mysql, the injected db, a
     * template's bundled one). After a host reboot the app may come up while
     * the database stays stopped; that is a down stack. Decided from a live
     * inspect, never from the cached snapshot.
     */
    private function embeddedDatabaseSidecarNeedsStart(SSHService $ssh, ContainerDeployment $deployment): bool
    {
        foreach ($this->databaseMembers($deployment) as $member) {
            $inspect = $this->runtimeInspector->inspect($ssh, $member->containerName);
            if (($inspect['running'] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<StackMember>
     */
    private function databaseMembers(ContainerDeployment $deployment): array
    {
        $deployment->loadMissing('service');
        if (! $deployment->service || trim((string) ($deployment->docker_compose_content ?? '')) === '') {
            return [];
        }

        return array_values(array_filter(
            $this->members()->membersForService($deployment->service),
            fn (StackMember $member) => $member->kind === StackMemberKind::Database && ! $member->synthesized
        ));
    }

    private function restartWaitSeconds(ContainerDeployment $deployment): int
    {
        return $this->databaseMembers($deployment) !== [] ? 90 : 30;
    }

    private function refreshMemberStates(SSHService $ssh, ContainerDeployment $deployment): void
    {
        try {
            ($this->memberStates ?? app(StackMemberStateService::class))->refresh($deployment, $ssh);
        } catch (\Throwable $e) {
            \Log::warning('Member state refresh after auto-restart failed', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function members(): StackMemberResolver
    {
        return $this->members ??= app(StackMemberResolver::class);
    }

    private function captureRestartDiagnostics(SSHService $ssh, ContainerDeployment $deployment): string
    {
        $path = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $pathArg = escapeshellarg($path);

        try {
            $ps = trim($ssh->exec(
                "cd {$pathArg} && docker compose -f docker-compose.yml ps -a 2>&1 | tail -n 20",
                30
            ));
            $logs = trim($ssh->exec(
                "cd {$pathArg} && docker compose -f docker-compose.yml logs --no-color --tail=30 2>&1 | tail -n 40",
                45
            ));

            return trim("ps:\n{$ps}\nlogs:\n{$logs}");
        } catch (\Throwable $e) {
            return 'diagnostics failed: '.$e->getMessage();
        }
    }
}
