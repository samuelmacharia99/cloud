<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\ResellerPackageSubscriptionService;
use Illuminate\Support\Facades\Log;

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
            ->with('resellerPackage')
            ->get()
            ->filter(fn (User $reseller) => $this->schedule->isResellerPackageDueForRenewalInvoice($reseller, $today));

        $createdCount = 0;
        $autoPaidCount = 0;
        $skippedCount = 0;

        foreach ($resellers as $reseller) {
            $pending = $this->subscriptions->pendingRenewalSubscriptionInvoice($reseller);
            if ($pending) {
                Log::info('Reseller renewal invoice skipped: open renewal invoice for current period', [
                    'reseller_id' => $reseller->id,
                    'invoice_id' => $pending->id,
                    'package_expires_at' => $reseller->package_expires_at?->toDateString(),
                ]);
                $skippedCount++;

                continue;
            }

            if (! $reseller->resellerPackage) {
                Log::warning('Reseller renewal invoice skipped: package record missing', [
                    'reseller_id' => $reseller->id,
                    'reseller_package_id' => $reseller->reseller_package_id,
                ]);

                continue;
            }

            try {
                $invoice = $this->subscriptions->createRenewalInvoiceIfNeeded($reseller);
                $createdCount++;

                Log::info('Reseller package renewal invoice created', [
                    'reseller_id' => $reseller->id,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'package_expires_at' => $reseller->package_expires_at?->toDateString(),
                ]);

                if ($invoice->isPaid()) {
                    $autoPaidCount++;
                }
            } catch (\InvalidArgumentException $e) {
                Log::info('Reseller renewal invoice not created', [
                    'reseller_id' => $reseller->id,
                    'reason' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::error('Reseller renewal invoice creation failed', [
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $missingCount = User::query()
            ->where('is_reseller', true)
            ->whereNotNull('reseller_package_id')
            ->whereNotNull('package_expires_at')
            ->get()
            ->filter(fn (User $reseller) => $this->schedule->resellerPackageMissingRenewalInvoice($reseller))
            ->count();

        if ($missingCount > 0) {
            Log::warning('Resellers in renewal window without a renewal invoice', [
                'count' => $missingCount,
            ]);
        }

        return "Created {$createdCount} reseller package renewal invoice(s), {$autoPaidCount} auto-paid from wallet, skipped {$skippedCount} with existing renewal invoice(s) ({$advanceDays} days before expiry).";
    }
}
