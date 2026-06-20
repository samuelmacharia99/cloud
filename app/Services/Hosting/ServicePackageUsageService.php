<?php

namespace App\Services\Hosting;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tracks shared-hosting package usage (disk, bandwidth, databases) from DirectAdmin.
 */
class ServicePackageUsageService
{
    public const META_KEY = 'package_usage';

    public const METRIC_DISK = 'disk';

    public const METRIC_BANDWIDTH = 'bandwidth';

    public const METRIC_DATABASE = 'database';

    /** @var array<string, mixed>|null */
    private ?array $lastDashboard = null;

    public function warningThresholdPercent(): int
    {
        return max(50, min(100, (int) Setting::getValue('hosting_package_usage_warning_percent', 90)));
    }

    public function clearThresholdPercent(): int
    {
        return max(40, min(95, (int) Setting::getValue('hosting_package_usage_clear_percent', 85)));
    }

    /**
     * @return Builder<Service>
     */
    public function monitorableServicesQuery(): Builder
    {
        return Service::query()
            ->with(['node', 'product.directAdminPackage', 'user'])
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
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
     * Fetch live DirectAdmin usage and persist package + account snapshots on the service.
     *
     * @return array<string, mixed>|null
     */
    public function syncFromDirectAdmin(Service $service): ?array
    {
        $service->loadMissing(['product.directAdminPackage', 'node']);

        $snapshot = $this->fetchLiveUsage($service);
        if ($snapshot === null) {
            return null;
        }

        $this->persistSnapshot($service, $snapshot, $this->lastDashboard());

        return $snapshot;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchLiveUsage(Service $service): ?array
    {
        if (! $service->isSharedHosting() || ! $service->node) {
            return null;
        }

        $username = $service->external_reference ?? ($service->service_meta['username'] ?? null);
        if (blank($username)) {
            return null;
        }

        $api = DirectAdminCustomerPanelApi::forServiceNode($service->node);
        if (! $api->isAvailable()) {
            return null;
        }

        $domain = $service->service_meta['domain'] ?? null;
        $response = $api->getDashboard((string) $username, is_string($domain) ? $domain : null);
        if (! $response['success']) {
            return null;
        }

        $data = $response['data'];
        $package = $service->product?->directAdminPackage;

        $diskLimitMb = isset($data['disk']['limit_mb']) ? (float) $data['disk']['limit_mb'] : null;
        if (($diskLimitMb === null || $diskLimitMb <= 0) && $package && (float) $package->disk_quota > 0) {
            $diskLimitMb = (float) $package->disk_quota * 1024;
        }

        $bandwidthLimitMb = isset($data['bandwidth']['limit_mb']) ? (float) $data['bandwidth']['limit_mb'] : null;
        if (($bandwidthLimitMb === null || $bandwidthLimitMb <= 0) && $package && (float) $package->bandwidth_quota > 0) {
            $bandwidthLimitMb = (float) $package->bandwidth_quota * 1024;
        }

        $databaseLimit = (int) ($data['counts']['database_limit'] ?? 0);
        if ($databaseLimit <= 0 && $package) {
            $databaseLimit = (int) $package->num_databases;
        }

        $snapshot = [
            'checked_at' => now()->toIso8601String(),
            self::METRIC_DISK => $this->metricFromMegabytes(
                (float) ($data['disk']['used_mb'] ?? 0),
                $diskLimitMb,
            ),
            self::METRIC_BANDWIDTH => $this->metricFromMegabytes(
                (float) ($data['bandwidth']['used_mb'] ?? 0),
                $bandwidthLimitMb,
            ),
            self::METRIC_DATABASE => $this->metricFromCount(
                (int) ($data['counts']['database'] ?? 0),
                $databaseLimit,
            ),
        ];

        $this->lastDashboard = $data;

        return $snapshot;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastDashboard(): ?array
    {
        return $this->lastDashboard;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function metricsNeedingUpgrade(array $snapshot, ?int $threshold = null): array
    {
        $threshold ??= $this->warningThresholdPercent();
        $atRisk = [];

        foreach ([self::METRIC_DISK, self::METRIC_BANDWIDTH, self::METRIC_DATABASE] as $metric) {
            $entry = $snapshot[$metric] ?? null;
            if (! is_array($entry) || ($entry['unlimited'] ?? false)) {
                continue;
            }

            if (($entry['percent'] ?? 0) >= $threshold) {
                $atRisk[$metric] = $entry;
            }
        }

        return $atRisk;
    }

    /**
     * @param  array<string, array<string, mixed>>  $metricsAtRisk
     */
    public function primaryMetric(array $metricsAtRisk): ?string
    {
        if ($metricsAtRisk === []) {
            return null;
        }

        $primary = null;
        $highest = -1.0;

        foreach ($metricsAtRisk as $metric => $entry) {
            $percent = (float) ($entry['percent'] ?? 0);
            if ($percent > $highest) {
                $highest = $percent;
                $primary = $metric;
            }
        }

        return $primary;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $dashboard
     */
    public function persistSnapshot(Service $service, array $snapshot, ?array $dashboard = null): void
    {
        $atRisk = $this->metricsNeedingUpgrade($snapshot);
        $meta = $service->service_meta ?? [];
        $existing = $meta[self::META_KEY] ?? [];

        $meta[self::META_KEY] = array_merge($snapshot, [
            'needs_upgrade' => $atRisk !== [],
            'primary_metric' => $this->primaryMetric($atRisk),
            'warning_sent_at' => $existing['warning_sent_at'] ?? null,
        ]);

        if ($dashboard !== null) {
            $meta['directadmin_account'] = [
                'checked_at' => $snapshot['checked_at'] ?? now()->toIso8601String(),
                'username' => $dashboard['username'] ?? ($meta['username'] ?? null),
                'domain' => $dashboard['domain'] ?? ($meta['domain'] ?? null),
                'package' => $dashboard['package'] ?? ($meta['package_name'] ?? null),
                'application_stack' => $meta['application_stack'] ?? null,
                'database_template' => $meta['database_template_name'] ?? null,
                'databases' => array_values($dashboard['databases'] ?? []),
                'counts' => $dashboard['counts'] ?? [],
                'disk_used_mb' => $dashboard['disk']['used_mb'] ?? null,
                'bandwidth_used_mb' => $dashboard['bandwidth']['used_mb'] ?? null,
            ];
        }

        $service->update(['service_meta' => $meta]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshotFromMeta(Service $service): ?array
    {
        $snapshot = $service->service_meta[self::META_KEY] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @return list<array{service: Service, snapshot: array<string, mixed>, metrics_at_risk: array<string, array<string, mixed>>, primary_metric: ?string}>
     */
    public function upgradeWarningsForUser(User $user): array
    {
        $warnings = [];

        $services = $user->services()
            ->with(['product.directAdminPackage', 'node'])
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->get()
            ->filter(fn (Service $service) => $service->isSharedHosting());

        foreach ($services as $service) {
            $snapshot = $this->snapshotFromMeta($service);
            if ($snapshot === null || empty($snapshot['needs_upgrade'])) {
                continue;
            }

            $atRisk = $this->metricsNeedingUpgrade($snapshot);
            if ($atRisk === []) {
                continue;
            }

            $warnings[] = [
                'service' => $service,
                'service_name' => $service->name,
                'snapshot' => $snapshot,
                'metrics_at_risk' => $atRisk,
                'primary_metric' => $this->primaryMetric($atRisk),
            ];
        }

        return $warnings;
    }

    public function metricLabel(string $metric): string
    {
        return match ($metric) {
            self::METRIC_BANDWIDTH => 'Bandwidth',
            self::METRIC_DATABASE => 'Databases',
            default => 'Storage',
        };
    }

    /**
     * @return array{used: float, limit: ?float, percent: ?float, unlimited: bool, unit: string}
     */
    public function metricFromMegabytes(float $used, ?float $limitMb): array
    {
        if ($limitMb === null || $limitMb <= 0) {
            return [
                'used' => round($used, 1),
                'limit' => null,
                'percent' => null,
                'unlimited' => true,
                'unit' => 'MB',
            ];
        }

        return [
            'used' => round($used, 1),
            'limit' => round($limitMb, 1),
            'percent' => round(($used / $limitMb) * 100, 1),
            'unlimited' => false,
            'unit' => 'MB',
        ];
    }

    /**
     * @return array{used: int, limit: ?int, percent: ?float, unlimited: bool, unit: string}
     */
    public function metricFromCount(int $used, int $limit): array
    {
        if ($limit < 0 || $limit === 0) {
            return [
                'used' => $used,
                'limit' => null,
                'percent' => null,
                'unlimited' => true,
                'unit' => 'count',
            ];
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'percent' => round(($used / $limit) * 100, 1),
            'unlimited' => false,
            'unit' => 'count',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function allMetricsBelowClearThreshold(array $snapshot): bool
    {
        $clear = $this->clearThresholdPercent();

        foreach ([self::METRIC_DISK, self::METRIC_BANDWIDTH, self::METRIC_DATABASE] as $metric) {
            $entry = $snapshot[$metric] ?? null;
            if (! is_array($entry) || ($entry['unlimited'] ?? false)) {
                continue;
            }

            if (($entry['percent'] ?? 0) >= $clear) {
                return false;
            }
        }

        return true;
    }
}
