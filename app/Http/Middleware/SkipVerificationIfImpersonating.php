<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;

class SkipVerificationIfImpersonating extends EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next, $redirectToRoute = null)
    {
        // If an admin or reseller is impersonating a user, skip email verification
        if (session('impersonating') || session('impersonating_reseller')) {
            return $next($request);
        }

        // Otherwise, use the default email verification check
        return parent::handle($request, $next, $redirectToRoute);
    }
}
