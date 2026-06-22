<?php

namespace App\Http\Middleware;

use App\Services\ResellerPublicApiService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResellerPublicApiCors
{
    public function __construct(
        private ResellerPublicApiService $publicApi,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('currentReseller')) {
            return $next($request);
        }

        $reseller = app('currentReseller');
        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);

            return $this->applyCorsHeaders($response, $reseller, $origin);
        }

        $response = $next($request);

        return $this->applyCorsHeaders($response, $reseller, $origin);
    }

    private function applyCorsHeaders(Response $response, $reseller, ?string $origin): Response
    {
        if ($origin && $this->publicApi->originAllowed($reseller, $origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
