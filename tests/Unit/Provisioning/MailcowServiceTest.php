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
    public function it_forces_ipv4_for_api_requests_by_default(): void
    {
        Http::fake([
            'mail.example.com/api/v1/get/status/version' => Http::response('2024-07', 200),
        ]);

        config(['mailcow.force_ipv4' => true]);

        $node = Node::factory()->mailcow()->create([
            'api_url' => 'https://mail.example.com',
        ]);

        MailcowService::forNode($node)->testConnection();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://mail.example.com/api/v1/get/status/version';
        });
    }

    #[Test]
    public function it_clarifies_api_access_denied_errors(): void
    {
        Http::fake([
            'mail.example.com/api/v1/add/domain' => Http::response([
                ['type' => 'danger', 'msg' => 'api access denied for ip 2a01:4f9:c014:e51f::1'],
            ], 200),
        ]);

        $node = Node::factory()->mailcow()->create([
            'api_url' => 'https://mail.example.com',
        ]);

        $result = MailcowService::forNode($node)->addDomain(['domain' => 'example.com']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('api access denied', $result['message']);
        $this->assertStringContainsString('API allowlist', $result['message']);
    }

    #[Test]
    public function it_ensures_dkim_by_creating_when_missing(): void
    {
        Http::fake([
            'mail.example.com/api/v1/get/dkim/*' => Http::sequence()
                ->push([], 200)
                ->push([
                    'dkim_txt' => 'v=DKIM1;k=rsa;t=s;s=email;p=ABC',
                    'dkim_selector' => 'dkim',
                ], 200),
            'mail.example.com/api/v1/add/dkim' => Http::response([
                ['type' => 'success', 'msg' => ['dkim_added', 'example.com']],
            ], 200),
        ]);

        $node = Node::factory()->mailcow()->create([
            'api_url' => 'https://mail.example.com',
        ]);

        $result = MailcowService::forNode($node)->ensureDkim('example.com');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['created']);
        $this->assertStringContainsString('p=ABC', $result['dkim_txt']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://mail.example.com/api/v1/add/dkim'
                && $request['domains'] === 'example.com'
                && $request['dkim_selector'] === 'dkim'
                && $request['key_size'] === '2048';
        });
    }

    #[Test]
    public function it_normalizes_bare_dkim_pubkey_to_txt(): void
    {
        $node = Node::factory()->mailcow()->create();
        $txt = MailcowService::forNode($node)->normalizeDkimTxt('MIIBIjANBgkq');

        $this->assertSame('v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkq', $txt);
    }
}
