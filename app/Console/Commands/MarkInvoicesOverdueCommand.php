<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\NotificationService;
use App\Services\ResellerEnforcementService;
use App\Services\ServiceOverdueEnforcementService;
use Illuminate\Support\Facades\Log;

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
        $enforcement = app(ServiceOverdueEnforcementService::class);
        $resellerEnforcement = app(ResellerEnforcementService::class);

        $count = 0;
        $suspended = 0;
        $resellersSuspended = 0;

        foreach ($invoices as $invoice) {
            $invoice->update(['status' => 'overdue']);
            $notificationService->notifyInvoiceOverdue($invoice);
            $count++;

            if ($invoice->type === 'reseller_subscription') {
                $reseller = $invoice->user;

                if ($reseller?->is_reseller && $resellerEnforcement->enforceOverdueSuspension($reseller->fresh())) {
                    $resellersSuspended++;
                    Log::info('Reseller suspended after subscription invoice marked overdue', [
                        'reseller_id' => $reseller->id,
                        'invoice_id' => $invoice->id,
                    ]);
                }

                continue;
            }

            if (! $enforcement->isSuspensionEnabled()) {
                continue;
            }

            foreach ($enforcement->activeServicesForInvoice($invoice) as $service) {
                try {
                    $resellerEnforcement->suspendServiceForEnforcement(
                        $service,
                        ResellerEnforcementService::REASON_INVOICE_OVERDUE
                    );

                    Log::info('Service suspended after invoice marked overdue', [
                        'service_id' => $service->id,
                        'invoice_id' => $invoice->id,
                        'driver' => $service->provisioning_driver_key ?: $service->product?->provisioning_driver_key,
                    ]);

                    $suspended++;
                } catch (\Throwable $e) {
                    Log::error('Failed to suspend service after invoice marked overdue', [
                        'service_id' => $service->id,
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $message = "Marked {$count} invoice(s) as overdue.";

        if ($resellersSuspended > 0) {
            $message .= " Suspended {$resellersSuspended} reseller account(s).";
        }

        if ($suspended > 0) {
            $message .= " Suspended {$suspended} service(s) on DirectAdmin and other drivers.";
        }

        return $message;
    }
}
