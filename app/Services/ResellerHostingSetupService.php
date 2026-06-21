<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ResellerProduct;
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

    public function requiresPrimaryDomainForCatalog(ResellerProduct $catalog, ?Product $adminProduct = null): bool
    {
        if ($catalog->usesDirectAdminPackage()) {
            return true;
        }

        return $adminProduct ? $this->requiresPrimaryDomain($adminProduct) : false;
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
        ?ResellerProduct $catalogProduct = null,
    ): array {
        $driver = $adminProduct->provisioning_driver_key;

        if ($catalogProduct?->usesDirectAdminPackage()
            || $driver === 'directadmin'
            || ($adminProduct->type === 'shared_hosting' && $adminProduct->direct_admin_package_id)) {
            return $this->buildDirectAdminContext($reseller, $customer, $adminProduct, $primaryDomain, $catalogProduct);
        }

        if ($driver === 'container') {
            if (! $adminProduct->container_template_id) {
                throw new \InvalidArgumentException(
                    'This container plan is not fully configured (missing deployment template). Contact your provider.'
                );
            }

            $adminProduct->loadMissing('containerTemplate');

            $meta = [];
            if (filled($primaryDomain)) {
                $meta['primary_domain'] = strtolower(trim((string) $primaryDomain));
            }

            if ($catalogProduct) {
                $meta['reseller_product_id'] = $catalogProduct->id;
            }

            $limits = $this->resolveContainerLimits($adminProduct, $catalogProduct);
            if ($limits !== []) {
                $meta['reseller_catalog_limits'] = $limits;
                if (isset($limits['disk_gb']) && $limits['disk_gb'] !== null) {
                    $meta['disk_limit_gb'] = $limits['disk_gb'];
                }
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
        ?ResellerProduct $catalogProduct = null,
    ): array {
        if (! $this->resellerDirectAdmin->hasDirectAdminBinding($reseller)) {
            throw new \InvalidArgumentException(
                'Your account is not linked to a DirectAdmin reseller. Ask your provider to link your DirectAdmin account from the admin reseller profile.'
            );
        }

        if (blank($primaryDomain)) {
            throw new \InvalidArgumentException(
                'Primary domain is required for shared hosting (PHP / WordPress) orders.'
            );
        }

        $resellerNode = $this->resellerDirectAdmin->resolveNode($reseller);
        if (! $resellerNode) {
            throw new \InvalidArgumentException(
                'No active DirectAdmin node is configured for your reseller account.'
            );
        }

        if ($catalogProduct?->usesDirectAdminPackage()) {
            if (! $this->resellerDirectAdmin->packageNameIsValid($reseller, (string) $catalogProduct->direct_admin_package_name)) {
                throw new \InvalidArgumentException(
                    'The linked DirectAdmin package is no longer available on your reseller account. Update the catalog item.'
                );
            }

            $prepared = $this->directAdminSetup->prepareForResellerPackage(
                $customer,
                (string) $primaryDomain,
                (string) $catalogProduct->direct_admin_package_name,
                $resellerNode,
            );

            $meta = array_merge($prepared['meta'], [
                'directadmin_reseller' => $reseller->directadmin_username,
                'reseller_product_id' => $catalogProduct->id,
            ]);

            return [
                'node_id' => $resellerNode->id,
                'service_meta' => $meta,
                'provisioning_driver_key' => 'directadmin',
            ];
        }

        $adminProduct->loadMissing('directAdminPackage');

        $prepared = $this->directAdminSetup->prepareForOrder(
            $adminProduct,
            $customer,
            (string) $primaryDomain,
        );

        $package = $adminProduct->directAdminPackage;
        if ($package && (int) $package->node_id !== (int) $resellerNode->id) {
            throw new \InvalidArgumentException(
                'This catalog plan is tied to a different DirectAdmin server than your reseller account. Link a DirectAdmin package from your server on this catalog item.'
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

    /**
     * @return array<string, float|int>
     */
    private function resolveContainerLimits(Product $adminProduct, ?ResellerProduct $catalogProduct): array
    {
        if ($catalogProduct?->hasContainerResourceLimits()) {
            $limits = $catalogProduct->containerResourceLimits();

            return array_filter([
                'cpu' => $limits['cpu'],
                'memory_mb' => $limits['memory_mb'],
                'disk_gb' => $limits['disk_gb'],
            ], fn ($value) => $value !== null);
        }

        $included = $adminProduct->getIncludedContainerLimits($adminProduct->containerTemplate);

        return array_filter([
            'cpu' => $included['cpu'],
            'memory_mb' => $included['memory_mb'],
            'disk_gb' => $included['disk_gb'] > 0 ? $included['disk_gb'] : null,
        ], fn ($value) => $value !== null);
    }
}
