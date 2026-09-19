<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;

class SkipVerificationIfImpersonating extends EnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        // If an admin or reseller is viewing through this account, skip email verification
        if (app(ImpersonationService::class)->isImpersonating()) {
            return $next($request);
        }

        // Otherwise, use the default email verification check
        return parent::handle($request, $next, $redirectToRoute);
    }
}
