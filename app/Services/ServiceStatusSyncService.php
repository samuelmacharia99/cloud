<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\DirectAdminService;
use App\Support\ServiceLiveStatusResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ServiceStatusSyncService
{
    public function __construct(
        private ContainerDeploymentService $containerDeployment,
    ) {}

    /**
     * Probe infrastructure and persist live status on the service row.
     */
    public function sync(Service $service, bool $applyHealing = false): ServiceLiveStatusResult
    {
        $service->loadMissing(['product', 'node', 'containerDeployment.node']);

        $result = $this->probe($service);

        $mismatch = $this->detectMismatch($service, $result);

        $service->update([
            'live_status' => $result->status,
            'live_status_label' => $result->label,
            'live_status_source' => $result->source,
            'live_status_checked_at' => now(),
            'live_status_detail' => $result->detail,
            'live_status_mismatch' => $mismatch,
        ]);

        if ($applyHealing) {
            $this->applyHealing($service->fresh(['containerDeployment']), $result);
        }

        return $result;
    }

    /**
     * @return array{checked: int, mismatches: int, errors: int}
     */
    public function syncMany(iterable $services, bool $applyHealing = false): array
    {
        $summary = ['checked' => 0, 'mismatches' => 0, 'errors' => 0];

        foreach ($services as $service) {
            try {
                $result = $this->sync($service, $applyHealing);
                $summary['checked']++;

                if ($service->fresh()->live_status_mismatch) {
                    $summary['mismatches']++;
                }

                if (in_array($result->status, ['unknown', 'unavailable'], true)) {
                    $summary['errors']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::warning('Live status sync failed', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * Services that should be polled for live infrastructure status.
     */
    public function pollableQuery()
    {
        return Service::query()
            ->with(['product', 'node', 'containerDeployment.node'])
            ->whereHas('product', fn ($q) => $q->whereIn('type', ['shared_hosting', 'container_hosting']))
            ->whereNotIn('status', [
                ServiceStatus::Terminated,
                ServiceStatus::Cancelled,
            ]);
    }

    public function probe(Service $service): ServiceLiveStatusResult
    {
        if ($service->isContainerHosting()) {
            return $this->probeContainer($service);
        }

        if ($service->isSharedHosting()) {
            return $this->probeDirectAdmin($service);
        }

        return ServiceLiveStatusResult::unavailable('Live status not supported for this product type');
    }

    private function probeDirectAdmin(Service $service): ServiceLiveStatusResult
    {
        if (! $service->node_id || ! $service->node) {
            return ServiceLiveStatusResult::unavailable('No DirectAdmin node assigned', 'directadmin');
        }

        if (! $service->node->is_active) {
            return ServiceLiveStatusResult::unavailable('DirectAdmin node is inactive', 'directadmin');
        }

        $username = $service->service_meta['username'] ?? $service->getHostingCredentials()['username'] ?? null;
        if (blank($username)) {
            return new ServiceLiveStatusResult(
                status: 'pending',
                label: 'No DirectAdmin username configured',
                source: 'directadmin',
                detail: [],
            );
        }

        $da = new DirectAdminService($service->node);
        $status = $da->getAccountLiveStatus((string) $username);

        return new ServiceLiveStatusResult(
            status: $status['live_status'],
            label: $status['label'],
            source: 'directadmin',
            detail: $status['detail'],
        );
    }

    private function probeContainer(Service $service): ServiceLiveStatusResult
    {
        $deployment = $service->containerDeployment;

        if (! $deployment) {
            return new ServiceLiveStatusResult(
                status: 'pending',
                label: 'Not deployed yet',
                source: 'container',
                detail: [],
            );
        }

        if ($deployment->status === 'terminated') {
            return new ServiceLiveStatusResult(
                status: 'terminated',
                label: 'Container deployment terminated',
                source: 'container',
                detail: ['deployment_status' => $deployment->status],
            );
        }

        if (! $deployment->node) {
            return ServiceLiveStatusResult::unavailable('No container node assigned', 'container');
        }

        if ($deployment->status === 'deploying') {
            $docker = $this->safeContainerStatus($service);

            if (($docker['running'] ?? false) === true) {
                $this->updateDeploymentFromDocker($deployment, $docker);

                return new ServiceLiveStatusResult(
                    status: 'active',
                    label: 'Container running (deploying flag stale)',
                    source: 'container',
                    detail: $docker,
                );
            }

            return new ServiceLiveStatusResult(
                status: 'provisioning',
                label: 'Deployment in progress',
                source: 'container',
                detail: ['deployment_status' => $deployment->status],
            );
        }

        if ($deployment->status === 'failed') {
            return new ServiceLiveStatusResult(
                status: 'failed',
                label: 'Deployment failed',
                source: 'container',
                detail: ['deployment_status' => $deployment->status],
            );
        }

        $docker = $this->safeContainerStatus($service);

        if ($docker === null) {
            return ServiceLiveStatusResult::unknown('Could not reach container node', 'container');
        }

        $this->updateDeploymentFromDocker($deployment, $docker);

        if (($docker['running'] ?? false) === true) {
            return new ServiceLiveStatusResult(
                status: 'active',
                label: 'Container running',
                source: 'container',
                detail: $docker,
            );
        }

        if (($docker['state'] ?? '') === 'unknown' && ! ($docker['running'] ?? false)) {
            return new ServiceLiveStatusResult(
                status: 'terminated',
                label: 'Container not found on node',
                source: 'container',
                detail: $docker,
            );
        }

        return new ServiceLiveStatusResult(
            status: 'suspended',
            label: 'Container stopped ('.($docker['state'] ?? 'stopped').')',
            source: 'container',
            detail: $docker,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeContainerStatus(Service $service): ?array
    {
        try {
            return $this->containerDeployment->getStatus($service);
        } catch (\Throwable $e) {
            Log::warning('Container live status probe failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $docker
     */
    private function updateDeploymentFromDocker($deployment, array $docker): void
    {
        $deploymentStatus = ($docker['running'] ?? false)
            ? 'running'
            : (($docker['state'] ?? '') === 'unknown' ? $deployment->status : 'stopped');

        $deployment->update([
            'status' => $deploymentStatus,
            'last_status_check_at' => now(),
            'last_status_check_output' => json_encode($docker),
        ]);
    }

    public function detectMismatch(Service $service, ServiceLiveStatusResult $result): bool
    {
        $expected = $this->expectedPlatformStatusForLive($result->status);

        if ($expected === null) {
            return false;
        }

        $platform = $service->status instanceof ServiceStatus
            ? $service->status
            : ServiceStatus::tryFrom((string) $service->status);

        if ($platform === null) {
            return false;
        }

        if (in_array($platform, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true)) {
            return false;
        }

        return $platform !== $expected;
    }

    public function expectedPlatformStatusForLive(string $liveStatus): ?ServiceStatus
    {
        return match ($liveStatus) {
            'active' => ServiceStatus::Active,
            'suspended' => ServiceStatus::Suspended,
            'terminated' => ServiceStatus::Terminated,
            'provisioning' => ServiceStatus::Provisioning,
            'pending' => ServiceStatus::Pending,
            'failed' => ServiceStatus::Failed,
            default => null,
        };
    }

    private function applyHealing(Service $service, ServiceLiveStatusResult $result): void
    {
        if ($result->status === 'active' && in_array($service->status, [ServiceStatus::Provisioning, ServiceStatus::Failed], true)) {
            $service->update(['status' => ServiceStatus::Active]);

            return;
        }

        if ($result->status === 'provisioning' && $service->status === ServiceStatus::Pending) {
            $service->update(['status' => ServiceStatus::Provisioning]);
        }
    }

    /**
     * @return Collection<int, Service>
     */
    public function mismatchedServices(int $limit = 100): Collection
    {
        return Service::query()
            ->with(['user', 'product'])
            ->where('live_status_mismatch', true)
            ->latest('live_status_checked_at')
            ->limit($limit)
            ->get();
    }
}
