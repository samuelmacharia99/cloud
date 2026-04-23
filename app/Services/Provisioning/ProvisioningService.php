<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Services\NotificationService;
use Illuminate\Support\Str;

class ProvisioningService
{
    /**
     * Provision a service (activate it)
     */
    public function provision(Service $service): void
    {
        try {
            $driver = $service->provisioning_driver_key ?: $service->product->provisioning_driver_key;

            if ($driver === 'directadmin') {
                $this->provisionDirectAdmin($service);
            } elseif ($driver === 'container') {
                $containerService = new ContainerDeploymentService();
                $containerService->deploy($service);
            } elseif ($driver === 'server') {
                $serverService = new ServerProvisioningService();
                $serverService->provision($service);
            } else {
                // For domains, manual hosting, etc. — just activate
                $service->update(['status' => 'active']);
            }

            // Send service activated notification (only if not already sent by the driver)
            if ($service->status === 'active' && ! $service->containerDeployment) {
                app(NotificationService::class)->notifyServiceActivated($service->fresh());
            }
        } catch (\Exception $e) {
            \Log::error("Provisioning failed for service {$service->id}: {$e->getMessage()}");
            $service->update(['status' => 'failed']);

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

            if ($driver === 'directadmin' && $service->external_reference) {
                $daService = new DirectAdminService();
                $daService->suspendAccount($service);
            } elseif ($driver === 'container') {
                $containerService = new ContainerDeploymentService();
                $containerService->suspend($service);
            }

            // Update status if not already updated by driver
            if ($service->status !== 'suspended') {
                $service->update(['status' => 'suspended']);
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

            if ($driver === 'directadmin' && $service->external_reference) {
                $daService = new DirectAdminService();
                $daService->unsuspendAccount($service);
            } elseif ($driver === 'container') {
                $containerService = new ContainerDeploymentService();
                $containerService->unsuspend($service);
            }

            // Update status if not already updated by driver
            if ($service->status !== 'active') {
                $service->update(['status' => 'active']);
            }
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
                $daService = new DirectAdminService();
                $daService->terminateAccount($service);
            } elseif ($driver === 'container') {
                $containerService = new ContainerDeploymentService();
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
    private function provisionDirectAdmin(Service $service): void
    {
        $node = $service->node;
        if (!$node) {
            throw new \Exception('Service is not assigned to a DirectAdmin node — cannot provision.');
        }

        if ($node->type !== 'directadmin') {
            throw new \Exception("Service node {$node->name} is not a DirectAdmin server (type: {$node->type}).");
        }

        $daService = new DirectAdminService($node);

        if (!$daService->isConfigured()) {
            throw new \Exception("DirectAdmin API is not configured for node {$node->name}.");
        }

        // Resolve the package: prefer the product's bound DirectAdmin package,
        // fall back to whatever was stamped into service_meta when the service
        // was created, and only then to the legacy global setting.
        $product = $service->product;
        $package = $product?->directAdminPackage;

        $packageKey = $package?->package_key
            ?? ($service->service_meta['package'] ?? null)
            ?? \App\Models\Setting::getValue('directadmin_default_package', 'default');

        // Resolve credentials and primary domain from service_meta first.
        $meta = $service->service_meta ?? [];
        $username = $meta['username'] ?? $this->generateDirectAdminUsername($service);
        $password = $meta['password'] ?? Str::random(16);
        $domain = $meta['domain'] ?? "{$username}.local";

        $result = $daService->createHostingAccount($service, $username, $password, $domain, $packageKey);

        if ($result['success']) {
            // Persist what we ended up using. Merge into service_meta so the
            // admin sees the actual credentials in the customer detail view,
            // and store the raw API response in `credentials` for audit.
            $service->update([
                'status' => 'active',
                'external_reference' => $username,
                'service_meta' => array_merge($meta, [
                    'username' => $username,
                    'password' => $password,
                    'domain' => $domain,
                    'package' => $packageKey,
                    'package_name' => $package?->name,
                    'node_id' => $node->id,
                    'node_name' => $node->name,
                ]),
                'credentials' => json_encode($result['credentials']),
            ]);

            \Log::info("Service {$service->id} provisioned on DirectAdmin", [
                'service_id' => $service->id,
                'node_id' => $node->id,
                'node' => $node->name,
                'username' => $username,
                'domain' => $domain,
                'package' => $packageKey,
            ]);
        } else {
            throw new \Exception($result['message'] ?? 'DirectAdmin provisioning failed');
        }
    }

    /**
     * Build a DirectAdmin-safe username from a service when the admin/customer
     * didn't supply one.
     *
     * DA accepts up to 16 chars, must start with a letter, lowercase + digits.
     */
    private function generateDirectAdminUsername(Service $service): string
    {
        $base = Str::of($service->name)->lower()->replaceMatches('/[^a-z0-9]+/', '')->__toString();

        if ($base === '' || !ctype_alpha($base[0])) {
            $base = 'u' . $base;
        }

        // Append the service id for uniqueness, but keep it under 16 chars.
        $suffix = (string) $service->id;
        $base = substr($base, 0, max(1, 16 - strlen($suffix)));

        return substr($base . $suffix, 0, 16);
    }
}
