<?php

namespace App\Http\Middleware;

use App\Services\PublicWebsiteApiContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResellerPublicApi
{
    public function __construct(
        private PublicWebsiteApiContext $api,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->api->isReseller() && ! $this->api->isPlatform()) {
            return response()->json([
                'success' => false,
                'message' => 'This API is only available on an enabled platform or reseller branding domain, or with a valid API token.',
            ], 404);
        }

        if (! $this->api->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Public website API is not enabled.',
            ], 403);
        }

        return $next($request);
    }
}
