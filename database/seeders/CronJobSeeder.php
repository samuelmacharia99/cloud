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
                'name' => 'Terminate Services',
                'description' => 'Terminates services that have been suspended past the terminate_after_days threshold.',
                'command' => 'cron:terminate-services',
                'schedule' => '0 5 * * *',
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
        ];

        foreach ($jobs as $job) {
            CronJob::updateOrCreate(
                ['command' => $job['command']],
                $job
            );
        }
    }
}
