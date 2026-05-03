<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DomainPushService;
use Illuminate\Console\Command;

class ProcessQueuedDomainOrdersCommand extends Command
{
    protected $signature = 'cron:process-queued-domain-orders';
    protected $description = 'Process queued domain orders when resellers have sufficient funds';

    public function handle(DomainPushService $domainPushService): int
    {
        $resellers = User::where('is_reseller', true)
            ->whereHas('domainOrders', function ($query) {
                $query->where('status', 'queued')
                    ->where('expires_at', '>', now());
            })
            ->get();

        $totalPushed = 0;
        $resellersProcessed = 0;

        foreach ($resellers as $reseller) {
            $pushed = $domainPushService->processQueuedOrders($reseller);
            $totalPushed += $pushed;
            if ($pushed > 0) {
                $resellersProcessed++;
            }
        }

        $message = "{$totalPushed} domain order(s) pushed from {$resellersProcessed} reseller(s)";
        $this->info($message);

        return self::SUCCESS;
    }
}
