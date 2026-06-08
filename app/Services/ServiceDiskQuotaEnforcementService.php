<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Monitors DirectAdmin shared hosting disk usage and suspends accounts that exceed quota.
 *
 * Applies to direct customers and reseller-managed customers. Disk limits come from the
 * DirectAdmin package assigned at provision time (catalog package binding).
 */
class ServiceDiskQuotaEnforcementService
{
    public function __construct(
        private ServiceOverdueEnforcementService $overdueEnforcement,
        private ProvisioningService $provisioning,
    ) {}

    public function isEnabled(): bool
    {
        return in_array(Setting::getValue('suspend_on_disk_overquota', 'true'), ['1', 'true', true], true);
    }

    public function thresholdPercent(): int
    {
        return max(1, min(200, (int) Setting::getValue('disk_overquota_threshold_percent', 100)));
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

        $thresholdMb = $limitMb * ($this->thresholdPercent() / 100);

        return $usedMb >= $thresholdMb;
    }

    /**
     * @return array{suspended: int, restored: int, skipped: int}
     */
    public function enforce(): array
    {
        if (! $this->isEnabled()) {
            return ['suspended' => 0, 'restored' => 0, 'skipped' => 0];
        }

        $suspended = 0;
        $restored = 0;
        $skipped = 0;

        foreach ($this->activeDirectAdminServicesQuery()->cursor() as $service) {
            try {
                $usage = $this->resolveDiskUsage($service);
                if ($usage === null) {
                    $skipped++;

                    continue;
                }

                if (! $usage['over_quota']) {
                    continue;
                }

                $this->suspendForDiskOverquota($service, $usage);
                $suspended++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::error('Disk quota enforcement failed for active service', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->diskSuspendedServicesQuery()->cursor() as $service) {
            try {
                if ($this->overdueEnforcement->shouldSuspendForOverdueInvoice($service)) {
                    continue;
                }

                $usage = $this->resolveDiskUsage($service);
                if ($usage === null || $usage['over_quota']) {
                    continue;
                }

                $this->restoreFromDiskOverquota($service);
                $restored++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::error('Disk quota restore failed for suspended service', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('suspended', 'restored', 'skipped');
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
