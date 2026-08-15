<?php

namespace App\Console\Commands;

use App\Services\CreditService;
use Illuminate\Support\Facades\Log;

class ExpireCreditsCommand extends BaseCronCommand
{
    protected $signature = 'credits:expire';

    protected $description = 'Mark expired credits as expired';

    protected function handleCron(): string
    {
        try {
            $expiredCount = CreditService::expireOldCredits();

            Log::info('Cron: Credits expiration', [
                'expired_count' => $expiredCount,
            ]);

            return "Expired {$expiredCount} credits.";
        } catch (\Exception $e) {
            Log::error('Credits expiration failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
