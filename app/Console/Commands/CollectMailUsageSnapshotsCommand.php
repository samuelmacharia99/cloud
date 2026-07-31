<?php

namespace App\Console\Commands;

use App\Services\Billing\MailUsageSnapshotService;

class CollectMailUsageSnapshotsCommand extends BaseCronCommand
{
    protected $signature = 'cron:collect-mail-usage-snapshots';

    protected $description = 'Snapshot Mailcow mailbox/alias counts for usage billing';

    protected function handleCron(): string
    {
        $count = app(MailUsageSnapshotService::class)->snapshotAllActive();

        return "Snapshotted mail usage for {$count} service(s).";
    }
}
