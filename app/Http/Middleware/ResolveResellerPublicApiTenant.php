<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ResellerApiTokenService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResolveResellerPublicApiTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('currentReseller') && $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($request->bearerToken());

            if ($accessToken?->can(ResellerApiTokenService::TOKEN_ABILITY)) {
                $user = $accessToken->tokenable;

                if ($user instanceof User && $user->is_reseller) {
                    app()->instance('currentReseller', $user);
                    $accessToken->forceFill(['last_used_at' => now()])->save();
                }
            }
        }

        return $next($request);
    }
}
