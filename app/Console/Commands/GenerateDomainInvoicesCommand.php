<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Setting;
use App\Services\DomainAutoRenewService;
use App\Services\DomainRenewalService;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateDomainInvoicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:generate-domain-invoices';

    protected $description = 'Generate renewal invoices for domains (default: 30 days before expiry) and auto-pay auto-renew domains from credits or wallet';

    protected function handleCron(): string
    {
        $schedule = app(InvoiceGenerationScheduleService::class);
        $autoRenew = app(DomainAutoRenewService::class);

        $advanceDays = $schedule->domainAdvanceDays();
        $paymentDays = (int) Setting::getValue('domain_renewal_payment_days', 10);
        $renewalYears = (int) Setting::getValue('domain_renewal_years', 1);

        $domains = $schedule->domainsDueForRenewalInvoiceQuery()
            ->get()
            ->merge($schedule->autoRenewResellerManagedDomainsDueQuery()->get())
            ->unique('id');

        $invoiced = 0;
        $autoPaid = 0;

        foreach ($domains as $domain) {
            if (! $schedule->isDomainDueForRenewalInvoice($domain)) {
                continue;
            }

            $result = $this->invoiceDomain($domain, $renewalYears, $paymentDays, $autoRenew);
            $invoiced += $result['invoiced'] ? 1 : 0;
            $autoPaid += $result['auto_paid'] ? 1 : 0;
        }

        $autoPaid += $autoRenew->payOpenAutoRenewInvoices();

        return "Generated {$invoiced} renewal invoice(s) for {$domains->count()} eligible domain(s) ({$advanceDays} days before expiry); auto-paid {$autoPaid} auto-renew invoice(s).";
    }

    /**
     * @return array{invoiced: bool, auto_paid: bool}
     */
    private function invoiceDomain(
        Domain $domain,
        int $renewalYears,
        int $paymentDays,
        DomainAutoRenewService $autoRenew,
    ): array {
        $invoiced = false;
        $autoPaid = false;

        try {
            $payload = DB::transaction(function () use ($domain, $renewalYears, $paymentDays, $autoRenew) {
                $renewalPrice = $this->getRenewalPrice($domain);

                if ($renewalPrice <= 0) {
                    Log::warning("Domain renewal invoice skipped: {$domain->name}{$domain->extension} - no pricing available");

                    return null;
                }

                $renewalOrder = $autoRenew->startScheduledRenewal($domain, $renewalYears, $paymentDays);
                $alreadyInvoiced = (bool) ($renewalOrder->invoice_id || $renewalOrder->customer_invoice_id);
                $invoice = app(DomainRenewalService::class)->createInvoice($renewalOrder);

                Log::info("Domain renewal invoice generated: {$domain->name}{$domain->extension} (Invoice: {$invoice->invoice_number})");

                return [
                    'invoice' => $invoice,
                    'already_invoiced' => $alreadyInvoiced,
                ];
            });

            if (! $payload) {
                return ['invoiced' => false, 'auto_paid' => false];
            }

            $autoPaid = $autoRenew->attemptAutoPay($payload['invoice']->fresh(), $domain->fresh());

            if ($autoPaid) {
                return ['invoiced' => ! $payload['already_invoiced'], 'auto_paid' => true];
            }

            if (! $payload['already_invoiced']) {
                if ($domain->fresh()->auto_renew) {
                    app(NotificationService::class)->notifyDomainAutoRenewUnpaid($payload['invoice'], $domain);
                } else {
                    app(NotificationService::class)->notifyDomainRenewalInvoice($payload['invoice'], $domain);
                }
                $invoiced = true;
            }
        } catch (\Exception $e) {
            Log::error("Failed to generate domain invoice for {$domain->name}{$domain->extension}: {$e->getMessage()}");
        }

        return ['invoiced' => $invoiced, 'auto_paid' => $autoPaid];
    }

    private function getRenewalPrice(Domain $domain): float
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
