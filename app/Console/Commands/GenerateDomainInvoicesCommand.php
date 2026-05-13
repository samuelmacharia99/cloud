<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Services\DomainRenewalService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateDomainInvoicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:generate-domain-invoices';
    protected $description = 'Generate renewal invoices for domains expiring in 30 days';

    protected function handleCron(): string
    {
        $advanceDays = (int) Setting::getValue('domain_renewal_advance_days', 30);
        $paymentDays = (int) Setting::getValue('domain_renewal_payment_days', 10);
        $renewalYears = (int) Setting::getValue('domain_renewal_years', 1);

        $thirtyDaysFromNow = now()->addDays($advanceDays);

        // Find active domains expiring soon with no pending renewal
        $domains = Domain::where('status', 'active')
            ->whereDate('expires_at', '<=', $thirtyDaysFromNow->toDateString())
            ->whereDate('expires_at', '>', now()->toDateString())
            ->whereDoesntHave('renewalOrders', function ($q) {
                $q->whereIn('status', ['pending', 'invoiced'])
                  ->where('created_at', '>=', now()->subDays(7));
            })
            ->with(['user', 'domainExtension'])
            ->get();

        $count = 0;
        foreach ($domains as $domain) {
            try {
                DB::transaction(function () use ($domain, $renewalYears, $paymentDays, &$count) {
                    $renewalPrice = $this->getRenewalPrice($domain);

                    if ($renewalPrice <= 0) {
                        Log::warning("Domain renewal invoice skipped: {$domain->name}{$domain->extension} - no pricing available");
                        return;
                    }

                    // Create renewal order
                    $renewalOrder = DomainRenewalOrder::create([
                        'domain_id' => $domain->id,
                        'user_id' => $domain->user_id,
                        'years' => $renewalYears,
                        'amount' => $renewalPrice,
                        'status' => 'pending',
                        'expires_at' => now()->addDays($paymentDays),
                    ]);

                    // Create invoice using service
                    $renewalService = app(DomainRenewalService::class);
                    $invoice = $renewalService->createInvoice($renewalOrder);

                    // Notify customer
                    app(NotificationService::class)->notifyDomainRenewalInvoice($invoice, $domain);

                    Log::info("Domain renewal invoice generated: {$domain->name}{$domain->extension} (Invoice: {$invoice->invoice_number})");
                    $count++;
                });
            } catch (\Exception $e) {
                Log::error("Failed to generate domain invoice for {$domain->name}{$domain->extension}: {$e->getMessage()}");
            }
        }

        return "Generated {$count} renewal invoice(s) for {$domains->count()} expiring domain(s).";
    }

    private function getRenewalPrice(Domain $domain): float
    {
        $extension = $domain->domainExtension;
        if (!$extension) {
            return 0;
        }

        $domain->loadMissing('user');
        $user = $domain->user;
        if (!$user) {
            return 0;
        }

        $pricing = $extension->getPricingForUser($user, 1);
        return (float) ($pricing->renewal_price ?? $pricing->price ?? 0);
    }
}
