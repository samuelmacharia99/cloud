<?php

namespace App\Services;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Support\Facades\Log;

class ServiceDeletionService
{
    public function __construct(
        private ProvisioningService $provisioning,
    ) {}

    /**
     * Terminate provisioned infrastructure when needed, then remove the service record.
     *
     * @throws \RuntimeException when live infrastructure cannot be cleaned up
     */
    public function delete(Service $service): void
    {
        if ($this->requiresInfrastructureCleanup($service)) {
            $alreadyInactive = in_array($service->status->value, ['terminated', 'cancelled'], true);

            try {
                $this->provisioning->terminate($service->fresh());
            } catch (\Throwable $e) {
                Log::warning('Service delete: infrastructure cleanup failed', [
                    'service_id' => $service->id,
                    'status' => $service->status->value,
                    'error' => $e->getMessage(),
                ]);

                if (! $alreadyInactive) {
                    throw new \RuntimeException(
                        'Could not deprovision service infrastructure. Terminate the service first or fix the host connection, then delete.'
                    );
                }
            }
        }

        $service->fresh()->delete();
    }

    public function requiresInfrastructureCleanup(Service $service): bool
    {
        $driver = $service->provisioning_driver_key ?: $service->product?->provisioning_driver_key;

        return in_array($driver, ['container', 'directadmin'], true);
    }
}
