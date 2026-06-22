<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PlatformApiTokenService;
use App\Services\ResellerApiTokenService;
use App\Services\ResellerBrandingResolver;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResolveResellerPublicApiTenant
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('currentReseller') && $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($request->bearerToken());

            if ($accessToken instanceof PersonalAccessToken) {
                $user = $accessToken->tokenable;

                if ($user instanceof User && $accessToken->can(ResellerApiTokenService::TOKEN_ABILITY) && $user->is_reseller) {
                    app()->instance('currentReseller', $user);
                    $accessToken->forceFill(['last_used_at' => now()])->save();
                } elseif ($user instanceof User && $accessToken->can(PlatformApiTokenService::TOKEN_ABILITY) && $user->is_admin) {
                    app()->instance('platformPublicApi', true);
                    $accessToken->forceFill(['last_used_at' => now()])->save();
                }
            }
        }

        if (! app()->bound('currentReseller') && ! app()->bound('platformPublicApi')) {
            if ($this->brandingResolver->isPlatformHost($this->brandingResolver->normalizeHost($request->getHost()))) {
                app()->instance('platformPublicApi', true);
            }
        }

        return $next($request);
    }
}
