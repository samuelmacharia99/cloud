<?php

namespace App\Services;

use App\Models\User;

class ResellerCheckoutGuardService
{
    public function __construct(
        private ResellerDiskUsageService $diskUsage,
    ) {}

    public function assertCheckoutAllowed(User $customer): void
    {
        if (! $customer->reseller_id) {
            return;
        }

        $reseller = User::query()->find($customer->reseller_id);
        if (! $reseller?->is_reseller) {
            throw new \InvalidArgumentException('Your account is not linked to an active reseller. Contact support.');
        }

        if ($reseller->isResellerSuspended()) {
            throw new \InvalidArgumentException(
                'Your provider\'s account is temporarily suspended. New orders cannot be placed until billing is restored.'
            );
        }

        if (! $reseller->hasResellerPackage()) {
            throw new \InvalidArgumentException(
                'Your provider has not activated a reseller package yet. New orders are unavailable.'
            );
        }

        if ($reseller->isAtServiceLimit()) {
            throw new \InvalidArgumentException(
                'Your provider has reached their service capacity. Contact them to upgrade before ordering more hosting.'
            );
        }

        if ($this->diskUsage->isOverPool($reseller)) {
            throw new \InvalidArgumentException(
                'Your provider has exceeded their disk pool allocation. New hosting orders are temporarily unavailable.'
            );
        }
    }
}
