<?php

namespace App\Console\Commands;

use App\Services\PaymentGateway\MpesaReconciliationService;

class ReconcilePendingMpesaPaymentsCommand extends BaseCronCommand
{
    protected $signature = 'cron:reconcile-mpesa-payments';

    protected $description = 'Re-queries pending M-Pesa STK payments and settles invoices where payment completed but invoice is unpaid';

    protected function handleCron(): string
    {
        $service = app(MpesaReconciliationService::class);

        $stkStats = $service->reconcilePendingStkPayments();
        $orphanStats = $service->settleOrphanedCompletedPayments();

        return sprintf(
            'STK query: %d checked, %d completed, %d failed, %d still pending, %d errors. Orphaned settlements: %d found, %d settled, %d errors.',
            $stkStats['queried'],
            $stkStats['completed'],
            $stkStats['failed'],
            $stkStats['still_pending'],
            $stkStats['errors'],
            $orphanStats['found'],
            $orphanStats['settled'],
            $orphanStats['errors'],
        );
    }
}
