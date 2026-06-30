<?php

namespace App\Providers;

use App\Listeners\BlockDestructiveProductionCommands;
use App\Models\Setting;
use App\Services\AdminAttentionService;
use App\Services\Billing\InvoiceCurrencyService;
use App\Services\DomainPushService;
use App\Services\EmailDeliveryService;
use App\Services\EmailRateLimiter;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Services\ResellerAnalyticsService;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerDomainTransferService;
use App\Services\ResellerMailService;
use App\Services\ResellerWalletService;
use App\Services\TalksasaSmsService;
use App\Services\UserCurrencyService;
use App\Services\WalletNotificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
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
        $this->app->singleton(EmailDeliveryService::class);
        $this->app->singleton(EmailRateLimiter::class);
        $this->app->singleton(NotificationPreferenceService::class);
        $this->app->singleton(NotificationService::class);
        $this->app->singleton(ResellerDomainTransferService::class);
        $this->app->singleton(ResellerBrandingResolver::class);
        $this->app->singleton(ResellerMailService::class);
        $this->app->singleton(UserCurrencyService::class);
        $this->app->singleton(InvoiceCurrencyService::class);
        $this->app->singleton(TalksasaSmsService::class);
        $this->app->alias(TalksasaSmsService::class, 'talksasa-sms-service');
    }

    public function boot(): void
    {
        Event::listen(CommandStarting::class, BlockDestructiveProductionCommands::class);

        $this->configureRateLimiting();

        // Load mail settings from database
        $this->loadMailConfigFromDatabase();

        $this->shareResellerWalletData();
        $this->shareAdminAttentionData();
    }

    private function shareAdminAttentionData(): void
    {
        View::composer(['layouts.admin', 'dashboard.admin'], function ($view) {
            if (! auth()->check() || ! auth()->user()->isAdmin()) {
                return;
            }

            $view->with('adminAttention', app(AdminAttentionService::class)->snapshot(auth()->user()));
        });
    }

    private function shareResellerWalletData(): void
    {
        View::composer(['layouts.reseller', 'dashboard.reseller', 'reseller.*'], function ($view) {
            if (! auth()->check() || ! auth()->user()->is_reseller) {
                return;
            }

            $reseller = auth()->user();
            $wallet = $reseller->wallet;
            $view->with('resellerCurrency', 'KSH');
            $view->with('walletBalance', $wallet?->balance ?? 0);
            $view->with('walletIsLow', $wallet?->isLowBalance() ?? false);
            $view->with('walletCurrency', 'KSH');
            $view->with('resellerBillingHealth', app(ResellerAnalyticsService::class)->billingHealthSnapshot($reseller));
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('reseller-public-api', function (Request $request) {
            if (app()->bound('currentReseller')) {
                $key = 'reseller:'.app('currentReseller')->id;
            } elseif (app()->bound('platformPublicApi')) {
                $key = 'platform';
            } else {
                $key = 'unknown';
            }

            return Limit::perMinute(30)->by($request->ip().'|'.$key);
        });

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
            $mailReplyToName = Setting::getValue('mail_reply_to_name');
            $mailReplyToAddress = Setting::getValue('mail_reply_to_address', Setting::getValue('support_email'));

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

            if ($mailReplyToAddress) {
                Mail::alwaysReplyTo($mailReplyToAddress, $mailReplyToName ?: ($mailFromName ?: config('mail.from.name')));
            }
        } catch (\Exception $e) {
            // Database may not be ready yet, skip
        }
    }
}
