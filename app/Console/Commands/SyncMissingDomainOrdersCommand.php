<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\DomainPushService;
use App\Services\ResellerDomainOrderService;
use Illuminate\Console\Command;

class SyncMissingDomainOrdersCommand extends Command
{
    protected $signature = 'domain-orders:sync-missing {--invoice= : Limit to a single invoice ID}';

    protected $description = 'Create missing domain orders for paid invoices that have domain lines without domain_order_id';

    public function handle(ResellerDomainOrderService $orderService, DomainPushService $domainPushService): int
    {
        $invoiceId = $this->option('invoice');

        $query = Invoice::query()
            ->where('status', 'paid')
            ->whereHas('items', function ($items) {
                $items->where(function ($line) {
                    $line->where('product_type', 'Domain')
                        ->orWhereIn('custom_options->type', ['domain_registration', 'domain_transfer']);
                });
            });

        if ($invoiceId) {
            $query->whereKey($invoiceId);
        }

        $totalCreated = 0;
        $totalProcessed = 0;

        $query->orderBy('id')->chunkById(50, function ($invoices) use ($orderService, $domainPushService, &$totalCreated, &$totalProcessed) {
            foreach ($invoices as $invoice) {
                $created = $orderService->ensureOrdersForInvoice($invoice);

                if ($created === 0) {
                    continue;
                }

                $totalCreated += $created;
                $totalProcessed++;

                if ($invoice->isPaid()) {
                    $domainPushService->handlePaidDomainInvoice($invoice->fresh(['items', 'user']));
                }

                $this->line("Invoice #{$invoice->id}: created {$created} domain order(s)");
            }
        });

        $this->info("Created {$totalCreated} domain order(s) across {$totalProcessed} invoice(s).");

        return self::SUCCESS;
    }
}
