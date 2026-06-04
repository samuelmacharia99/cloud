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
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Apply CSP in both development and production
        // Allow external resources: fonts.bunny.net, Google reCAPTCHA, CDN
        $csp = "default-src 'self'; ".
               "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://cdn.jsdelivr.net; ".
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://www.google.com https://www.gstatic.com; ".
               "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:; ".
               'frame-src https://www.google.com; '.
               "img-src 'self' data: https:; ".
               "connect-src 'self' https: wss:; ";

        // Add stricter CSP rules for production
        if (! app()->environment('local', 'development')) {
            $csp .= "frame-ancestors 'none'; ".
                   "base-uri 'self'; ".
                   "form-action 'self';";
        }

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'X-Robots-Tag' => 'noai, noimageai',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        if (! app()->environment('local', 'development')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
