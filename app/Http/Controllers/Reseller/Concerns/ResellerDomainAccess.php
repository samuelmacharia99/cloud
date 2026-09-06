<?php

namespace App\Http\Controllers\Reseller\Concerns;

use App\Models\Domain;

trait ResellerDomainAccess
{
    protected function assertResellerCanManageDomain(Domain $domain): void
    {
        abort_unless(
            $domain->isManagedByReseller(auth()->user()),
            403,
            'You cannot manage this domain.',
        );
    }
}
