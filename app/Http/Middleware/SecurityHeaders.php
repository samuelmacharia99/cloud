<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip CSP in development (Vite dev server causes issues with CSP)
        // CSP is critical in production for security
        if (!app()->environment('local', 'development')) {
            // Get security headers from config for production
            $headers = config('security.headers', []);

            // Apply each security header (except CSP, we handle it separately)
            foreach ($headers as $header => $value) {
                if ($header !== 'Content-Security-Policy') {
                    $response->header($header, $value);
                }
            }

            // Strict CSP for production
            $strictCsp = "default-src 'self'; " .
                        "script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; " .
                        "style-src 'self' 'unsafe-inline' fonts.bunny.net; " .
                        "font-src fonts.bunny.net; " .
                        "img-src 'self' data: https:; " .
                        "connect-src 'self' https:; " .
                        "frame-ancestors 'none'; " .
                        "base-uri 'self'; " .
                        "form-action 'self';";

            $response->header('Content-Security-Policy', $strictCsp);
        }

        // Other security headers (always apply, safe in both dev and production)
        $response->header('X-Frame-Options', 'DENY');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-XSS-Protection', '1; mode=block');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->header('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->header('X-Robots-Tag', 'noai, noimageai');

        // Only enforce HSTS in production (avoid mixed content issues in dev)
        if (!app()->environment('local', 'development')) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        $response->header('X-Permitted-Cross-Domain-Policies', 'none');

        return $response;
    }
}
