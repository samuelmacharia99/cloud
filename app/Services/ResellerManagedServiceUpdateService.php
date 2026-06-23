<?php

namespace App\Services;

use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\Provisioning\DirectAdminSetupService;
use Illuminate\Support\Facades\Log;

class ResellerManagedServiceUpdateService
{
    public function __construct(
        private ResellerScopeService $scope,
        private ResellerProvisionProductResolver $productResolver,
        private ResellerDirectAdminService $resellerDirectAdmin,
        private DirectAdminSetupService $directAdminSetup,
        private CustomerHostingUpgradeService $hostingUpgrade,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{success_message: string, warning_message: ?string}
     */
    public function update(User $reseller, Service $service, array $validated): array
    {
        $this->assertManaged($reseller, $service);

        $listing = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->with('adminProduct.directAdminPackage')
            ->findOrFail($validated['reseller_product_id']);

        $targetProduct = $this->productResolver->resolve($listing);
        if (! $targetProduct) {
            throw new \InvalidArgumentException('Selected catalog item is not available for provisioning.');
        }

        $service->loadMissing('product', 'node', 'user');

        $serviceType = $service->product?->type;
        $listingType = $listing->type ?? $targetProduct->type;
        if ($serviceType && $listingType && $serviceType !== $listingType) {
            throw new \InvalidArgumentException('Cannot switch to a product of a different type.');
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $currentListingId = (int) ($meta['reseller_product_id'] ?? 0);
        $packageChanged = $currentListingId !== (int) $listing->id
            || (int) $service->product_id !== (int) $targetProduct->id;

        $hasHostingAccount = filled($service->external_reference)
            || filled($meta['username'] ?? null);

        $driver = $service->provisioning_driver_key ?: $service->product?->provisioning_driver_key;
        $isDirectAdmin = $driver === 'directadmin'
            || $listing->usesDirectAdminPackage()
            || $targetProduct->directAdminPackage;

        $updates = [
            'billing_cycle' => $validated['billing_cycle'],
            'next_due_date' => $validated['next_due_date'],
            'commenced_at' => $validated['commenced_at'] ?? null,
            'custom_price' => $validated['custom_price'] ?? null,
        ];

        $meta['reseller_product_id'] = $listing->id;

        if ($isDirectAdmin) {
            if (! empty($validated['username'])) {
                $meta['username'] = $validated['username'];
                $updates['external_reference'] = $validated['username'];
            }
            if (! empty($validated['password'])) {
                $meta['password'] = $validated['password'];
            }
            if (! empty($validated['primary_domain'])) {
                $meta['domain'] = strtolower($validated['primary_domain']);
            }

            if (! $hasHostingAccount && ! empty($meta['username'])) {
                $node = $this->resellerDirectAdmin->resolveNode($reseller);
                if ($listing->usesDirectAdminPackage()) {
                    if (! $this->resellerDirectAdmin->packageNameIsValid($reseller, (string) $listing->direct_admin_package_name)) {
                        throw new \InvalidArgumentException('DirectAdmin package is not available on your reseller account.');
                    }

                    $meta = array_merge($meta, $listing->directAdminPackageMeta(), [
                        'reseller_product_id' => $listing->id,
                        'directadmin_reseller' => $reseller->directadmin_username,
                    ]);
                }

                if ($node) {
                    $updates['node_id'] = $node->id;
                    $meta['node_id'] = $node->id;
                    $meta['node_name'] = $node->name;
                }

                $updates['provisioning_driver_key'] = 'directadmin';
            }
        }

        $updates['service_meta'] = $meta;

        $deferAdminProductUpgrade = false;
        $appliedOnServer = false;

        if ($packageChanged && $service->isSharedHosting() && $hasHostingAccount) {
            if ($listing->usesDirectAdminPackage()) {
                $this->applyResellerDirectAdminPackageChange($reseller, $service, $listing);
                $appliedOnServer = true;
                $updates['product_id'] = $targetProduct->id;
                $updates['service_meta'] = array_merge($meta, $listing->directAdminPackageMeta());
                $updates['provisioning_driver_key'] = 'directadmin';
            } elseif ($targetProduct->directAdminPackage) {
                $deferAdminProductUpgrade = true;
            } else {
                $updates['product_id'] = $targetProduct->id;
                $updates['provisioning_driver_key'] = $targetProduct->provisioning_driver_key;
            }
        } elseif ($packageChanged) {
            $updates['product_id'] = $targetProduct->id;
            $updates['provisioning_driver_key'] = $targetProduct->provisioning_driver_key;

            if ($listing->usesDirectAdminPackage()) {
                $updates['service_meta'] = array_merge($updates['service_meta'], $listing->directAdminPackageMeta());
            } elseif ($targetProduct->directAdminPackage) {
                $package = $targetProduct->directAdminPackage;
                $updates['service_meta'] = array_merge($updates['service_meta'], [
                    'package' => $package->package_key,
                    'package_name' => $package->name,
                ]);
            }
        }

        $service->update($updates);

        if ($deferAdminProductUpgrade) {
            try {
                $this->hostingUpgrade->applyUpgrade($service->fresh(), $targetProduct);
                $appliedOnServer = true;
            } catch (\Throwable $e) {
                Log::warning('Reseller service updated but hosting package change failed', [
                    'service_id' => $service->id,
                    'reseller_id' => $reseller->id,
                    'target_product_id' => $targetProduct->id,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'success_message' => 'Service details saved.',
                    'warning_message' => 'Hosting package could not be applied on the server: '.$e->getMessage(),
                ];
            }
        }

        $message = $appliedOnServer
            ? 'Service updated and hosting package changed successfully.'
            : 'Service updated successfully.';

        return ['success_message' => $message, 'warning_message' => null];
    }

    private function applyResellerDirectAdminPackageChange(User $reseller, Service $service, ResellerProduct $listing): void
    {
        if (! $this->resellerDirectAdmin->packageNameIsValid($reseller, (string) $listing->direct_admin_package_name)) {
            throw new \InvalidArgumentException('DirectAdmin package is not available on your reseller account.');
        }

        $service->loadMissing('node');
        $node = $service->node ?? $this->resellerDirectAdmin->resolveNode($reseller);

        if (! $node) {
            throw new \RuntimeException('No DirectAdmin node configured for your account.');
        }

        $meta = $service->service_meta ?? [];
        $username = $meta['username'] ?? $service->external_reference;

        if (! $username) {
            throw new \RuntimeException('Hosting username not found on service.');
        }

        $packageMeta = $listing->directAdminPackageMeta();
        $packageApiName = (string) ($packageMeta['package'] ?? $listing->direct_admin_package_name);
        $directAdmin = $this->resellerDirectAdmin->directAdminForService($service);

        if (! $directAdmin) {
            throw new \RuntimeException('DirectAdmin API is not configured for this service.');
        }

        $this->directAdminSetup->ensurePackageLimitsOnServer(
            $directAdmin,
            $service,
            $this->resellerDirectAdmin->impersonationUsernameForService($service),
        );

        $result = $directAdmin->changeUserPackage($username, $packageApiName);

        if (! $result['success']) {
            throw new \RuntimeException($result['message']);
        }
    }

    private function assertManaged(User $reseller, Service $service): void
    {
        $owned = $service->reseller_id === $reseller->id
            || ($service->user && $service->user->reseller_id === $reseller->id);

        if (! $owned) {
            abort(404);
        }
    }
}
