<?php

namespace App\Http\Middleware;

use App\Services\RegistrationGuardService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        app(RegistrationGuardService::class)->enforceRateLimits($request);

        return $next($request);
    }
}
