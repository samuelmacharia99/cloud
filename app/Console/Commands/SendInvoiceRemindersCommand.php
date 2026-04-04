<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class SendInvoiceRemindersCommand extends BaseCronCommand
{
    protected $signature = 'cron:send-invoice-reminders';
    protected $description = 'Logs reminder actions for invoices due in 7 days and 1 day';

    protected function handleCron(): string
    {
        $reminders = [];
        $notificationService = app(NotificationService::class);

        foreach ([7, 1] as $days) {
            $target = now()->addDays($days)->toDateString();
            $invoices = Invoice::with('user')
                ->where('status', 'unpaid')
                ->where('due_date', $target)
                ->get();

            foreach ($invoices as $invoice) {
                Log::info("REMINDER: Invoice {$invoice->invoice_number} due in {$days} day(s) for user ID {$invoice->user_id}");
                $notificationService->notifyInvoiceReminder($invoice, $days);
                $reminders[] = "{$invoice->invoice_number} ({$days}d)";
            }
        }

        $total = count($reminders);
        return $total > 0
            ? "Sent {$total} reminder(s): " . implode(', ', $reminders)
            : "No invoices require reminders today.";
    }
}
