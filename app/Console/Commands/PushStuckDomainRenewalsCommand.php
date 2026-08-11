<?php

namespace App\Console\Commands;

use App\Services\DomainRenewalPushService;
use Illuminate\Console\Command;

class PushStuckDomainRenewalsCommand extends Command
{
    protected $signature = 'domain-renewals:push-stuck-paid';

    protected $description = 'Push domain renewals whose invoices are paid but status never advanced to admin (pushed)';

    public function handle(DomainRenewalPushService $pushService): int
    {
        $advanced = $pushService->pushStuckPaidRenewals();

        $this->info("Advanced {$advanced} stuck paid domain renewal(s).");

        return self::SUCCESS;
    }
}
