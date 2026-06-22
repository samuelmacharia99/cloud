<?php

namespace App\Http\Middleware;

use App\Services\ResellerPublicApiService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResellerPublicApi
{
    public function __construct(
        private ResellerPublicApiService $publicApi,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('currentReseller')) {
            return response()->json([
                'success' => false,
                'message' => 'This API is only available on your branding domain or with a valid API token.',
            ], 404);
        }

        $reseller = app('currentReseller');

        if (! $this->publicApi->isEnabled($reseller)) {
            return response()->json([
                'success' => false,
                'message' => 'Public website API is not enabled for this reseller.',
            ], 403);
        }

        return $next($request);
    }
}
