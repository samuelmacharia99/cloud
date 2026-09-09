<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which portal is rendering the shared application console.
 *
 * The console partials are used by both the customer service page and the admin
 * service page, so every link inside them resolves through container_route().
 * The chosen prefix is kept on the current request rather than in shared view
 * state, so a request that renders the admin console cannot make the next
 * customer page emit admin URLs.
 */
class ContainerConsoleContext
{
    public const CUSTOMER_PREFIX = 'customer.services.container';

    public const ADMIN_PREFIX = 'admin.services.container';

    private const ATTRIBUTE = 'container_console_route_prefix';

    public static function useAdminRoutes(?Request $request = null): void
    {
        ($request ?? request())->attributes->set(self::ATTRIBUTE, self::ADMIN_PREFIX);
    }

    public static function routePrefix(?Request $request = null): string
    {
        $request ??= request();

        if (! $request instanceof Request) {
            return self::CUSTOMER_PREFIX;
        }

        $prefix = $request->attributes->get(self::ATTRIBUTE);

        return $prefix === self::ADMIN_PREFIX ? self::ADMIN_PREFIX : self::CUSTOMER_PREFIX;
    }
}
