<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use App\Models\CronJob;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Load mail settings from database
        $this->loadMailConfigFromDatabase();

        // Register dynamic cron job scheduler
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            try {
                $jobs = CronJob::where('enabled', true)->get();

                foreach ($jobs as $job) {
                    $schedule->command($job->command)
                        ->cron($job->schedule)
                        ->withoutOverlapping()
                        ->runInBackground();
                }
            } catch (\Exception $e) {
                // Silently fail on fresh install when cron_jobs table doesn't exist yet
                \Illuminate\Support\Facades\Log::debug('Cron jobs not loaded: ' . $e->getMessage());
            }
        });
    }

    /**
     * Load mail configuration from database settings
     */
    private function loadMailConfigFromDatabase(): void
    {
        try {
            if (!\Schema::hasTable('settings')) {
                return;
            }

            $smtpHost = \App\Models\Setting::getValue('smtp_host');
            $smtpPort = \App\Models\Setting::getValue('smtp_port');
            $smtpUser = \App\Models\Setting::getValue('smtp_user');
            $smtpPassword = \App\Models\Setting::getValue('smtp_password');
            $mailFromName = \App\Models\Setting::getValue('mail_from_name');
            $mailFromAddress = \App\Models\Setting::getValue('mail_from_address');

            // Override mail config if database values exist
            if ($smtpHost) {
                config([
                    'mail.mailers.smtp.host' => $smtpHost,
                    'mail.mailers.smtp.port' => $smtpPort ?: 2525,
                    'mail.mailers.smtp.username' => $smtpUser,
                    'mail.mailers.smtp.password' => $smtpPassword,
                ]);
            }

            if ($mailFromName || $mailFromAddress) {
                config([
                    'mail.from.name' => $mailFromName ?: config('mail.from.name'),
                    'mail.from.address' => $mailFromAddress ?: config('mail.from.address'),
                ]);
            }
        } catch (\Exception $e) {
            // Database may not be ready yet, skip
        }
    }
}
