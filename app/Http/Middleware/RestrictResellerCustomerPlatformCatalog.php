<?php

namespace App\Http\Middleware;

use App\Services\ResellerCustomerCatalogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictResellerCustomerPlatformCatalog
{
    public function __construct(
        private ResellerCustomerCatalogService $catalogService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->catalogService->isResellerCustomer($user)) {
            $this->catalogService->sanitizeSessionCart($user);

            $routeName = $request->route()?->getName() ?? '';

            if ($this->catalogService->isPlatformCatalogRoute($routeName)) {
                return redirect()
                    ->route('customer.reseller-catalog.index')
                    ->with('info', 'Order products and services from your reseller catalog.');
            }
        }

        return $next($request);
    }
}
