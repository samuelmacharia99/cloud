<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckCustomerRole
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->check()) {
            abort(403, 'Unauthorized access');
        }

        if (auth()->user()->is_admin && ! session('impersonating')) {
            return redirect()->route('dashboard');
        }

        if (auth()->user()->is_reseller) {
            return redirect()->route('dashboard')
                ->with('info', 'Please use your reseller portal to manage your account.');
        }

        return $next($request);
    }
}
