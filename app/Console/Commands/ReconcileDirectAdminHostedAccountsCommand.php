<?php

namespace App\Console\Commands;

use App\Services\ResellerHostedAccountReconciliationService;

class ReconcileDirectAdminHostedAccountsCommand extends BaseCronCommand
{
    protected $signature = 'cron:reconcile-directadmin-hosted-accounts {--no-notify : Skip reseller notifications}';

    protected $description = 'Reconcile DirectAdmin hosted users with platform services and heal package drift';

    protected function handleCron(): string
    {
        $summary = app(ResellerHostedAccountReconciliationService::class)
            ->runScheduledReconciliation(notify: ! $this->option('no-notify'));

        return sprintf(
            'Checked %d reseller(s): %d unlinked, %d package drift healed, %d missing on DA, %d status drift, %d notifications.',
            $summary['resellers_checked'],
            $summary['unlinked_total'],
            $summary['package_drift'],
            $summary['missing_on_da'],
            $summary['status_drift'],
            $summary['notifications_sent'],
        );
    }
}
