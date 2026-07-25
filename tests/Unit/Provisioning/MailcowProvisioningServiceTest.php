<?php

namespace Tests\Unit\Provisioning;

use App\Models\Product;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\Provisioning\MailcowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailcowProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;
    #[Test]
    public function it_reads_limits_from_product_resource_limits(): void
    {
        $product = new Product([
            'resource_limits' => [
                'mailboxes' => 5,
                'aliases' => 15,
                'quota_mb' => 10240,
                'mailbox_quota_mb' => 2048,
                'msgs_per_day' => 250,
            ],
        ]);

        $limits = (new MailcowProvisioningService)->limitsForProduct($product);

        $this->assertSame(5, $limits['mailboxes']);
        $this->assertSame(15, $limits['aliases']);
        $this->assertSame(10240, $limits['quota_mb']);
        $this->assertSame(2048, $limits['mailbox_quota_mb']);
        $this->assertSame(250, $limits['msgs_per_day']);
    }

    #[Test]
    public function it_defaults_msgs_per_day_when_missing(): void
    {
        $product = new Product([
            'resource_limits' => [
                'mailboxes' => 5,
                'aliases' => 15,
                'quota_mb' => 10240,
                'mailbox_quota_mb' => 2048,
            ],
        ]);

        $limits = (new MailcowProvisioningService)->limitsForProduct($product);

        $this->assertSame((int) config('mailcow.default_msgs_per_day', 500), $limits['msgs_per_day']);
    }

    #[Test]
    public function it_posts_domain_daily_ratelimit_to_mailcow(): void
    {
        Http::fake([
            'mail.example.com/api/v1/edit/rl-domain' => Http::response([
                ['type' => 'success', 'msg' => 'rl_saved'],
            ], 200),
        ]);

        $node = \App\Models\Node::factory()->mailcow()->create([
            'api_url' => 'https://mail.example.com',
            'api_token' => 'test-key',
        ]);

        $result = MailcowService::forNode($node)->editDomainRatelimit('example.com', 500, 'd');

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/api/v1/edit/rl-domain')
                && ($data['items'][0] ?? null) === 'example.com'
                && ($data['attr']['rl_value'] ?? null) === '500'
                && ($data['attr']['rl_frame'] ?? null) === 'd';
        });
    }
}
