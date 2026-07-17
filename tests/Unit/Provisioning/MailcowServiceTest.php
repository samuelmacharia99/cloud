<?php

namespace Tests\Unit\Provisioning;

use App\Models\Node;
use App\Services\Provisioning\MailcowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailcowServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_not_configured_without_token(): void
    {
        $node = Node::factory()->mailcow()->create(['api_token' => '']);
        $service = MailcowService::forNode($node);

        $this->assertFalse($service->isConfigured());
    }

    #[Test]
    public function it_tests_connection_against_version_endpoint(): void
    {
        Http::fake([
            'mail.example.com/api/v1/get/status/version' => Http::response('2024-07', 200),
        ]);

        $node = Node::factory()->mailcow()->create();
        $result = MailcowService::forNode($node)->testConnection();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Connected', $result['message']);
    }

    #[Test]
    public function it_builds_webmail_url_from_base(): void
    {
        $node = Node::factory()->mailcow()->create([
            'api_url' => 'https://mail.example.com',
        ]);

        $this->assertSame(
            'https://mail.example.com/SOGo/',
            MailcowService::forNode($node)->webmailUrl()
        );
    }
}
