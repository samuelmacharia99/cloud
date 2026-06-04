<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Services\Provisioning\DirectAdminSetupService;

class ResellerHostingSetupService
{
    public function __construct(
        private DirectAdminSetupService $directAdminSetup,
        private ResellerDirectAdminService $resellerDirectAdmin,
    ) {}

    public function requiresPrimaryDomain(Product $product): bool
    {
        if ($product->provisioning_driver_key === 'directadmin') {
            return true;
        }

        return $product->type === 'shared_hosting' && $product->direct_admin_package_id;
    }

    /**
     * Build node_id, service_meta, and driver for a reseller customer hosting order.
     *
     * @return array{node_id?: int|null, service_meta: array<string, mixed>, provisioning_driver_key: ?string}
     */
    public function buildProvisioningContext(
        User $reseller,
        User $customer,
        Product $adminProduct,
        ?string $primaryDomain = null,
    ): array {
        $driver = $adminProduct->provisioning_driver_key;

        if ($driver === 'directadmin' || ($adminProduct->type === 'shared_hosting' && $adminProduct->direct_admin_package_id)) {
            return $this->buildDirectAdminContext($reseller, $customer, $adminProduct, $primaryDomain);
        }

        if ($driver === 'container') {
            if (! $adminProduct->container_template_id) {
                throw new \InvalidArgumentException(
                    'This container plan is not fully configured (missing deployment template). Contact your provider.'
                );
            }

            $meta = [];
            if (filled($primaryDomain)) {
                $meta['primary_domain'] = strtolower(trim((string) $primaryDomain));
            }

            return [
                'service_meta' => $meta,
                'provisioning_driver_key' => 'container',
            ];
        }

        return [
            'service_meta' => [],
            'provisioning_driver_key' => $driver,
        ];
    }

    /**
     * @return array{node_id: int, service_meta: array<string, mixed>, provisioning_driver_key: string}
     */
    private function buildDirectAdminContext(
        User $reseller,
        User $customer,
        Product $adminProduct,
        ?string $primaryDomain,
    ): array {
        if (! $this->resellerDirectAdmin->hasDirectAdminBinding($reseller)) {
            throw new \InvalidArgumentException(
                'Your account is not linked to a DirectAdmin reseller. Ask your provider to set your DirectAdmin username and node under reseller settings.'
            );
        }

        if (blank($primaryDomain)) {
            throw new \InvalidArgumentException(
                'Primary domain is required for shared hosting (PHP / WordPress) orders.'
            );
        }

        $adminProduct->loadMissing('directAdminPackage');

        $prepared = $this->directAdminSetup->prepareForOrder(
            $adminProduct,
            $customer,
            (string) $primaryDomain,
        );

        $resellerNode = $this->resellerDirectAdmin->resolveNode($reseller);
        if (! $resellerNode) {
            throw new \InvalidArgumentException(
                'No active DirectAdmin node is configured for your reseller account.'
            );
        }

        $package = $adminProduct->directAdminPackage;
        if ($package && (int) $package->node_id !== (int) $resellerNode->id) {
            throw new \InvalidArgumentException(
                'This catalog plan is tied to a different DirectAdmin server than your reseller account. Choose a plan on your server or contact support.'
            );
        }

        $meta = array_merge($prepared['meta'], [
            'node_id' => $resellerNode->id,
            'node_name' => $resellerNode->name,
            'directadmin_reseller' => $reseller->directadmin_username,
        ]);

        return [
            'node_id' => $resellerNode->id,
            'service_meta' => $meta,
            'provisioning_driver_key' => 'directadmin',
        ];
    }
}
