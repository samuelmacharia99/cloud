<?php

namespace App\Console\Commands;

use App\Models\ResellerDomainOrder;
use Illuminate\Console\Command;

class ExpireQueuedDomainOrdersCommand extends Command
{
    protected $signature = 'cron:expire-queued-domain-orders';
    protected $description = 'Mark queued domain orders as expired if past their expiration date';

    public function handle(): int
    {
        $expired = ResellerDomainOrder::where('status', 'queued')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        // Get unique resellers for notification
        $resellers = ResellerDomainOrder::where('status', 'expired')
            ->where('updated_at', '>=', now()->subMinute())
            ->distinct('reseller_id')
            ->pluck('reseller_id');

        $message = "{$expired} domain order(s) expired for " . count($resellers) . " reseller(s)";
        $this->info($message);

        return self::SUCCESS;
    }
}
