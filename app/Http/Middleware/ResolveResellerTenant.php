<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ResellerBrandingResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveResellerTenant
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $hostReseller = $this->brandingResolver->resolveFromHost($request->getHost());

        if ($hostReseller) {
            app()->instance('currentReseller', $hostReseller);
        }

        $branding = $this->resolveBranding($request, $hostReseller);
        view()->share('resellerBranding', $branding);
        view()->share('isWhiteLabelHost', (bool) $hostReseller);

        return $next($request);
    }

    private function resolveBranding(Request $request, $hostReseller): array
    {
        if (auth()->check()) {
            $user = auth()->user();

            if ($user->is_reseller) {
                return $this->brandingResolver->forReseller($user);
            }

            if ($user->reseller_id) {
                return $this->brandingResolver->forCustomer($user);
            }
        }

        if ($hostReseller) {
            return $this->brandingResolver->forReseller($hostReseller);
        }

        if (session()->has('registration_reseller_id')) {
            $inviteReseller = User::find(session('registration_reseller_id'));
            if ($inviteReseller?->is_reseller) {
                return $this->brandingResolver->forReseller($inviteReseller);
            }
        }

        return $this->brandingResolver->defaults();
    }
}
