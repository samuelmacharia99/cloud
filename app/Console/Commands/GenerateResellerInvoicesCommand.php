<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\ResellerPackageSubscriptionService;

class GenerateResellerInvoicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:generate-reseller-invoices';

    protected $description = 'Generate renewal invoices for reseller packages (10 days before expiry)';

    public function __construct(
        private ResellerPackageSubscriptionService $subscriptions,
        private InvoiceGenerationScheduleService $schedule,
    ) {
        parent::__construct();
    }

    protected function handleCron(): string
    {
        $advanceDays = $this->schedule->resellerPackageAdvanceDays();
        $today = now()->startOfDay();

        $resellers = User::query()
            ->where('is_reseller', true)
            ->whereNotNull('reseller_package_id')
            ->whereNotNull('package_expires_at')
            ->get()
            ->filter(fn (User $reseller) => $this->schedule->isResellerPackageDueForRenewalInvoice($reseller, $today));

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($resellers as $reseller) {
            if ($this->subscriptions->pendingSubscriptionInvoice($reseller)) {
                $skippedCount++;

                continue;
            }

            $package = $reseller->resellerPackage;
            if (! $package) {
                continue;
            }

            $this->subscriptions->createSubscriptionInvoice($reseller, $package, renewal: true);
            $createdCount++;
        }

        return "Created {$createdCount} reseller package renewal invoice(s), skipped {$skippedCount} with existing unpaid invoice(s) ({$advanceDays} days before expiry).";
    }
}
