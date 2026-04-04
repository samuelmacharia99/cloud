<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\NotificationService;

class MarkInvoicesOverdueCommand extends BaseCronCommand
{
    protected $signature = 'cron:mark-invoices-overdue';
    protected $description = 'Transitions unpaid invoices past their due date to overdue status';

    protected function handleCron(): string
    {
        $invoices = Invoice::where('status', 'unpaid')
            ->where('due_date', '<', now()->toDateString())
            ->get();

        $notificationService = app(NotificationService::class);
        $count = 0;

        foreach ($invoices as $invoice) {
            $invoice->update(['status' => 'overdue']);
            $notificationService->notifyInvoiceOverdue($invoice);
            $count++;
        }

        return "Marked {$count} invoice(s) as overdue.";
    }
}
