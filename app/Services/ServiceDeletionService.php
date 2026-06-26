<?php

namespace App\Services;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Support\Facades\Log;

class ServiceDeletionService
{
    public function __construct(
        private ProvisioningService $provisioning,
        private ServiceInfrastructureProbeService $probe,
    ) {}

    /**
     * Terminate provisioned infrastructure when needed, then remove the service record.
     *
     * @throws \RuntimeException when live infrastructure cannot be cleaned up
     */
    public function delete(Service $service, bool $skipProvisioning = false): void
    {
        $infrastructureAbsent = $this->probe->infrastructureAlreadyAbsent($service);

        if ($this->requiresInfrastructureCleanup($service) && ! $skipProvisioning && ! $infrastructureAbsent) {
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
                        'Could not deprovision service infrastructure. Terminate the service first, use force delete, or fix the host connection.'
                    );
                }
            }
        } elseif ($infrastructureAbsent || $skipProvisioning) {
            Log::info('Service delete: skipping infrastructure cleanup', [
                'service_id' => $service->id,
                'infrastructure_absent' => $infrastructureAbsent,
                'force' => $skipProvisioning,
            ]);
        }

        $service->fresh()->delete();
    }

    public function requiresInfrastructureCleanup(Service $service): bool
    {
        $driver = $service->provisioning_driver_key ?: $service->product?->provisioning_driver_key;

        return in_array($driver, ['container', 'directadmin'], true);
    }
}
