<?php

namespace App\Console\Commands;

use App\Services\CreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireCreditsCommand extends Command
{
    protected $signature = 'credits:expire';
    protected $description = 'Mark expired credits as expired';

    public function handle(): int
    {
        try {
            $expiredCount = CreditService::expireOldCredits();

            $this->info("Expired {$expiredCount} credits.");

            Log::info("Cron: Credits expiration", [
                'expired_count' => $expiredCount,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error expiring credits: {$e->getMessage()}");

            Log::error("Credits expiration failed", [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
