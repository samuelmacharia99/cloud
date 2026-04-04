<?php

namespace App\Providers;

use App\Models\Email;
use App\Models\SmsLog;
use App\Policies\EmailPolicy;
use App\Policies\SmsLogPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        SmsLog::class => SmsLogPolicy::class,
        Email::class => EmailPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
