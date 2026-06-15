<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\DomainActivationService;
use App\Services\DomainTransferService;
use App\Services\NotificationService;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerEnforcementService;

class ProvisioningService
{
    public function __construct(
        private DirectAdminSetupService $directAdminSetup,
        private ResellerEnforcementService $resellerEnforcement,
    ) {}

    /**
     * Provision a service (activate it)
     */
    public function provision(Service $service): void
    {
        try {
            $this->resellerEnforcement->assertCanProvision($service);

            $driver = $service->provisioning_driver_key ?: $service->product->provisioning_driver_key;

            if ($driver === 'directadmin') {
                $this->provisionDirectAdmin($service);
            } elseif ($driver === 'container') {
                $containerService = new ContainerDeploymentService;
                $containerService->deploy($service);
            } elseif ($driver === 'server') {
                $serverService = new ServerProvisioningService;
                $serverService->provision($service);
            } else {
                // Unknown driver - only allow for domain-type services (manual provisioning)
                if ($service->product && $service->product->type === 'domain') {
                    $service->update(['status' => 'active']);
                    app(DomainActivationService::class)->activateFromService($service);
                } else {
                    throw new \Exception("Unknown provisioning driver '{$driver}' for service type '{$service->product->type}'. Service requires a valid driver (directadmin, container, server).");
                }
            }

            // Send service activated notification (only if not already sent by the driver)
            if ($service->status === 'active' && ! $service->containerDeployment) {
                app(NotificationService::class)->notifyServiceActivated($service->fresh());
            }
        } catch (\Exception $e) {
            \Log::error("Provisioning failed for service {$service->id}: {$e->getMessage()}");
            $service->update(['status' => 'failed']);

            $service->loadMissing('user', 'product');
            app(NotificationService::class)->notifyServiceProvisionFailed($service->fresh(), $e->getMessage());

            throw $e;
        }
    }

    /**
     * Suspend a service
     */
    public function suspend(Service $service): void
    {
        try {
            $driver = $service->provisioning_driver_key ?: $service->product->provisioning_driver_key;

            // Check for external_reference OR username in service_meta (for linked existing accounts)
            $hasReference = $service->external_reference || ($service->service_meta['username'] ?? null);

            $suspended = false;

            if ($driver === 'directadmin' && $hasReference) {
                $node = $this->resolveDirectAdminNode($service);
                if (! $node) {
                    throw new \Exception('Service has no DirectAdmin node assigned');
                }

                $daService = new DirectAdminService($node);
                $suspended = $daService->suspendAccount($service);
                if (! $suspended) {
                    throw new \Exception('DirectAdmin API failed to suspend account');
                }
            } elseif ($driver === 'container') {
                $containerService = new ContainerDeploymentService;
                $containerService->suspend($service);
                $suspended = true;
            } else {
                // For drivers without active suspension, just update status
                $suspended = true;
            }

            // Only update status if suspension was successful
            if ($suspended && $service->status !== ServiceStatus::Suspended) {
                $service->update([
                    'status' => ServiceStatus::Suspended,
                    'suspend_date' => now(),
                ]);
            }

            // Send service suspended notification
            app(NotificationService::class)->notifyServiceSuspended($service->fresh());
        } catch (\Exception $e) {
            \Log::error("Failed to suspend service {$service->id}: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Unsuspend a service
     */
    public function unsuspend(Service $service): void
    {
        try {
            $driver = $service->provisioning_driver_key ?: $service->product->provisioning_driver_key;

            // Check for external_reference OR username in service_meta (for linked existing accounts)
            $hasReference = $service->external_reference || ($service->service_meta['username'] ?? null);

            if ($driver === 'directadmin' && $hasReference) {
                $node = $this->resolveDirectAdminNode($service);
                if (! $node) {
                    throw new \Exception('Service has no DirectAdmin node assigned');
                }

                $daService = new DirectAdminService($node);
                $unsuspended = $daService->unsuspendAccount($service);
                if (! $unsuspended) {
                    throw new \Exception('DirectAdmin API failed to unsuspend account');
                }
            } elseif ($driver === 'container') {
                $containerService = new ContainerDeploymentService;
                $containerService->unsuspend($service);
            }

            // Update status if not already updated by driver
            if ($service->status !== ServiceStatus::Active) {
                $service->update([
                    'status' => ServiceStatus::Active,
                    'suspend_date' => null,
                ]);
            }

            app(NotificationService::class)->notifyServiceUnsuspended($service->fresh(['user', 'product']));
        } catch (\Exception $e) {
            \Log::error("Failed to unsuspend service {$service->id}: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Terminate a service
     */
    public function terminate(Service $service): void
    {
        try {
            $driver = $service->provisioning_driver_key ?: $service->product->provisioning_driver_key;

            if ($driver === 'directadmin' && $service->external_reference) {
                $node = $this->resolveDirectAdminNode($service);
                if (! $node) {
                    throw new \Exception('Service has no DirectAdmin node assigned');
                }

                $daService = new DirectAdminService($node);
                $terminated = $daService->terminateAccount($service);
                if (! $terminated) {
                    throw new \Exception('DirectAdmin API failed to terminate account');
                }
            } elseif ($driver === 'container') {
                $containerService = new ContainerDeploymentService;
                $containerService->terminate($service);
            }

            // Update status if not already updated by driver
            if ($service->status !== 'terminated') {
                $service->update(['status' => 'terminated', 'terminate_date' => now()]);
            }

            // Send service terminated notification
            app(NotificationService::class)->notifyServiceTerminated($service->fresh());
        } catch (\Exception $e) {
            \Log::error("Failed to terminate service {$service->id}: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Provision a DirectAdmin hosting account on the service's assigned node,
     * using the product's bound DirectAdmin package.
     *
     * Credential and domain inputs come from $service->service_meta when
     * available (set by the admin Add-Service modal or the customer checkout);
     * any missing pieces are generated to keep the legacy "auto-provision on
     * payment" path working when no one has supplied them yet.
     */
    private function resolveDirectAdminNode(Service $service): ?Node
    {
        $service->loadMissing('node');

        if ($service->node) {
            return $service->node;
        }

        if (! $service->reseller_id) {
            return null;
        }

        $reseller = User::query()->find($service->reseller_id);
        if (! $reseller) {
            return null;
        }

        $node = app(ResellerDirectAdminService::class)->resolveNode($reseller);
        if ($node) {
            $service->update(['node_id' => $node->id]);
            $service->setRelation('node', $node);
        }

        return $node;
    }

    private function provisionDirectAdmin(Service $service): void
    {
        $node = $this->resolveDirectAdminNode($service);

        if (! $node) {
            throw new \Exception('Service is not assigned to a DirectAdmin node — cannot provision.');
        }

        if ($node->type !== 'directadmin') {
            throw new \Exception("Service node {$node->name} is not a DirectAdmin server (type: {$node->type}).");
        }

        $daService = new DirectAdminService($node);

        if (! $daService->isConfigured()) {
            throw new \Exception("DirectAdmin API is not configured for node {$node->name}.");
        }

        $credentials = $this->directAdminSetup->resolveCredentials($service);
        $username = $credentials['username'];
        $password = $credentials['password'];
        $domain = $credentials['domain'];
        $packageName = $this->directAdminSetup->resolvePackageName($service);
        $meta = $service->service_meta ?? [];

        if ($service->status === 'active' && ($service->external_reference || ($meta['provisioned_at'] ?? null))) {
            \Log::info("DirectAdmin service {$service->id} already provisioned — skipping create", [
                'username' => $service->external_reference ?? $username,
            ]);

            return;
        }

        if ($daService->accountExists($username)) {
            throw new \Exception("DirectAdmin account \"{$username}\" already exists on {$node->name}.");
        }

        $ownerReseller = $meta['directadmin_reseller'] ?? null;
        if (! $ownerReseller && $service->reseller_id) {
            $ownerReseller = User::query()
                ->whereKey($service->reseller_id)
                ->value('directadmin_username');
        }

        $this->directAdminSetup->ensurePackageLimitsOnServer(
            $daService,
            $service,
            filled($ownerReseller) ? (string) $ownerReseller : null,
        );

        $result = $daService->createHostingAccount(
            $service,
            $username,
            $password,
            $domain,
            $packageName,
            filled($ownerReseller) ? (string) $ownerReseller : null,
        );

        if ($result['success']) {
            $service->update([
                'status' => 'active',
                'external_reference' => $username,
                'service_meta' => array_merge($meta, [
                    'username' => $username,
                    'password' => $password,
                    'domain' => $domain,
                    'package_name' => $packageName,
                    'package' => $meta['package'] ?? null,
                    'node_id' => $node->id,
                    'node_name' => $node->name,
                    'provisioned_at' => now()->toIso8601String(),
                ]),
                'credentials' => json_encode($result['credentials']),
            ]);

            if (! empty($meta['domain_id']) && ! empty($meta['transfer_pending'])) {
                $domainModel = Domain::find($meta['domain_id']);
                if ($domainModel && $domainModel->isTransfer()) {
                    DomainTransferService::initiateTransfer($domainModel);
                }
            } elseif (! empty($meta['domain_id'])) {
                app(DomainActivationService::class)->activateFromService($service->fresh());
            }

            \Log::info("Service {$service->id} provisioned on DirectAdmin", [
                'service_id' => $service->id,
                'node_id' => $node->id,
                'node' => $node->name,
                'username' => $username,
                'domain' => $domain,
                'package' => $packageName,
            ]);

            app(NotificationService::class)->notifySharedHostingCredentials($service->fresh(['user', 'product']));
        } else {
            throw new \Exception($result['message'] ?? 'DirectAdmin provisioning failed');
        }
    }
}
