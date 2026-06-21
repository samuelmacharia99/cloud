<?php

namespace App\Services;

use App\Models\Node;
use App\Models\ResellerProduct;
use App\Models\User;

class ResellerInfrastructureService
{
    public function __construct(
        private ResellerDirectAdminService $resellerDirectAdmin,
        private ResellerDiskUsageService $diskUsage,
        private ResellerScopeService $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildDashboard(User $reseller, bool $refresh = false): array
    {
        $reseller->loadMissing('resellerNode', 'resellerPackage');

        $availableNodes = $this->resellerDirectAdmin->availableNodes();
        $isConnected = $this->resellerDirectAdmin->hasDirectAdminBinding($reseller);
        $node = $isConnected ? $this->resellerDirectAdmin->resolveNode($reseller) : null;

        $packages = [];
        $packagesError = null;
        $hostedUserCount = null;
        $diskUsedMb = null;
        $platformServicesOnNode = 0;
        $daCatalogItems = 0;

        if ($isConnected && $node) {
            if ($refresh) {
                $verification = $this->resellerDirectAdmin->verifyBinding(
                    $node,
                    (string) $reseller->directadmin_username,
                );
                $packages = $verification['packages'];
                $packagesError = $verification['success'] ? null : $verification['message'];
                $hostedUserCount = $verification['hosted_user_count'];
                $diskUsedMb = $verification['disk_used_mb'];
            } else {
                $packageResult = $this->resellerDirectAdmin->listAssignablePackages($reseller);
                $packages = $packageResult['packages'];
                $packagesError = $packageResult['error'];
                $hostedUserCount = $this->resellerDirectAdmin->fetchHostedUserCount($reseller);
                $diskUsedMb = $this->resellerDirectAdmin->fetchTotalHostedDiskMb($reseller);
            }

            $platformServicesOnNode = $this->scope->managedServicesQuery($reseller)
                ->where('node_id', $node->id)
                ->where(function ($query) {
                    $query->where('provisioning_driver_key', 'directadmin')
                        ->orWhereHas('product', fn ($product) => $product
                            ->where('type', 'shared_hosting')
                            ->where('provisioning_driver_key', 'directadmin'));
                })
                ->count();

            $daCatalogItems = ResellerProduct::query()
                ->where('reseller_id', $reseller->id)
                ->where('type', 'shared_hosting')
                ->whereNotNull('direct_admin_package_name')
                ->where('direct_admin_package_name', '!=', '')
                ->count();
        }

        $diskPoolGb = $this->diskUsage->diskPoolGb($reseller);
        $diskUsedGb = $diskUsedMb !== null ? round($diskUsedMb / 1024, 2) : null;
        $diskPoolPercent = ($diskUsedGb !== null && $diskPoolGb > 0)
            ? min(100, round(($diskUsedGb / $diskPoolGb) * 100, 1))
            : null;

        $userCountBreakdown = $reseller->getResellerUserCountBreakdown();
        $maxUsers = (int) ($reseller->resellerPackage?->max_users ?? 0);
        $userLimitPercent = ($maxUsers > 0 && $userCountBreakdown['count'] > 0)
            ? min(100, round(($userCountBreakdown['count'] / $maxUsers) * 100, 1))
            : null;

        $settings = $reseller->settings ?? [];

        return [
            'is_connected' => $isConnected,
            'available_nodes' => $availableNodes,
            'node' => $node,
            'directadmin_username' => $reseller->directadmin_username,
            'connected_at' => $settings['directadmin_connected_at'] ?? null,
            'has_login_key' => filled($reseller->directadmin_login_key),
            'provisioning_ready' => $this->resellerDirectAdmin->canAutoProvision($reseller),
            'control_panel_url' => $this->controlPanelUrl($node),
            'packages' => $packages,
            'packages_error' => $packagesError,
            'hosted_user_count' => $hostedUserCount,
            'hosted_user_count_source' => $userCountBreakdown['source'],
            'max_users' => $maxUsers,
            'user_limit_percent' => $userLimitPercent,
            'disk_used_mb' => $diskUsedMb,
            'disk_used_gb' => $diskUsedGb,
            'disk_pool_gb' => $diskPoolGb,
            'disk_pool_percent' => $diskPoolPercent,
            'platform_services_on_node' => $platformServicesOnNode,
            'da_catalog_items' => $daCatalogItems,
            'api_reachable' => $isConnected && $hostedUserCount !== null,
        ];
    }

    private function controlPanelUrl(?Node $node): ?string
    {
        if (! $node) {
            return null;
        }

        $host = $node->hostname ?: $node->name;
        $port = $node->da_port ?: '2222';

        return "https://{$host}:{$port}";
    }
}
