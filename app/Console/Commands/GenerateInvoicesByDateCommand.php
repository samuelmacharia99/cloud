<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\DomainRenewalOrder;
use App\Models\Setting;
use App\Services\DomainRenewalService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateInvoicesByDateCommand extends Command
{
    protected $signature = 'invoices:generate-for-date
                            {--for-date= : The date to generate invoices for (YYYY-MM-DD). Defaults to today}
                            {--type=all : Type of invoices: service, domain, or all}
                            {--send-notifications : Send email/SMS notifications to customers}';

    protected $description = 'Generate invoices for services/domains expiring on a specific date';

    public function handle(): int
    {
        $dateString = $this->option('for-date') ?: now()->toDateString();
        $type = $this->option('type') ?: 'all';
        $sendNotifications = $this->hasOption('send-notifications') && $this->option('send-notifications');

        try {
            $date = \Carbon\Carbon::createFromFormat('Y-m-d', $dateString);
        } catch (\Exception $e) {
            $this->error("Invalid date format. Use YYYY-MM-DD");
            return 1;
        }

        $this->info("Generating invoices for date: {$date->format('l, F j, Y')}");
        $this->info("Type: " . ucfirst($type));
        if ($sendNotifications) {
            $this->info("Notifications: ENABLED");
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
        $this->info("✓ Complete!");
        $this->line("Service invoices generated: {$serviceCount}");
        $this->line("Domain invoices generated: {$domainCount}");
        $this->line("Total: " . ($serviceCount + $domainCount));

        return 0;
    }

    private function generateServiceInvoices(\Carbon\Carbon $date, bool $sendNotifications): int
    {
        $this->line("Generating service renewal invoices...");

        $services = Service::with(['product', 'user'])
            ->where('status', 'active')
            ->whereDate('next_due_date', '=', $date->toDateString())
            ->whereDoesntHave('invoice', function ($q) use ($date) {
                $q->whereIn('status', ['draft', 'unpaid'])
                  ->whereDate('created_at', '>=', $date->copy()->subDays(7)->toDateString());
            })
            ->get();

        if ($services->isEmpty()) {
            $this->line("  No services expiring on this date.");
            return 0;
        }

        $invoiceDueDays = (int) Setting::getValue('invoice_due_days', 14);
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $taxEnabled = Setting::getValue('tax_enabled', 'false') === 'true';

        $count = 0;
        foreach ($services as $service) {
            try {
                DB::transaction(function () use ($service, $prefix, $invoiceDueDays, $taxRate, $taxEnabled, $date, $sendNotifications, &$count) {
                    $year = $date->format('Y');
                    $sequence = Invoice::whereYear('created_at', $year)->count() + 1;
                    $number = $prefix . '-' . $year . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);

                    $price = $this->getPriceForCycle($service);
                    $tax = $taxEnabled ? round($price * $taxRate / 100, 2) : 0;
                    $total = $price + $tax;
                    $dueDate = $date->copy()->addDays($invoiceDueDays)->toDateString();

                    $invoice = Invoice::create([
                        'user_id' => $service->user_id,
                        'invoice_number' => $number,
                        'status' => 'unpaid',
                        'due_date' => $dueDate,
                        'subtotal' => $price,
                        'tax' => $tax,
                        'total' => $total,
                        'notes' => 'Manual invoice generation for ' . $date->format('M d, Y') . '.',
                    ]);

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'service_id' => $service->id,
                        'product_id' => $service->product_id,
                        'description' => $service->product->name . ' — ' . ucfirst($service->billing_cycle),
                        'quantity' => 1,
                        'unit_price' => $price,
                        'amount' => $price,
                    ]);

                    if ($service->product->overage_enabled && $service->containerDeployment) {
                        $this->addOverageItems($invoice, $service, $taxEnabled, $taxRate);
                    }

                    $service->update([
                        'invoice_id' => $invoice->id,
                        'next_due_date' => $this->advanceDueDate($service, $date),
                    ]);

                    if ($sendNotifications) {
                        app(NotificationService::class)->notifyInvoiceGenerated($invoice);
                    }

                    $this->line("  ✓ Service: {$service->name} → Invoice {$invoice->invoice_number} (KES " . number_format($invoice->total, 2) . ")");
                    $count++;
                });
            } catch (\Exception $e) {
                $this->error("  ✗ Failed for {$service->name}: {$e->getMessage()}");
                Log::error("Failed to generate service invoice for {$service->name}: {$e->getMessage()}");
            }
        }

        return $count;
    }

    private function generateDomainInvoices(\Carbon\Carbon $date, bool $sendNotifications): int
    {
        $this->line("Generating domain renewal invoices...");

        $domains = Domain::where('status', 'active')
            ->whereDate('expires_at', '=', $date->toDateString())
            ->whereDoesntHave('renewalOrders', function ($q) use ($date) {
                $q->whereIn('status', ['pending', 'invoiced'])
                  ->whereDate('created_at', '>=', $date->copy()->subDays(7)->toDateString());
            })
            ->with(['user', 'domainExtension'])
            ->get();

        if ($domains->isEmpty()) {
            $this->line("  No domains expiring on this date.");
            return 0;
        }

        $renewalYears = (int) Setting::getValue('domain_renewal_years', 1);
        $paymentDays = (int) Setting::getValue('domain_renewal_payment_days', 10);

        $count = 0;
        foreach ($domains as $domain) {
            try {
                DB::transaction(function () use ($domain, $renewalYears, $paymentDays, $date, $sendNotifications, &$count) {
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
                        'expires_at' => $date->copy()->addDays($paymentDays)->toDateString(),
                    ]);

                    $renewalService = app(DomainRenewalService::class);
                    $invoice = $renewalService->createInvoice($renewalOrder);

                    if ($sendNotifications) {
                        app(NotificationService::class)->notifyDomainRenewalInvoice($invoice, $domain);
                    }

                    $this->line("  ✓ Domain: {$domain->name}{$domain->extension} → Invoice {$invoice->invoice_number} (KES " . number_format($invoice->total, 2) . ")");
                    $count++;
                });
            } catch (\Exception $e) {
                $this->error("  ✗ Failed for {$domain->name}{$domain->extension}: {$e->getMessage()}");
                Log::error("Failed to generate domain invoice for {$domain->name}{$domain->extension}: {$e->getMessage()}");
            }
        }

        return $count;
    }

    private function getPriceForCycle(Service $service): float
    {
        return match($service->billing_cycle) {
            'monthly' => (float) $service->product->monthly_price,
            'quarterly' => (float) ($service->product->monthly_price * 3),
            'semi-annual' => (float) ($service->product->monthly_price * 6),
            'annual' => (float) $service->product->yearly_price ?: ($service->product->monthly_price * 12),
            default => (float) $service->product->price,
        };
    }

    private function advanceDueDate(Service $service, \Carbon\Carbon $fromDate)
    {
        return match($service->billing_cycle) {
            'monthly' => $fromDate->copy()->addMonth(),
            'quarterly' => $fromDate->copy()->addMonths(3),
            'semi-annual' => $fromDate->copy()->addMonths(6),
            'annual' => $fromDate->copy()->addYear(),
            default => $fromDate->copy()->addMonth(),
        };
    }

    private function getRenewalPrice(Domain $domain): float
    {
        $extension = $domain->domainExtension;
        if (!$extension) {
            return 0;
        }

        $pricing = $extension->getRetailPricing(1);
        return (float) ($pricing->renewal_price ?? $pricing->price ?? 0);
    }

    private function addOverageItems(Invoice $invoice, Service $service, bool $taxEnabled, float $taxRate): void
    {
        $deployment = $service->containerDeployment;
        $template = $service->product->containerTemplate;
        $product = $service->product;

        if (!$deployment || !$template) {
            return;
        }

        $from = $service->last_invoice_date ?? $service->created_at;
        $to = now();
        $billingHours = (float) $from->diffInHours($to);

        if ($billingHours <= 0) {
            return;
        }

        $avgCpuPercent = \App\Models\ContainerMetric::averageCpuPercent($deployment, $from, $to);
        $avgMemoryMb = \App\Models\ContainerMetric::averageMemoryMb($deployment, $from, $to);

        $avgCpuCores = $avgCpuPercent / 100;
        $avgMemoryGb = $avgMemoryMb / 1024;

        $includedCores = $template->required_cpu_cores;
        $includedGb = $template->required_ram_mb / 1024;

        $cpuOverageHours = max(0, $avgCpuCores - $includedCores) * $billingHours;
        $memoryOverageGbHours = max(0, $avgMemoryGb - $includedGb) * $billingHours;

        $cpuOverageAmount = $cpuOverageHours * $product->cpu_overage_rate;
        $memoryOverageAmount = $memoryOverageGbHours * $product->ram_overage_rate;

        if ($cpuOverageAmount > 0) {
            $cpuTax = $taxEnabled ? round($cpuOverageAmount * $taxRate / 100, 2) : 0;

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $service->product_id,
                'description' => "CPU Overage — {$cpuOverageHours} core-hours @ KES {$product->cpu_overage_rate}/hour",
                'quantity' => $cpuOverageHours,
                'unit_price' => $product->cpu_overage_rate,
                'amount' => $cpuOverageAmount,
            ]);

            $invoice->increment('subtotal', $cpuOverageAmount);
            $invoice->increment('tax', $cpuTax);
            $invoice->increment('total', $cpuOverageAmount + $cpuTax);
        }

        if ($memoryOverageAmount > 0) {
            $memTax = $taxEnabled ? round($memoryOverageAmount * $taxRate / 100, 2) : 0;

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $service->product_id,
                'description' => "RAM Overage — {$memoryOverageGbHours} GB-hours @ KES {$product->ram_overage_rate}/GB-hour",
                'quantity' => $memoryOverageGbHours,
                'unit_price' => $product->ram_overage_rate,
                'amount' => $memoryOverageAmount,
            ]);

            $invoice->increment('subtotal', $memoryOverageAmount);
            $invoice->increment('tax', $memTax);
            $invoice->increment('total', $memoryOverageAmount + $memTax);
        }
    }
}
