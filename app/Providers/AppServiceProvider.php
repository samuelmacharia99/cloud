<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use App\Models\CronJob;
use Illuminate\Support\Facades\View;
use App\Models\User;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('wallet-service', function () {
            return new \App\Services\ResellerWalletService($this->app['db']);
        });

        $this->app->singleton('domain-push-service', function () {
            return new \App\Services\DomainPushService(
                $this->app['db'],
                $this->app['wallet-service'],
                app(\App\Services\WalletNotificationService::class)
            );
        });

        $this->app->singleton(\App\Services\WalletNotificationService::class);
        $this->app->singleton(\App\Services\ResellerDomainTransferService::class);
    }

    public function boot(): void
    {
        // Load mail settings from database
        $this->loadMailConfigFromDatabase();

        // Share reseller branding with all views
        View::composer('*', function ($view) {
            if (!auth()->check()) {
                return;
            }

            $user = auth()->user();
            $reseller = null;

            if ($user->is_reseller) {
                $reseller = $user;
            } elseif ($user->reseller_id) {
                $reseller = User::find($user->reseller_id);
            }

            $branding = $reseller ? ($reseller->settings['branding'] ?? []) : [];

            $view->with('resellerBranding', array_merge([
                'company_name' => config('app.name'),
                'custom_domain' => null,
                'logo_url' => null,
                'favicon_url' => null,
            ], $branding));

            // Share wallet balance for resellers
            if ($user->is_reseller) {
                $wallet = $user->wallet;
                $view->with('walletBalance', $wallet?->balance ?? 0);
                $view->with('walletIsLow', $wallet?->isLowBalance() ?? false);
                $view->with('walletCurrency', $wallet?->currency ?? 'KES');
            }
        });

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
            $smtpEncryption = \App\Models\Setting::getValue('smtp_encryption', 'tls');
            $mailFromName = \App\Models\Setting::getValue('mail_from_name');
            $mailFromAddress = \App\Models\Setting::getValue('mail_from_address');

            // Override mail config if database values exist
            if ($smtpHost) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $smtpHost,
                    'mail.mailers.smtp.port' => $smtpPort ?: 587,
                    'mail.mailers.smtp.encryption' => $smtpEncryption ?: 'tls',
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
