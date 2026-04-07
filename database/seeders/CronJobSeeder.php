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
                'name' => 'Generate Invoices',
                'description' => 'Creates renewal invoices for services where next_due_date has arrived.',
                'command' => 'cron:generate-invoices',
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
                'name' => 'Suspend Services',
                'description' => 'Suspends active services with overdue invoices past the grace period.',
                'command' => 'cron:suspend-services',
                'schedule' => '0 4 * * *',
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
                'name' => 'Check Node Health',
                'description' => 'Sets monitored nodes to offline/degraded based on last heartbeat.',
                'command' => 'cron:check-node-health',
                'schedule' => '*/5 * * * *',
                'enabled' => true,
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
