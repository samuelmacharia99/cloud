<?php

namespace App\Providers;

use App\Models\Email;
use App\Models\Payment;
use App\Models\SmsLog;
use App\Models\Setting;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\EmailPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ResellerPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SettingPolicy;
use App\Policies\SmsLogPolicy;
use App\Policies\TicketPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        SmsLog::class => SmsLogPolicy::class,
        Email::class => EmailPolicy::class,
        Payment::class => PaymentPolicy::class,
        User::class => ResellerPolicy::class,
        Setting::class => SettingPolicy::class,
        Service::class => ServicePolicy::class,
        Ticket::class => TicketPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
