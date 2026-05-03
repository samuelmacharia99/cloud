<?php

namespace Database\Seeders;

use App\Models\CronJob;
use Illuminate\Database\Seeder;

class WalletCronJobsSeeder extends Seeder
{
    public function run(): void
    {
        $jobs = [
            [
                'name' => 'Process Queued Domain Orders',
                'command' => 'cron:process-queued-domain-orders',
                'schedule' => '0 * * * *',
                'description' => 'Process queued domain orders when resellers have sufficient wallet funds',
                'enabled' => true,
            ],
            [
                'name' => 'Expire Queued Domain Orders',
                'command' => 'cron:expire-queued-domain-orders',
                'schedule' => '0 0 * * *',
                'description' => 'Expire queued domain orders that have passed their 10-day expiration window',
                'enabled' => true,
            ],
            [
                'name' => 'Wallet Low Balance Alerts',
                'command' => 'cron:wallet-low-balance-alerts',
                'schedule' => '0 * * * *',
                'description' => 'Send SMS/email alerts to resellers with low wallet balance (24h repeat guard)',
                'enabled' => true,
            ],
        ];

        foreach ($jobs as $job) {
            CronJob::updateOrCreate(
                ['command' => $job['command']],
                $job
            );
        }
    }
}
