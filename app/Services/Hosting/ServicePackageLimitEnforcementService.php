<?php

namespace App\Services\Hosting;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ResellerEnforcementService;
use App\Services\ServiceOverdueEnforcementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Suspends shared hosting services that exceed package limits (disk, bandwidth, database count)
 * and restores them when usage returns below the configured threshold.
 */
class ServicePackageLimitEnforcementService
{
    public function __construct(
        private ServicePackageUsageService $usage,
        private ServiceOverdueEnforcementService $overdueEnforcement,
        private ProvisioningService $provisioning,
    ) {}

    public function isEnabled(): bool
    {
        $packageSetting = Setting::getValue('suspend_on_package_overquota');
        if ($packageSetting !== null) {
            return in_array($packageSetting, ['1', 'true', true], true);
        }

        return in_array(Setting::getValue('suspend_on_disk_overquota', 'true'), ['1', 'true', true], true);
    }

    public function thresholdPercent(): int
    {
        $threshold = Setting::getValue('package_overquota_threshold_percent');

        if ($threshold === null) {
            $threshold = Setting::getValue('disk_overquota_threshold_percent', 100);
        }

        return max(1, min(200, (int) $threshold));
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
        $threshold = $this->thresholdPercent();

        foreach ($this->activeServicesQuery()->cursor() as $service) {
            try {
                if ($this->overdueEnforcement->shouldSuspendForOverdueInvoice($service)) {
                    continue;
                }

                $snapshot = $this->usage->fetchLiveUsage($service);
                if ($snapshot === null) {
                    $skipped++;

                    continue;
                }

                $this->usage->persistSnapshot($service, $snapshot, $this->usage->lastDashboard());

                $overLimit = $this->metricsOverLimit($snapshot, $threshold);
                if ($overLimit === []) {
                    continue;
                }

                $this->suspendForPackageOverlimit($service, $overLimit, $snapshot);
                $suspended++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::error('Package limit enforcement failed for active service', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->packageSuspendedServicesQuery()->cursor() as $service) {
            try {
                if ($this->overdueEnforcement->shouldSuspendForOverdueInvoice($service)) {
                    continue;
                }

                $snapshot = $this->usage->fetchLiveUsage($service) ?? $this->usage->snapshotFromMeta($service);
                if ($snapshot === null) {
                    $skipped++;

                    continue;
                }

                if ($this->metricsOverLimit($snapshot, $threshold) !== []) {
                    continue;
                }

                $this->restoreFromPackageOverlimit($service);
                $restored++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::error('Package limit restore failed for suspended service', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('suspended', 'restored', 'skipped');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function metricsOverLimit(array $snapshot, ?int $threshold = null): array
    {
        $threshold ??= $this->thresholdPercent();
        $overLimit = [];

        foreach ([
            ServicePackageUsageService::METRIC_DISK,
            ServicePackageUsageService::METRIC_BANDWIDTH,
            ServicePackageUsageService::METRIC_DATABASE,
        ] as $metric) {
            $entry = $snapshot[$metric] ?? null;
            if (! is_array($entry) || ($entry['unlimited'] ?? false)) {
                continue;
            }

            if ($this->isMetricOverLimit($entry, $threshold)) {
                $overLimit[$metric] = $entry;
            }
        }

        return $overLimit;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function isMetricOverLimit(array $entry, int $thresholdPercent): bool
    {
        if ($entry['unlimited'] ?? false) {
            return false;
        }

        $limit = $entry['limit'] ?? null;
        $used = $entry['used'] ?? null;

        if ($limit === null || $limit <= 0 || $used === null) {
            return false;
        }

        // DirectAdmin database slots: suspend only when count exceeds the package allowance.
        // At capacity (e.g. 5/5) is allowed; disk and bandwidth still suspend at/over quota.
        if (($entry['unit'] ?? '') === 'count') {
            return (float) $used > (float) $limit;
        }

        if ($thresholdPercent >= 100) {
            return (float) $used >= (float) $limit;
        }

        return ($entry['percent'] ?? 0) >= $thresholdPercent;
    }

    /**
     * @return Builder<Service>
     */
    public function activeServicesQuery(): Builder
    {
        return $this->usage->monitorableServicesQuery()
            ->where('status', ServiceStatus::Active);
    }

    /**
     * @return Builder<Service>
     */
    public function packageSuspendedServicesQuery(): Builder
    {
        return Service::query()
            ->with(['node', 'product', 'user'])
            ->where('status', ServiceStatus::Suspended)
            ->where(function (Builder $query) {
                $query->where(
                    'service_meta->'.ResellerEnforcementService::META_SUSPENSION_REASON,
                    ResellerEnforcementService::REASON_PACKAGE_OVERQUOTA,
                )->orWhere(
                    'service_meta->'.ResellerEnforcementService::META_SUSPENSION_REASON,
                    ResellerEnforcementService::REASON_DISK_OVERQUOTA,
                );
            })
            ->whereNotNull('node_id');
    }

    /**
     * @param  array<string, array<string, mixed>>  $overLimit
     * @param  array<string, mixed>  $snapshot
     */
    protected function suspendForPackageOverlimit(Service $service, array $overLimit, array $snapshot): void
    {
        if ($service->status !== ServiceStatus::Active) {
            return;
        }

        $meta = $service->service_meta ?? [];
        $meta[ResellerEnforcementService::META_SUSPENSION_REASON] = ResellerEnforcementService::REASON_PACKAGE_OVERQUOTA;
        $meta['package_overlimit_metrics'] = array_keys($overLimit);
        $meta['package_overlimit_at'] = now()->toIso8601String();
        $meta[ServicePackageUsageService::META_KEY] = array_merge(
            $meta[ServicePackageUsageService::META_KEY] ?? [],
            $snapshot,
            [
                'needs_upgrade' => true,
                'primary_metric' => $this->usage->primaryMetric($overLimit),
            ],
        );
        $service->update(['service_meta' => $meta]);

        $this->provisioning->suspend($service->fresh());

        Log::warning('Service suspended for package limit exceeded', [
            'service_id' => $service->id,
            'user_id' => $service->user_id,
            'reseller_id' => $service->reseller_id,
            'metrics' => array_keys($overLimit),
        ]);
    }

    public function tryRestoreFromPackageOverlimit(Service $service): bool
    {
        $reason = $service->service_meta[ResellerEnforcementService::META_SUSPENSION_REASON] ?? null;

        if (! in_array($reason, [
            ResellerEnforcementService::REASON_PACKAGE_OVERQUOTA,
            ResellerEnforcementService::REASON_DISK_OVERQUOTA,
        ], true)) {
            return false;
        }

        if ($service->status !== ServiceStatus::Suspended) {
            return false;
        }

        $snapshot = $this->usage->fetchLiveUsage($service) ?? $this->usage->snapshotFromMeta($service);
        if ($snapshot === null || $this->metricsOverLimit($snapshot) !== []) {
            return false;
        }

        $this->restoreFromPackageOverlimit($service);

        return true;
    }

    protected function restoreFromPackageOverlimit(Service $service): void
    {
        $meta = $service->service_meta ?? [];
        unset(
            $meta[ResellerEnforcementService::META_SUSPENSION_REASON],
            $meta['package_overlimit_metrics'],
            $meta['package_overlimit_at'],
            $meta['disk_used_mb'],
            $meta['disk_limit_mb'],
            $meta['disk_suspended_at'],
        );

        if (isset($meta[ServicePackageUsageService::META_KEY]) && is_array($meta[ServicePackageUsageService::META_KEY])) {
            $meta[ServicePackageUsageService::META_KEY]['needs_upgrade'] = false;
            unset($meta[ServicePackageUsageService::META_KEY]['warning_sent_at']);
        }

        $service->update(['service_meta' => $meta ?: null]);

        $this->provisioning->unsuspend($service->fresh());

        Log::info('Service unsuspended after package usage returned below limit', [
            'service_id' => $service->id,
            'user_id' => $service->user_id,
            'reseller_id' => $service->reseller_id,
        ]);
    }
}
