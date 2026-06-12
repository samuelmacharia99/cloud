<?php

namespace App\Providers;

use App\Models\Domain;
use App\Models\Email;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\DomainPolicy;
use App\Policies\EmailPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\RegistrarPolicy;
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
        Domain::class => DomainPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Order::class => OrderPolicy::class,
        Payment::class => PaymentPolicy::class,
        Registrar::class => RegistrarPolicy::class,
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
