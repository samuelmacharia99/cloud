<?php

namespace Tests\Unit\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\MailcowMailboxAccessService;
use App\Services\Provisioning\MailcowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailcowMailboxAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_app_password_payload_for_mailcow(): void
    {
        Http::fake([
            'mail.example.com/api/v1/add/app-passwd' => Http::response([
                ['type' => 'success', 'msg' => 'app_passwd_added'],
            ], 200),
        ]);

        $node = Node::factory()->mailcow()->create();
        $result = MailcowService::forNode($node)->addAppPassword('info@example.com', 'Talksasa console SSO', 'TempPass123456');

        $this->assertTrue($result['success']);
        $this->assertSame('TempPass123456', $result['password']);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/api/v1/add/app-passwd')
                && ($data['username'] ?? null) === 'info@example.com'
                && in_array('smtp_access', $data['protocols'] ?? [], true);
        });
    }

    #[Test]
    public function it_issues_and_consumes_webmail_sso_token(): void
    {
        Http::fake([
            'mail.example.com/api/v1/get/app-passwd/all/*' => Http::response([], 200),
            'mail.example.com/api/v1/add/app-passwd' => Http::response([
                ['type' => 'success', 'msg' => 'app_passwd_added'],
            ], 200),
        ]);

        $customer = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $product = Product::factory()->emailHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'provisioning_driver_key' => 'mailcow',
            'service_meta' => ['mailcow_domain' => 'example.com'],
            'external_reference' => 'example.com',
        ]);

        $access = app(MailcowMailboxAccessService::class);
        $issued = $access->issueWebmailSso($service, 'info@example.com');

        $this->assertTrue($issued['success'], $issued['message'] ?? '');
        $this->assertNotEmpty($issued['token']);

        $payload = $access->consumeWebmailSso($service, $customer->id, $issued['token']);
        $this->assertNotNull($payload);
        $this->assertSame('info@example.com', $payload['mailbox']);
        $this->assertNotEmpty($payload['password']);
        $this->assertStringContainsString('/SOGo/connect', $payload['connect_url']);

        $this->assertNull($access->consumeWebmailSso($service, $customer->id, $issued['token']));
    }

    #[Test]
    public function it_rejects_mailbox_outside_service_domain(): void
    {
        $customer = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $product = Product::factory()->emailHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'provisioning_driver_key' => 'mailcow',
            'service_meta' => ['mailcow_domain' => 'example.com'],
        ]);

        $result = app(MailcowMailboxAccessService::class)->issueWebmailSso($service, 'info@other.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not belong', $result['message']);
    }
}
