<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResellerHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('currentReseller')) {
            abort(404);
        }

        return $next($request);
    }
}
