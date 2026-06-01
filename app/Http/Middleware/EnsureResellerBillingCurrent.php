<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureResellerBillingCurrent
{
    /**
     * Block operational routes when reseller account is suspended for billing.
     * Payment, package, wallet, and own invoices remain accessible.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (! $user || ! $user->isReseller()) {
            return $next($request);
        }

        if (! $user->isResellerSuspended()) {
            return $next($request);
        }

        if ($request->routeIs(
            'reseller.packages.*',
            'reseller.payment.*',
            'reseller.invoices.*',
            'reseller.wallet.*',
            'dashboard',
        )) {
            return $next($request);
        }

        return redirect()
            ->route('reseller.packages.index')
            ->with('error', 'Your reseller account is suspended due to an overdue package subscription. Pay your renewal invoice to restore access.');
    }
}
