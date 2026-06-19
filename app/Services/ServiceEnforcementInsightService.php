<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Hosting\ServicePackageUsageService;
use App\Services\Provisioning\DirectAdminService;

/**
 * Builds enforcement and package usage snapshots for reseller and customer portals.
 */
class ServiceEnforcementInsightService
{
    public function __construct(
        private ServiceOverdueEnforcementService $overdueEnforcement,
        private ServiceDiskQuotaEnforcementService $diskQuotaEnforcement,
        private ServicePackageUsageService $packageUsage,
    ) {}

    /**
     * @return array{
     *     suspension_reason: ?string,
     *     suspension_label: ?string,
     *     is_suspended: bool,
     *     billing_overdue: bool,
     *     disk: ?array{used_mb: float, limit_mb: ?float, percent: ?float, over_quota: bool},
     *     bandwidth: ?array{used: float, limit: ?float, percent: ?float, unlimited: bool},
     *     database: ?array{used: int, limit: ?int, percent: ?float, unlimited: bool},
     *     needs_upgrade: bool,
     *     primary_metric: ?string,
     *     upgrade_url: ?string,
     *     alerts: list<array{type: string, level: string, message: string}>
     * }
     */
    public function forService(Service $service): array
    {
        $service->loadMissing(['invoice', 'invoiceItems.invoice', 'node', 'product.directAdminPackage']);

        $reason = $service->service_meta[ResellerEnforcementService::META_SUSPENSION_REASON] ?? null;
        $alerts = [];
        $warningThreshold = $this->packageUsage->warningThresholdPercent();

        $billingOverdue = $this->overdueEnforcement->shouldSuspendForOverdueInvoice($service);
        if ($billingOverdue && $service->status === ServiceStatus::Active) {
            $alerts[] = [
                'type' => 'billing',
                'level' => 'warning',
                'message' => 'This service has an unpaid or overdue invoice and may be suspended automatically.',
            ];
        }

        $snapshot = $this->packageUsage->snapshotFromMeta($service);
        $disk = $this->resolveDiskInsight($service, $snapshot);
        $bandwidth = $this->metricInsight($snapshot, ServicePackageUsageService::METRIC_BANDWIDTH);
        $database = $this->metricInsight($snapshot, ServicePackageUsageService::METRIC_DATABASE);

        foreach ([
            ServicePackageUsageService::METRIC_DISK => $disk,
            ServicePackageUsageService::METRIC_BANDWIDTH => $bandwidth,
            ServicePackageUsageService::METRIC_DATABASE => $database,
        ] as $metric => $entry) {
            if ($entry === null || ($entry['unlimited'] ?? false)) {
                continue;
            }

            $percent = $entry['percent'] ?? null;
            if ($percent === null) {
                continue;
            }

            $label = $this->packageUsage->metricLabel($metric);

            if (($metric === ServicePackageUsageService::METRIC_DISK && ($entry['over_quota'] ?? false))
                || $percent >= 100) {
                $alerts[] = [
                    'type' => $metric,
                    'level' => 'danger',
                    'message' => "{$label} usage is at {$percent}% of your plan limit.",
                ];
            } elseif ($percent >= $warningThreshold) {
                $alerts[] = [
                    'type' => $metric,
                    'level' => 'warning',
                    'message' => "{$label} usage is at {$percent}% of your plan limit. Upgrade your plan to avoid interruption.",
                ];
            }
        }

        if ($service->status === ServiceStatus::Suspended && $reason) {
            $alerts[] = [
                'type' => 'suspension',
                'level' => 'danger',
                'message' => 'Suspended: '.$this->reasonLabel($reason),
            ];
        }

        $needsUpgrade = $snapshot !== null && ! empty($snapshot['needs_upgrade']);

        return [
            'suspension_reason' => $reason,
            'suspension_label' => $reason ? $this->reasonLabel($reason) : null,
            'is_suspended' => $service->status === ServiceStatus::Suspended,
            'billing_overdue' => $billingOverdue,
            'disk' => $disk,
            'bandwidth' => $bandwidth,
            'database' => $database,
            'needs_upgrade' => $needsUpgrade,
            'primary_metric' => $snapshot['primary_metric'] ?? null,
            'upgrade_url' => $service->isSharedHosting()
                ? route('customer.services.upgrade', $service)
                : null,
            'alerts' => $alerts,
        ];
    }

    /**
     * @return list<array{type: string, level: string, message: string, service_id: int, service_name: string, upgrade_url: ?string}>
     */
    public function alertsForCustomerServices(iterable $services): array
    {
        $alerts = [];

        foreach ($services as $service) {
            $insight = $this->forService($service);
            foreach ($insight['alerts'] as $alert) {
                if (($alert['level'] ?? '') !== 'warning' || ! in_array($alert['type'] ?? '', [
                    ServicePackageUsageService::METRIC_DISK,
                    ServicePackageUsageService::METRIC_BANDWIDTH,
                    ServicePackageUsageService::METRIC_DATABASE,
                ], true)) {
                    continue;
                }

                $alerts[] = array_merge($alert, [
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'upgrade_url' => $insight['upgrade_url'],
                ]);
            }
        }

        return $alerts;
    }

    public function reasonLabel(string $reason): string
    {
        return match ($reason) {
            ResellerEnforcementService::REASON_INVOICE_OVERDUE => 'Unpaid or overdue invoice',
            ResellerEnforcementService::REASON_DISK_OVERQUOTA => 'Disk quota exceeded',
            ResellerEnforcementService::REASON_RESELLER_OVERDUE => 'Reseller package billing lapsed',
            ResellerEnforcementService::REASON_PACKAGE_LIMIT => 'Exceeded package service slot limit',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{used_mb: float, limit_mb: ?float, percent: ?float, over_quota: bool, unlimited: bool}|null
     */
    private function resolveDiskInsight(Service $service, ?array $snapshot): ?array
    {
        $cached = $this->metricInsight($snapshot, ServicePackageUsageService::METRIC_DISK);
        if ($cached !== null) {
            return [
                'used_mb' => (float) ($cached['used'] ?? 0),
                'limit_mb' => isset($cached['limit']) ? (float) $cached['limit'] : null,
                'percent' => $cached['percent'] ?? null,
                'over_quota' => $this->diskQuotaEnforcement->isOverQuota(
                    (float) ($cached['used'] ?? 0),
                    isset($cached['limit']) ? (float) $cached['limit'] : null,
                ),
                'unlimited' => $cached['unlimited'] ?? false,
            ];
        }

        if (! $service->isSharedHosting() || ! $service->node) {
            $meta = $service->service_meta ?? [];
            if (isset($meta['disk_used_mb'])) {
                $used = (float) $meta['disk_used_mb'];
                $limit = isset($meta['disk_limit_mb']) ? (float) $meta['disk_limit_mb'] : null;

                return [
                    'used_mb' => $used,
                    'limit_mb' => $limit,
                    'percent' => $limit && $limit > 0 ? round(($used / $limit) * 100, 1) : null,
                    'over_quota' => $limit && $limit > 0 && $used >= $limit,
                    'unlimited' => ! $limit || $limit <= 0,
                ];
            }

            return null;
        }

        $username = $service->external_reference ?? ($service->service_meta['username'] ?? null);
        if (blank($username)) {
            return null;
        }

        $usage = (new DirectAdminService($service->node))->getAccountDiskUsage((string) $username);
        if ($usage === null) {
            return null;
        }

        $limit = $usage['limit_mb'];
        $used = $usage['used_mb'];

        return [
            'used_mb' => $used,
            'limit_mb' => $limit,
            'percent' => $limit && $limit > 0 ? round(($used / $limit) * 100, 1) : null,
            'over_quota' => $this->diskQuotaEnforcement->isOverQuota($used, $limit),
            'unlimited' => ! $limit || $limit <= 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{used: float|int, limit: float|int|null, percent: ?float, unlimited: bool}|null
     */
    private function metricInsight(?array $snapshot, string $metric): ?array
    {
        if ($snapshot === null || ! isset($snapshot[$metric]) || ! is_array($snapshot[$metric])) {
            return null;
        }

        return $snapshot[$metric];
    }
}
