<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Hosting\ServicePackageLimitEnforcementService;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Monitors DirectAdmin shared hosting usage and suspends accounts that exceed package limits.
 *
 * Disk-specific helpers remain here for legacy callers; full package enforcement is delegated
 * to ServicePackageLimitEnforcementService (disk, bandwidth, databases).
 */
class ServiceDiskQuotaEnforcementService
{
    public function __construct(
        private ServiceOverdueEnforcementService $overdueEnforcement,
        private ProvisioningService $provisioning,
        private ServicePackageLimitEnforcementService $packageLimits,
    ) {}

    public function isEnabled(): bool
    {
        return $this->packageLimits->isEnabled();
    }

    public function thresholdPercent(): int
    {
        return $this->packageLimits->thresholdPercent();
    }

    /**
     * @return Builder<Service>
     */
    public function activeDirectAdminServicesQuery(): Builder
    {
        return Service::query()
            ->with(['node', 'product', 'user'])
            ->where('status', ServiceStatus::Active)
            ->whereNotNull('node_id')
            ->where(function (Builder $query) {
                $query->where('provisioning_driver_key', 'directadmin')
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where('provisioning_driver_key', 'directadmin')
                        ->where('type', 'shared_hosting'));
            })
            ->where(function (Builder $query) {
                $query->whereNotNull('external_reference')
                    ->orWhereNotNull('service_meta->username');
            });
    }

    /**
     * @return Builder<Service>
     */
    public function diskSuspendedServicesQuery(): Builder
    {
        return Service::query()
            ->with(['node', 'product', 'user'])
            ->where('status', ServiceStatus::Suspended)
            ->where('service_meta->'.ResellerEnforcementService::META_SUSPENSION_REASON, ResellerEnforcementService::REASON_DISK_OVERQUOTA)
            ->whereNotNull('node_id');
    }

    /**
     * @return array{used_mb: float, limit_mb: float, over_quota: bool}|null
     */
    public function resolveDiskUsage(Service $service): ?array
    {
        $username = $service->external_reference ?? ($service->service_meta['username'] ?? null);
        if (blank($username) || ! $service->node) {
            return null;
        }

        $directAdmin = new DirectAdminService($service->node);
        if (! $directAdmin->isConfigured()) {
            return null;
        }

        $usage = $directAdmin->getAccountDiskUsage((string) $username);
        if ($usage === null) {
            return null;
        }

        $usage['over_quota'] = $this->isOverQuota($usage['used_mb'], $usage['limit_mb']);

        return $usage;
    }

    public function isOverQuota(float $usedMb, ?float $limitMb): bool
    {
        if ($limitMb === null || $limitMb <= 0) {
            return false;
        }

        return $this->packageLimits->isMetricOverLimit([
            'used' => $usedMb,
            'limit' => $limitMb,
            'percent' => round(($usedMb / $limitMb) * 100, 1),
            'unlimited' => false,
            'unit' => 'MB',
        ], $this->thresholdPercent());
    }

    /**
     * @return array{suspended: int, restored: int, skipped: int}
     */
    public function enforce(): array
    {
        return $this->packageLimits->enforce();
    }

    /**
     * @param  array{used_mb: float, limit_mb: float}  $usage
     */
    protected function suspendForDiskOverquota(Service $service, array $usage): void
    {
        if ($service->status !== ServiceStatus::Active) {
            return;
        }

        $meta = $service->service_meta ?? [];
        $meta[ResellerEnforcementService::META_SUSPENSION_REASON] = ResellerEnforcementService::REASON_DISK_OVERQUOTA;
        $meta['disk_used_mb'] = $usage['used_mb'];
        $meta['disk_limit_mb'] = $usage['limit_mb'];
        $meta['disk_suspended_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);

        $this->provisioning->suspend($service->fresh());

        Log::warning('Service suspended for disk overquota', [
            'service_id' => $service->id,
            'user_id' => $service->user_id,
            'reseller_id' => $service->reseller_id,
            'used_mb' => $usage['used_mb'],
            'limit_mb' => $usage['limit_mb'],
        ]);
    }

    protected function restoreFromDiskOverquota(Service $service): void
    {
        $meta = $service->service_meta ?? [];
        unset(
            $meta[ResellerEnforcementService::META_SUSPENSION_REASON],
            $meta['disk_used_mb'],
            $meta['disk_limit_mb'],
            $meta['disk_suspended_at'],
        );
        $service->update(['service_meta' => $meta ?: null]);

        $this->provisioning->unsuspend($service->fresh());

        Log::info('Service unsuspended after disk usage returned below quota', [
            'service_id' => $service->id,
            'user_id' => $service->user_id,
            'reseller_id' => $service->reseller_id,
        ]);
    }
}
