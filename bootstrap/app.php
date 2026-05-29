<?php

use App\Http\Middleware\CheckAdminRole;
use App\Http\Middleware\CheckCustomerRole;
use App\Http\Middleware\CheckResellerRole;
use App\Http\Middleware\EnforceResellerLimits;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SkipVerificationIfImpersonating;
use App\Http\Middleware\ThrottleRegistration;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            AuthenticateSession::class,
            SecurityHeaders::class,
            LogActivity::class,
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ], append: [
            ThrottleRequests::class.':api',
        ]);

        // Register custom route middleware
        $middleware->alias([
            'admin' => CheckAdminRole::class,
            'customer' => CheckCustomerRole::class,
            'reseller' => CheckResellerRole::class,
            'reseller.limits' => EnforceResellerLimits::class,
            'skip.verification.if.impersonating' => SkipVerificationIfImpersonating::class,
            'registration.throttle' => ThrottleRegistration::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if (! $request->is('my/services/*/container/files/upload')) {
                return null;
            }

            $maxMb = (int) config('security.container_file_upload.max_size_mb', 100);

            return response()->json([
                'error' => "File exceeds the server upload limit ({$maxMb} MB). "
                    .'Increase nginx client_max_body_size and PHP post_max_size — see deploy/nginx/upload-limits.conf.',
            ], 413);
        });
    })->create();
