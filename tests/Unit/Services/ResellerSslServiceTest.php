<?php

namespace Tests\Unit\Services;

use App\Services\ResellerSslService;
use Tests\TestCase;

class ResellerSslServiceTest extends TestCase
{
    public function test_summarize_certbot_failure_skips_boilerplate_and_returns_detail(): void
    {
        $output = <<<'TXT'
Saving debug log to /var/log/letsencrypt/letsencrypt.log
The following error was encountered:
Detail: 89.167.115.94: Invalid response from http://server.enthelotcloud.com/.well-known/acme-challenge/test: 404
TXT;

        $summary = app(ResellerSslService::class)->summarizeCertbotFailure($output);

        $this->assertStringNotContainsString('following error was encountered', strtolower($summary));
        $this->assertStringContainsString('404', $summary);
        $this->assertStringContainsString('acme-challenge', $summary);
    }

    public function test_summarize_certbot_failure_returns_tail_when_no_detail_line(): void
    {
        $output = "Line one\nLine two\nSomething went wrong entirely\n";

        $summary = app(ResellerSslService::class)->summarizeCertbotFailure($output);

        $this->assertStringContainsString('Something went wrong', $summary);
    }
}
