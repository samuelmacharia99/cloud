<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LogActivity
{
    /**
     * Routes that should be logged for activity tracking.
     */
    protected array $loggedRoutes = [
        'profile.update',
        'password.update',
        'profile.destroy',
        'admin.products.*',
        'admin.customers.*',
        'admin.invoices.*',
        'admin.services.*',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Log activity if enabled and route matches
        if (config('security.audit.enabled')) {
            $this->logActivityIfMatches($request, $response);
        }

        return $response;
    }

    /**
     * Log activity if route matches logged routes.
     */
    private function logActivityIfMatches(Request $request, Response $response): void
    {
        $routeName = $request->route()?->getName();
        $user = Auth::user();

        if (!$routeName || !$this->shouldLog($routeName)) {
            return;
        }

        $logData = [
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'route' => $routeName,
            'method' => $request->getMethod(),
            'url' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status_code' => $response->getStatusCode(),
            'timestamp' => now(),
        ];

        // Log sensitive operations
        if ($this->isSensitiveOperation($routeName)) {
            Log::info('AUDIT: Sensitive operation performed', $logData);
        } else {
            Log::debug('Activity logged', $logData);
        }
    }

    /**
     * Check if route should be logged.
     */
    private function shouldLog(string $routeName): bool
    {
        foreach ($this->loggedRoutes as $pattern) {
            if ($pattern === $routeName || fnmatch($pattern, $routeName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if operation is sensitive and should be highlighted.
     */
    private function isSensitiveOperation(string $routeName): bool
    {
        $sensitive = [
            'password.update',
            'profile.destroy',
            'admin.products.destroy',
            'admin.customers.destroy',
        ];

        return in_array($routeName, $sensitive);
    }
}
