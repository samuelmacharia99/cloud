<?php

namespace App\Console\Commands;

use App\Services\Provisioning\HetznerStorageBoxClient;
use App\Services\Provisioning\StorageBoxRetentionService;

class PurgeStorageBoxBackupsCommand extends BaseCronCommand
{
    protected $signature = 'cron:purge-storage-box-backups';

    protected $description = 'Delete container backup archives on Hetzner Storage Box older than the configured retention period';

    protected function handleCron(): string
    {
        $retention = app(StorageBoxRetentionService::class);
        $hetzner = app(HetznerStorageBoxClient::class);

        if (! $hetzner->isConfigured()) {
            return 'Skipped: Hetzner Storage Box is not configured.';
        }

        if (! $retention->autoPurgeEnabled()) {
            return 'Skipped: automatic Storage Box retention purge is disabled in Provisioning settings.';
        }

        $days = $retention->retentionDays();
        $eligible = $retention->eligibleBackupCount($days);

        if ($eligible === 0) {
            return "No Storage Box backups older than {$days} day(s).";
        }

        $result = $retention->purgeOlderThan($days);
        $hetzner->clearDiskUsageCache();

        $message = "Purged {$result['purged_count']} Storage Box backup archive(s) older than {$days} day(s), freeing ".formatBytes((int) $result['freed_bytes']).'.';

        if ($result['errors'] !== []) {
            $message .= ' '.count($result['errors']).' deletion(s) failed.';
        }

        return $message;
    }
}
