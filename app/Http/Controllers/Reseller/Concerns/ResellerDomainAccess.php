<?php

namespace App\Http\Controllers\Reseller\Concerns;

use App\Models\Domain;
use App\Models\Service;

trait ResellerDomainAccess
{
    protected function assertResellerCanManageDomain(Domain $domain): void
    {
        $resellerId = auth()->id();

        if ($domain->user_id === $resellerId || $domain->reseller_id === $resellerId) {
            return;
        }

        $customerIds = Service::query()
            ->where('reseller_id', $resellerId)
            ->distinct()
            ->pluck('user_id');

        abort_unless($customerIds->contains($domain->user_id), 403, 'You cannot manage this domain.');
    }
}
