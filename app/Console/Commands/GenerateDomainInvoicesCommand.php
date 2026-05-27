<?php

namespace App\Console\Commands;

use App\Models\DomainRenewalOrder;
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

                    $renewalOrder = DomainRenewalOrder::create([
                        'domain_id' => $domain->id,
                        'user_id' => $domain->user_id,
                        'years' => $renewalYears,
                        'amount' => $renewalPrice,
                        'status' => 'pending',
                        'expires_at' => now()->addDays($paymentDays),
                    ]);

                    $renewalService = app(DomainRenewalService::class);
                    $invoice = $renewalService->createInvoice($renewalOrder);

                    app(NotificationService::class)->notifyDomainRenewalInvoice($invoice, $domain);

                    Log::info("Domain renewal invoice generated: {$domain->name}{$domain->extension} (Invoice: {$invoice->invoice_number})");
                    $count++;
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

        return (float) ($pricing->renewal_price ?? $pricing->price ?? 0);
    }
}
