<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\DomainRenewalService;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateDomainInvoicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:generate-domain-invoices';

    protected $description = 'Generate renewal invoices for domains (default: 30 days before expiry)';

    protected function handleCron(): string
    {
        $schedule = app(InvoiceGenerationScheduleService::class);

        $advanceDays = $schedule->domainAdvanceDays();
        $paymentDays = (int) Setting::getValue('domain_renewal_payment_days', 10);
        $renewalYears = (int) Setting::getValue('domain_renewal_years', 1);

        $domains = $schedule->domainsDueForRenewalInvoiceQuery()->get();

        $count = 0;
        foreach ($domains as $domain) {
            if (! $schedule->isDomainDueForRenewalInvoice($domain)) {
                continue;
            }

            try {
                DB::transaction(function () use ($domain, $renewalYears, $paymentDays, &$count) {
                    $renewalPrice = $this->getRenewalPrice($domain);

                    if ($renewalPrice <= 0) {
                        Log::warning("Domain renewal invoice skipped: {$domain->name}{$domain->extension} - no pricing available");

                        return;
                    }

                    $renewalService = app(DomainRenewalService::class);
                    $renewalOrder = $renewalService->initiateRenewal(
                        $domain,
                        $domain->user,
                        $renewalYears,
                        $paymentDays
                    );
                    $alreadyInvoiced = $renewalOrder->invoice_id || $renewalOrder->customer_invoice_id;
                    $invoice = $renewalService->createInvoice($renewalOrder);

                    if (! $alreadyInvoiced) {
                        app(NotificationService::class)->notifyDomainRenewalInvoice($invoice, $domain);
                        $count++;
                    }

                    Log::info("Domain renewal invoice generated: {$domain->name}{$domain->extension} (Invoice: {$invoice->invoice_number})");
                });
            } catch (\Exception $e) {
                Log::error("Failed to generate domain invoice for {$domain->name}{$domain->extension}: {$e->getMessage()}");
            }
        }

        return "Generated {$count} renewal invoice(s) for {$domains->count()} eligible domain(s) ({$advanceDays} days before expiry).";
    }

    private function getRenewalPrice($domain): float
    {
        $extension = $domain->domainExtension;
        if (! $extension) {
            return 0;
        }

        $domain->loadMissing('user');
        $user = $domain->user;
        if (! $user) {
            return 0;
        }

        $pricing = $extension->getPricingForUser($user, 1);
        if (! $pricing) {
            return 0;
        }

        return (float) ($pricing->renewal_price ?? $pricing->price ?? 0);
    }
}
