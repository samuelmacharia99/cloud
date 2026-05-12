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

        // Apply CSP in both development and production
        // Allow external resources: fonts.bunny.net, Google reCAPTCHA, CDN
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://www.google.com https://www.gstatic.com; " .
               "style-src 'self' 'unsafe-inline' https://fonts.bunny.net; " .
               "font-src https://fonts.bunny.net; " .
               "img-src 'self' data: https:; " .
               "connect-src 'self' https: wss:; " .
               "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/; ";

        // Add stricter CSP rules for production
        if (!app()->environment('local', 'development')) {
            $csp .= "frame-ancestors 'none'; " .
                   "base-uri 'self'; " .
                   "form-action 'self';";
        }

        $response->header('Content-Security-Policy', $csp);

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
