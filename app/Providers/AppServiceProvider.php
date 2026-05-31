<?php

namespace App\Providers;

use App\Models\CronJob;
use App\Models\Setting;
use App\Services\DomainPushService;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerDomainTransferService;
use App\Services\ResellerMailService;
use App\Services\ResellerWalletService;
use App\Services\TalksasaSmsService;
use App\Services\WalletNotificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('wallet-service', function () {
            return new ResellerWalletService($this->app['db']);
        });

        $this->app->singleton('domain-push-service', function () {
            return new DomainPushService(
                $this->app['db'],
                $this->app['wallet-service'],
                app(WalletNotificationService::class)
            );
        });

        $this->app->singleton(WalletNotificationService::class);
        $this->app->singleton(ResellerDomainTransferService::class);
        $this->app->singleton(ResellerBrandingResolver::class);
        $this->app->singleton(ResellerMailService::class);
        $this->app->singleton(TalksasaSmsService::class);
        $this->app->alias(TalksasaSmsService::class, 'talksasa-sms-service');
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        // Load mail settings from database
        $this->loadMailConfigFromDatabase();

        $this->shareResellerWalletData();

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
                Log::debug('Cron jobs not loaded: '.$e->getMessage());
            }
        });
    }

    private function shareResellerWalletData(): void
    {
        View::composer(['layouts.reseller', 'dashboard.reseller'], function ($view) {
            if (! auth()->check() || ! auth()->user()->is_reseller) {
                return;
            }

            $wallet = auth()->user()->wallet;
            $view->with('walletBalance', $wallet?->balance ?? 0);
            $view->with('walletIsLow', $wallet?->isLowBalance() ?? false);
            $view->with('walletCurrency', $wallet?->currency ?? 'KES');
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('laravel-container-actions', function (Request $request) {
            $service = $request->route('service');
            $serviceId = is_object($service) ? $service->id : $service;

            return Limit::perMinute(20)->by(($request->user()?->id ?? $request->ip()).'|service:'.$serviceId);
        });
    }

    /**
     * Load mail configuration from database settings
     */
    private function loadMailConfigFromDatabase(): void
    {
        try {
            if (! \Schema::hasTable('settings')) {
                return;
            }

            $smtpHost = Setting::getValue('smtp_host');
            $smtpPort = Setting::getValue('smtp_port');
            $smtpUser = Setting::getValue('smtp_user');
            $smtpPassword = Setting::getValue('smtp_password');
            $smtpEncryption = Setting::getValue('smtp_encryption', 'tls');
            $mailFromName = Setting::getValue('mail_from_name');
            $mailFromAddress = Setting::getValue('mail_from_address');

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
