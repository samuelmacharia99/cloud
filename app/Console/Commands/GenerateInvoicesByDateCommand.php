<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Billing\InvoiceNumberService;
use App\Services\Billing\ServiceRenewalPricingService;
use App\Services\ContainerOverageBillingService;
use App\Services\DomainRenewalService;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\NotificationService;
use App\Services\TaxService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateInvoicesByDateCommand extends Command
{
    protected $signature = 'invoices:generate-for-date
                            {--for-date= : The date to generate invoices for (YYYY-MM-DD). Defaults to today}
                            {--type=all : Type of invoices: service, domain, or all}
                            {--send-notifications : Send email/SMS notifications to customers}';

    protected $description = 'Generate renewal invoices using advance windows (monthly services: 10 days, others/domains: 30 days)';

    public function handle(): int
    {
        $dateString = $this->option('for-date') ?: now()->toDateString();
        $type = $this->option('type') ?: 'all';
        $sendNotifications = $this->hasOption('send-notifications') && $this->option('send-notifications');

        try {
            $date = Carbon::createFromFormat('Y-m-d', $dateString);
        } catch (\Exception $e) {
            $this->error('Invalid date format. Use YYYY-MM-DD');

            return 1;
        }

        $this->info("Generating invoices for date: {$date->format('l, F j, Y')}");
        $this->info('Type: '.ucfirst($type));
        if ($sendNotifications) {
            $this->info('Notifications: ENABLED');
        }
        $this->newLine();

        $serviceCount = 0;
        $domainCount = 0;

        if (in_array($type, ['service', 'all'])) {
            $serviceCount = $this->generateServiceInvoices($date, $sendNotifications);
        }

        if (in_array($type, ['domain', 'all'])) {
            $domainCount = $this->generateDomainInvoices($date, $sendNotifications);
        }

        $this->newLine();
        $this->info('✓ Complete!');
        $this->line("Service invoices generated: {$serviceCount}");
        $this->line("Domain invoices generated: {$domainCount}");
        $this->line('Total: '.($serviceCount + $domainCount));

        return 0;
    }

    private function generateServiceInvoices(Carbon $date, bool $sendNotifications): int
    {
        $this->line('Generating service renewal invoices...');

        $schedule = app(InvoiceGenerationScheduleService::class);
        $services = $schedule->servicesDueForRenewalInvoiceQuery($date)
            ->get()
            ->filter(fn (Service $service) => $schedule->isServiceDueForRenewalInvoice($service, $date));

        if ($services->isEmpty()) {
            $this->line('  No services due for renewal invoice generation on this date.');

            return 0;
        }

        $invoiceNumbers = app(InvoiceNumberService::class);
        $renewalPricing = app(ServiceRenewalPricingService::class);
        $count = 0;
        foreach ($services as $service) {
            try {
                $invoiceNumbers->createWithUniqueNumber(function (string $number) use (
                    $service,
                    $date,
                    $sendNotifications,
                    &$count,
                    $schedule,
                    $renewalPricing,
                ) {
                    $price = $renewalPricing->unitPrice($service);
                    $service->loadMissing('user');
                    $taxBreakdown = TaxService::calculateForUser($price, $service->user);
                    $dueDate = $schedule->serviceInvoiceDueDate($service)->toDateString();

                    $invoice = Invoice::create([
                        'user_id' => $service->user_id,
                        'invoice_number' => $number,
                        'status' => 'unpaid',
                        'due_date' => $dueDate,
                        'subtotal' => $taxBreakdown['subtotal'],
                        'tax' => $taxBreakdown['tax'],
                        'total' => $taxBreakdown['total'],
                        'notes' => 'Manual invoice generation for '.$date->format('M d, Y').'.',
                    ]);

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'service_id' => $service->id,
                        'product_id' => $service->product_id,
                        'description' => $service->product->name.' — '.ucfirst($service->billing_cycle),
                        'quantity' => 1,
                        'unit_price' => $price,
                        'amount' => $price,
                    ]);

                    if ($service->product->overage_enabled && $service->containerDeployment) {
                        app(ContainerOverageBillingService::class)->addOverageItemsToInvoice(
                            $invoice,
                            $service,
                        );
                    }

                    $service->update(['invoice_id' => $invoice->id]);

                    if ($sendNotifications) {
                        app(NotificationService::class)->notifyInvoiceGenerated($invoice);
                    }

                    $this->line("  ✓ Service: {$service->name} → Invoice {$invoice->invoice_number} (KES ".number_format($invoice->total, 2).')');
                    $count++;
                });
            } catch (\Exception $e) {
                $this->error("  ✗ Failed for {$service->name}: {$e->getMessage()}");
                Log::error("Failed to generate service invoice for {$service->name}: {$e->getMessage()}");
            }
        }

        return $count;
    }

    private function generateDomainInvoices(Carbon $date, bool $sendNotifications): int
    {
        $this->line('Generating domain renewal invoices...');

        $schedule = app(InvoiceGenerationScheduleService::class);
        $domains = $schedule->domainsDueForRenewalInvoiceQuery($date)->get();

        if ($domains->isEmpty()) {
            $this->line('  No domains due for renewal invoice generation on this date.');

            return 0;
        }

        $renewalYears = (int) Setting::getValue('domain_renewal_years', 1);
        $paymentDays = (int) Setting::getValue('domain_renewal_payment_days', 10);

        $count = 0;
        foreach ($domains as $domain) {
            if (! $schedule->isDomainDueForRenewalInvoice($domain, $date)) {
                continue;
            }

            try {
                DB::transaction(function () use ($domain, $renewalYears, $paymentDays, $sendNotifications, &$count) {
                    $renewalPrice = $this->getRenewalPrice($domain);

                    if ($renewalPrice <= 0) {
                        $this->error("  ✗ {$domain->name}{$domain->extension} - no pricing available");

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

                    if ($sendNotifications) {
                        app(NotificationService::class)->notifyDomainRenewalInvoice($invoice, $domain);
                    }

                    $this->line("  ✓ Domain: {$domain->name}{$domain->extension} → Invoice {$invoice->invoice_number} (KES ".number_format($invoice->total, 2).')');
                    $count++;
                });
            } catch (\Exception $e) {
                $this->error("  ✗ Failed for {$domain->name}{$domain->extension}: {$e->getMessage()}");
                Log::error("Failed to generate domain invoice for {$domain->name}{$domain->extension}: {$e->getMessage()}");
            }
        }

        return $count;
    }

    private function getRenewalPrice(Domain $domain): float
    {
        $extension = $domain->domainExtension;
        if (! $extension) {
            return 0;
        }

        $pricing = $extension->getRetailPricing(1);

        return (float) ($pricing->renewal_price ?? $pricing->price ?? 0);
    }
}
