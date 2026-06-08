<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Provisioning\DirectAdminService;

/**
 * Builds a reseller-facing enforcement snapshot for a managed service.
 */
class ServiceEnforcementInsightService
{
    public function __construct(
        private ServiceOverdueEnforcementService $overdueEnforcement,
        private ServiceDiskQuotaEnforcementService $diskQuotaEnforcement,
    ) {}

    /**
     * @return array{
     *     suspension_reason: ?string,
     *     suspension_label: ?string,
     *     is_suspended: bool,
     *     billing_overdue: bool,
     *     disk: ?array{used_mb: float, limit_mb: ?float, percent: ?float, over_quota: bool},
     *     alerts: list<array{type: string, level: string, message: string}>
     * }
     */
    public function forService(Service $service): array
    {
        $service->loadMissing(['invoice', 'invoiceItems.invoice', 'node', 'product']);

        $reason = $service->service_meta[ResellerEnforcementService::META_SUSPENSION_REASON] ?? null;
        $alerts = [];

        $billingOverdue = $this->overdueEnforcement->shouldSuspendForOverdueInvoice($service);
        if ($billingOverdue && $service->status === ServiceStatus::Active) {
            $alerts[] = [
                'type' => 'billing',
                'level' => 'warning',
                'message' => 'This service has an unpaid or overdue invoice and may be suspended automatically.',
            ];
        }

        $disk = $this->resolveDiskInsight($service);
        if ($disk !== null) {
            if ($disk['over_quota']) {
                $alerts[] = [
                    'type' => 'disk',
                    'level' => 'danger',
                    'message' => "Disk usage ({$disk['used_mb']} MB) is at or above the package limit ({$disk['limit_mb']} MB).",
                ];
            } elseif ($disk['percent'] !== null && $disk['percent'] >= 85) {
                $alerts[] = [
                    'type' => 'disk',
                    'level' => 'warning',
                    'message' => "Disk usage is at {$disk['percent']}% of the allocated quota.",
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

        return [
            'suspension_reason' => $reason,
            'suspension_label' => $reason ? $this->reasonLabel($reason) : null,
            'is_suspended' => $service->status === ServiceStatus::Suspended,
            'billing_overdue' => $billingOverdue,
            'disk' => $disk,
            'alerts' => $alerts,
        ];
    }

    /**
     * @return list<array{type: string, level: string, message: string}>
     */
    public function alertsForCustomerServices(iterable $services): array
    {
        $alerts = [];

        foreach ($services as $service) {
            $insight = $this->forService($service);
            foreach ($insight['alerts'] as $alert) {
                $alerts[] = array_merge($alert, [
                    'service_id' => $service->id,
                    'service_name' => $service->name,
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
     * @return array{used_mb: float, limit_mb: ?float, percent: ?float, over_quota: bool}|null
     */
    private function resolveDiskInsight(Service $service): ?array
    {
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
        ];
    }
}
