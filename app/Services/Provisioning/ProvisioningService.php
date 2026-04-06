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
     * Provision DirectAdmin hosting account
     */
    private function provisionDirectAdmin(Service $service): void
    {
        $daService = new DirectAdminService();

        // Generate username from service name + ID
        $username = Str::slug($service->name) . $service->id;
        $username = substr($username, 0, 16); // DA limit

        // Generate random password
        $password = Str::random(16);

        // Get domain from custom_options or use service name
        $domain = $service->service_meta['domain'] ?? "{$username}.com";

        // Get package from settings
        $package = \App\Models\Setting::getValue('directadmin_default_package', 'default');

        // Create account
        $result = $daService->createHostingAccount($service, $username, $password, $domain, $package);

        if ($result['success']) {
            // Save credentials to service
            $service->update([
                'status' => 'active',
                'external_reference' => $username,
                'credentials' => json_encode($result['credentials']),
            ]);

            // Log the creation
            \Log::info("Service {$service->id} provisioned on DirectAdmin", [
                'username' => $username,
                'domain' => $domain,
            ]);
        } else {
            throw new \Exception($result['message'] ?? 'DirectAdmin provisioning failed');
        }
    }
}
