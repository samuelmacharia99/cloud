<?php

namespace App\Http\Middleware;

use App\Services\AdminAttentionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MarkAdminSectionSeen
{
    /** @var array<string, string> */
    private const ROUTE_PREFIXES = [
        'admin.domain-orders.' => 'domain_orders',
        'admin.orders.' => 'orders',
        'admin.domain-renewals.' => 'domain_renewals',
        'tickets.' => 'tickets',
        'admin.payments.' => 'payments',
        'admin.services.' => 'services',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if (! $user?->isAdmin() || ! $request->route()) {
            return $response;
        }

        $routeName = $request->route()->getName() ?? '';

        foreach (self::ROUTE_PREFIXES as $prefix => $section) {
            if (str_starts_with($routeName, $prefix)) {
                app(AdminAttentionService::class)->markSeen($user, $section);

                break;
            }
        }

        return $response;
    }
}
