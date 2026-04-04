<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnforceResellerLimits
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        // Only applies to resellers
        if (!$user || !$user->isReseller()) {
            return $next($request);
        }

        // No package at all: redirect to package selection
        if (!$user->hasResellerPackage()) {
            return redirect()
                ->route('reseller.packages.index')
                ->with('warning', 'You must subscribe to a reseller package before managing services or customers.');
        }

        // Over limits: redirect to packages page with upgrade prompt
        if ($user->isOverPackageLimits()) {
            return redirect()
                ->route('reseller.packages.index')
                ->with('limit_exceeded', true)
                ->with('warning', 'You have reached your package limits. Please upgrade to continue adding services or customers.');
        }

        return $next($request);
    }
}
