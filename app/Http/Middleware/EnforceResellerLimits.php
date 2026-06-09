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
        if (! $user || ! $user->isReseller()) {
            return $next($request);
        }

        if ($user->isResellerSuspended()) {
            return redirect()
                ->route('reseller.packages.index')
                ->with('error', 'Your reseller account is suspended. Pay your package subscription invoice to continue.');
        }

        // No package at all: redirect to package selection
        if (! $user->hasResellerPackage()) {
            return redirect()
                ->route('reseller.packages.index')
                ->with('warning', 'You must subscribe to a reseller package before managing services or customers.');
        }

        // Over limits: allow catalog maintenance; block new capacity-consuming actions
        if ($user->isOverPackageLimits()) {
            $allowedWhenOverLimit = [
                'reseller.catalog.index',
                'reseller.catalog.show',
                'reseller.catalog.edit',
                'reseller.catalog.update',
                'reseller.catalog.destroy',
            ];

            if (! in_array($request->route()?->getName(), $allowedWhenOverLimit, true)) {
                return redirect()
                    ->route('reseller.packages.index')
                    ->with('limit_exceeded', true)
                    ->with('warning', 'You have reached your package limits. Please upgrade to continue adding services or customers.');
            }
        }

        return $next($request);
    }
}
