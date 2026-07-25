<?php

namespace App\Http\Middleware;

use App\Services\ResellerEnforcementService;
use Closure;
use Illuminate\Http\Request;

class EnforceResellerLimits
{
    /**
     * Capacity-consuming actions blocked when over package limits.
     * Catalog maintenance and domain wholesale ops remain available.
     *
     * @var list<string>
     */
    private const ALLOWED_WHEN_OVER_LIMIT = [
        'reseller.catalog.index',
        'reseller.catalog.show',
        'reseller.catalog.edit',
        'reseller.catalog.update',
        'reseller.catalog.destroy',
        'reseller.domains.index',
        'reseller.domains.show',
        'reseller.domains.nameservers',
        'reseller.domains.transfer',
        'reseller.domains.renew',
        'reseller.domains.destroy',
        'reseller.domains.pricing',
        'reseller.domains.pricing.update',
        'reseller.domains.pricing.api',
        'reseller.domains.check',
        'reseller.domain-orders.index',
        'reseller.domain-orders.push',
        'reseller.domain-orders.retry',
        'reseller.domain-orders.cancel',
        'reseller.domain-orders.destroy',
        'reseller.cart.index',
        'reseller.cart.context',
        'reseller.cart.add',
        'reseller.cart.transfer',
        'reseller.cart.nameservers',
        'reseller.cart.remove',
        'reseller.cart.clear',
        'reseller.checkout.show',
        'reseller.checkout.process',
        'reseller.customers.index',
        'reseller.customers.show',
        'reseller.customers.edit',
        'reseller.customers.update',
        'reseller.customers.destroy',
        'reseller.customers.impersonate',
        'reseller.exit-impersonation',
        'reseller.services.index',
        'reseller.services.show',
    ];

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

        $enforcement = app(ResellerEnforcementService::class);
        $enforcement->enforceOverdueSuspension($user->fresh());
        $user->refresh();

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

        // Over limits: allow catalog/domain maintenance; block new capacity-consuming actions
        if ($user->isOverPackageLimits()) {
            $routeName = $request->route()?->getName();

            if (! in_array($routeName, self::ALLOWED_WHEN_OVER_LIMIT, true)) {
                return redirect()
                    ->route('reseller.packages.index')
                    ->with('limit_exceeded', true)
                    ->with('warning', 'You have reached your package limits. Please upgrade to continue adding services or customers.');
            }
        }

        return $next($request);
    }
}
