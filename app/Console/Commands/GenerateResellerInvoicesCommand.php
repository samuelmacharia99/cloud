<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ResellerPackageSubscriptionService;
use Illuminate\Console\Command;

class GenerateResellerInvoicesCommand extends Command
{
    protected $signature = 'cron:generate-reseller-invoices';

    protected $description = 'Generate renewal invoices for reseller packages (5 days before expiry)';

    public function __construct(
        private ResellerPackageSubscriptionService $subscriptions,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $advanceDays = 5;
        $targetDate = now()->addDays($advanceDays)->toDateString();

        $resellers = User::where('is_reseller', true)
            ->whereNotNull('reseller_package_id')
            ->whereNotNull('package_expires_at')
            ->whereDate('package_expires_at', $targetDate)
            ->get();

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($resellers as $reseller) {
            if ($this->subscriptions->pendingSubscriptionInvoice($reseller)) {
                $this->info("Skipping reseller #{$reseller->id} ({$reseller->email}): existing unpaid invoice");
                $skippedCount++;

                continue;
            }

            $package = $reseller->resellerPackage;
            if (! $package) {
                $this->warn("Reseller #{$reseller->id} has no associated package");

                continue;
            }

            $this->subscriptions->createSubscriptionInvoice($reseller, $package, renewal: true);

            $this->info("Created renewal invoice for reseller #{$reseller->id} ({$reseller->email})");
            $createdCount++;
        }

        $this->line('');
        $this->info('Reseller invoice generation completed!');
        $this->info("Target expiry date: {$targetDate}");
        $this->info("Created: {$createdCount} | Skipped: {$skippedCount}");
    }
}
