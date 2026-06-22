<?php

namespace App\Http\Middleware;

use App\Services\PublicWebsiteApiContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResellerPublicApiCors
{
    public function __construct(
        private PublicWebsiteApiContext $api,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);

            return $this->applyCorsHeaders($response, $origin);
        }

        $response = $next($request);

        return $this->applyCorsHeaders($response, $origin);
    }

    private function applyCorsHeaders(Response $response, ?string $origin): Response
    {
        if ($origin && $this->api->originAllowed($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
