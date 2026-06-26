<?php

namespace App\Services;

use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;

class ServiceInfrastructureProbeService
{
    public function infrastructureAlreadyAbsent(Service $service): bool
    {
        $driver = $service->provisioning_driver_key ?: $service->product?->provisioning_driver_key;

        return match ($driver) {
            'directadmin' => $this->directAdminAccountMissing($service),
            'container' => $this->containerWorkloadAbsent($service),
            default => false,
        };
    }

    public function directAdminAccountMissing(Service $service): bool
    {
        $username = $this->directAdminUsername($service);
        if (blank($username)) {
            return true;
        }

        if ($this->cachedDirectAdminAccountMissing($service)) {
            return true;
        }

        $service->loadMissing('node', 'reseller');
        $node = $service->node;

        if (! $node && $service->reseller_id) {
            $reseller = $service->reseller ?? User::query()->find($service->reseller_id);
            $node = $reseller ? app(ResellerDirectAdminService::class)->resolveNode($reseller) : null;
        }

        if (! $node) {
            return false;
        }

        $directAdmin = new DirectAdminService($node);
        if (! $directAdmin->isConfigured()) {
            return false;
        }

        return ! $directAdmin->accountExists($username);
    }

    private function cachedDirectAdminAccountMissing(Service $service): bool
    {
        if ($service->live_status !== 'terminated') {
            return false;
        }

        $label = strtolower((string) ($service->live_status_label ?? ''));

        return str_contains($label, 'not found');
    }

    private function directAdminUsername(Service $service): ?string
    {
        return $service->external_reference ?? ($service->service_meta['username'] ?? null);
    }

    public function containerWorkloadAbsent(Service $service): bool
    {
        $service->loadMissing('containerDeployment');
        $deployment = $service->containerDeployment;

        if (! $deployment) {
            return true;
        }

        return in_array($deployment->status, ['terminated', 'failed'], true);
    }
}
