<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CsrfPageExpiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_middleware_does_not_stack_duplicate_session_or_csrf(): void
    {
        $web = app('router')->getMiddlewareGroups()['web'] ?? [];

        $startSession = array_values(array_filter($web, fn ($m) => is_string($m) && str_contains($m, 'StartSession')));
        $csrf = array_values(array_filter($web, fn ($m) => is_string($m) && (
            str_contains($m, 'ValidateCsrfToken') || str_contains($m, 'VerifyCsrfToken')
        )));

        $this->assertCount(1, $startSession, 'StartSession must appear once in the web stack');
        $this->assertCount(1, $csrf, 'CSRF middleware must appear once in the web stack');
        $this->assertTrue(
            collect($csrf)->contains(fn ($m) => str_contains($m, 'VerifyCsrfToken')),
            'App VerifyCsrfToken should replace the framework CSRF middleware'
        );
    }

    public function test_token_mismatch_redirects_with_friendly_flash_instead_of_raw_page_expired(): void
    {
        $request = Request::create('/login', 'POST', [], [], [], [
            'HTTP_REFERER' => 'http://localhost/login',
            'HTTP_ACCEPT' => 'text/html',
        ]);
        $this->app->instance('request', $request);

        $handler = $this->app->make(ExceptionHandler::class);
        $response = $handler->render($request, new TokenMismatchException('CSRF token mismatch.'));

        $this->assertTrue($response->isRedirect());
        $this->assertSame('Your session expired. Please try again.', session('error'));
    }

    public function test_token_mismatch_json_returns_419_with_message(): void
    {
        $request = Request::create('/login', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->app->instance('request', $request);

        $handler = $this->app->make(ExceptionHandler::class);
        $response = $handler->render($request, new HttpException(419, 'CSRF token mismatch.'));

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('session expired', $response->getContent());
    }
}
