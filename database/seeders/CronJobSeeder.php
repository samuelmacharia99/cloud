<?php

namespace Database\Seeders;

use App\Models\CronJob;
use Illuminate\Database\Seeder;

class CronJobSeeder extends Seeder
{
    public function run(): void
    {
        $jobs = [
            [
                'name' => 'Generate Service Invoices',
                'description' => 'Creates renewal invoices for services (monthly: 10 days prior, other cycles: 30 days prior).',
                'command' => 'cron:generate-invoices',
                'schedule' => '0 2 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Generate Domain Invoices',
                'description' => 'Creates renewal invoices for domains before expiry (default: 30 days prior).',
                'command' => 'cron:generate-domain-invoices',
                'schedule' => '0 2 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Mark Invoices Overdue',
                'description' => 'Transitions unpaid invoices past their due date to overdue status.',
                'command' => 'cron:mark-invoices-overdue',
                'schedule' => '0 3 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Suspend Services on Due Date',
                'description' => 'Suspends active services when their invoice due date arrives.',
                'command' => 'cron:suspend-on-due',
                'schedule' => '0 8 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Suspend Services',
                'description' => 'Suspends active services with overdue invoices past the grace period.',
                'command' => 'cron:suspend-services',
                'schedule' => '0 4 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Unsuspend Paid Services',
                'description' => 'Unsuspends services whose invoices have been paid.',
                'command' => 'cron:unsuspend-paid-services',
                'schedule' => '*/10 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Enforce Disk Quotas',
                'description' => 'Suspends DirectAdmin accounts over disk quota and restores them when usage drops (includes reseller customers).',
                'command' => 'cron:enforce-disk-quotas',
                'schedule' => '0 */6 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Terminate Services',
                'description' => 'Terminates services whose invoice has remained unpaid for the configured number of months.',
                'command' => 'cron:terminate-services',
                'schedule' => '0 5 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Provision Pending DirectAdmin',
                'description' => 'Retries provisioning for paid shared hosting services stuck in pending or failed status.',
                'command' => 'directadmin:provision-pending',
                'schedule' => '*/15 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Provision Pending Containers',
                'description' => 'Auto-provisions container services with paid invoices stuck in pending status.',
                'command' => 'cron:provision-pending-containers',
                'schedule' => '*/10 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Send Invoice Reminders',
                'description' => 'Logs reminder actions for invoices due in 7 days and 1 day.',
                'command' => 'cron:send-invoice-reminders',
                'schedule' => '0 9 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Check Domain Expiry',
                'description' => 'Marks expired domains and logs warnings for domains expiring soon.',
                'command' => 'cron:check-domain-expiry',
                'schedule' => '0 6 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Poll Node Health',
                'description' => 'Actively polls node health via SSH every 2 minutes (alternative to heartbeats).',
                'command' => 'cron:poll-node-health',
                'schedule' => '*/2 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Check Node Health',
                'description' => 'Sets monitored nodes to offline/degraded based on last heartbeat (disabled if using polling).',
                'command' => 'cron:check-node-health',
                'schedule' => '*/5 * * * *',
                'enabled' => false,
            ],
            [
                'name' => 'Cleanup Monitoring Data',
                'description' => 'Deletes node monitoring records and old cron logs older than retention period.',
                'command' => 'cron:cleanup-monitoring',
                'schedule' => '0 1 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Collect Container Metrics',
                'description' => 'Collects CPU, memory, and I/O metrics from running Docker containers via docker stats.',
                'command' => 'cron:collect-container-metrics',
                'schedule' => '*/5 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Auto-Restart Containers',
                'description' => 'Monitors and auto-restarts failed containers with auto-restart enabled.',
                'command' => 'cron:auto-restart-containers',
                'schedule' => '*/5 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Backup Containers',
                'description' => 'Creates scheduled backups for active container services not backed up in 24 hours.',
                'command' => 'cron:backup-containers',
                'schedule' => '30 3 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Collect Reseller Disk Usage',
                'description' => 'Records daily DirectAdmin and container disk usage per reseller for pool billing.',
                'command' => 'cron:collect-reseller-disk-usage',
                'schedule' => '15 3 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Sync Service Live Status',
                'description' => 'Probes DirectAdmin accounts and Docker containers to detect billing vs infrastructure status drift.',
                'command' => 'cron:sync-service-live-status',
                'schedule' => '*/15 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Provision Reseller SSL',
                'description' => 'Automatically issues and renews Let\'s Encrypt SSL for reseller custom domains.',
                'command' => 'cron:provision-reseller-ssl',
                'schedule' => '*/15 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Generate Reseller Package Invoices',
                'description' => 'Creates renewal invoices for reseller packages 10 days before expiry.',
                'command' => 'cron:generate-reseller-invoices',
                'schedule' => '0 2 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Suspend Resellers',
                'description' => 'Suspends resellers with overdue or expired package subscriptions; optionally cascades to DirectAdmin.',
                'command' => 'cron:suspend-resellers',
                'schedule' => '30 4 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Unsuspend Resellers',
                'description' => 'Restores resellers with current package billing and unsuspends cascade-suspended services.',
                'command' => 'cron:unsuspend-resellers',
                'schedule' => '*/15 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Enforce Reseller Package Limits',
                'description' => 'Suspends excess active services when resellers exceed package service slot limits on DirectAdmin.',
                'command' => 'cron:enforce-reseller-package-limits',
                'schedule' => '0 6 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Renew SSL Certificates',
                'description' => 'Renews expiring SSL certificates for container domains using certbot.',
                'command' => 'cron:renew-ssl-certificates',
                'schedule' => '0 2 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Update Exchange Rates',
                'description' => 'Fetches latest currency exchange rates from global API and updates database.',
                'command' => 'cron:update-exchange-rates',
                'schedule' => '0 0 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Process Queued Domain Orders',
                'description' => 'Process queued domain orders when resellers have sufficient wallet funds.',
                'command' => 'cron:process-queued-domain-orders',
                'schedule' => '*/30 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Expire Queued Domain Orders',
                'description' => 'Expire queued domain orders that have passed their 10-day expiration window.',
                'command' => 'cron:expire-queued-domain-orders',
                'schedule' => '15 0 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Expire Domain Renewal Orders',
                'description' => 'Expire pending domain renewal orders past their payment window.',
                'command' => 'cron:expire-domain-renewal-orders',
                'schedule' => '45 0 * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Wallet Low Balance Alerts',
                'description' => 'Send SMS/email alerts to resellers with low wallet balance.',
                'command' => 'cron:wallet-low-balance-alerts',
                'schedule' => '0 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Cron Health Check',
                'description' => 'Detects hung cron jobs and repeated failures; alerts admins.',
                'command' => 'cron:check-health',
                'schedule' => '*/5 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Terminal Session Cleanup',
                'description' => 'Removes expired container terminal sessions.',
                'command' => 'terminal:cleanup',
                'schedule' => '*/5 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Registrar Domain Status Sync',
                'description' => 'Syncs Openprovider domain statuses and completes pending orders when active.',
                'command' => 'registrar:sync-domains',
                'schedule' => '*/15 * * * *',
                'enabled' => true,
            ],
            [
                'name' => 'Telegram Log Monitor',
                'description' => 'Scans Laravel logs and sends new errors to Telegram.',
                'command' => 'telegram:monitor-logs',
                'schedule' => '* * * * *',
                'enabled' => true,
            ],
        ];

        foreach ($jobs as $job) {
            $model = CronJob::updateOrCreate(
                ['command' => $job['command']],
                $job
            );

            $model->refreshNextRunAt();
        }
    }
}
