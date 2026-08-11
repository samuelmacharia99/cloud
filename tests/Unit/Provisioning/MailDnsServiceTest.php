<?php

namespace Tests\Unit\Provisioning;

use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dns\CloudflareDnsService;
use App\Services\Provisioning\MailcowService;
use App\Services\Provisioning\MailDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailDnsServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_spf_with_mail_host_and_optional_ipv4(): void
    {
        $node = Node::factory()->mailcow()->create([
            'hostname' => 'mail.talksasa.test',
            'ip_address' => '203.0.113.10',
        ]);
        $client = MailcowService::forNode($node);
        $spf = app(MailDnsService::class)->spfRecord($client);

        $this->assertSame('v=spf1 mx a:mail.talksasa.test ip4:203.0.113.10 -all', $spf);
    }

    #[Test]
    public function it_ensures_dkim_and_includes_hardened_records(): void
    {
        Http::fake([
            'mail.example.com/api/v1/get/dkim/*' => Http::sequence()
                ->push(['pubkey' => ''], 200)
                ->push([
                    'dkim_txt' => 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjAN',
                    'dkim_selector' => 'dkim',
                ], 200)
                ->push([
                    'dkim_txt' => 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjAN',
                    'dkim_selector' => 'dkim',
                ], 200),
            'mail.example.com/api/v1/add/dkim' => Http::response([
                ['type' => 'success', 'msg' => ['dkim_added', 'mailme.com']],
            ], 200),
        ]);

        config(['mailcow.dmarc_policy' => 'v=DMARC1; p=quarantine; adkim=r; aspf=r']);

        $user = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create([
            'hostname' => 'mail.example.com',
            'ip_address' => '203.0.113.55',
        ]);
        $product = Product::factory()->emailHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'service_meta' => ['mailcow_domain' => 'mailme.com'],
            'external_reference' => 'mailme.com',
        ]);

        $records = app(MailDnsService::class)->recommendedRecords($service);

        $mx = collect($records)->firstWhere('type', 'MX');
        $spf = collect($records)->first(fn ($r) => $r['type'] === 'TXT' && $r['name'] === '@');
        $dmarc = collect($records)->firstWhere('name', '_dmarc');
        $dkim = collect($records)->first(fn ($r) => str_ends_with($r['name'], '._domainkey'));

        $this->assertSame('mail.example.com', $mx['content']);
        $this->assertStringContainsString('ip4:203.0.113.55', $spf['content']);
        $this->assertStringContainsString('p=quarantine', $dmarc['content']);
        $this->assertSame('dkim._domainkey', $dkim['name']);
        $this->assertStringContainsString('p=MIIBIjAN', $dkim['content']);

        Http::assertSent(fn ($request) => $request->url() === 'https://mail.example.com/api/v1/add/dkim'
            && $request['domains'] === 'mailme.com');
    }

    #[Test]
    public function sync_applies_records_for_cloudflare_mail_domains(): void
    {
        Setting::setValue('cloudflare_enabled', 'true');
        Setting::setValue('cloudflare_api_token', 'cf-test-token-abcdefghijklmnopqrstuvwxyz');
        Setting::setValue('cloudflare_account_id', 'account-123');

        Http::fake([
            'mail.example.com/api/v1/get/dkim/*' => Http::response([
                'dkim_txt' => 'v=DKIM1;k=rsa;t=s;s=email;p=PUBKEY',
                'dkim_selector' => 'dkim',
            ], 200),
            'api.cloudflare.com/client/v4/zones/*/dns_records*' => Http::sequence()
                ->push(['success' => true, 'result' => []], 200) // list
                ->push(['success' => true, 'result' => ['id' => 'r1']], 200) // MX
                ->push(['success' => true, 'result' => ['id' => 'r2']], 200) // SPF
                ->push(['success' => true, 'result' => ['id' => 'r3']], 200) // DMARC
                ->push(['success' => true, 'result' => ['id' => 'r4']], 200), // DKIM
        ]);

        $user = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create(['hostname' => 'mail.example.com']);
        $product = Product::factory()->emailHosting()->create();
        Domain::create([
            'user_id' => $user->id,
            'name' => 'mailme',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-abc',
        ]);
        Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'service_meta' => ['mailcow_domain' => 'mailme.com'],
            'external_reference' => 'mailme.com',
        ]);

        $this->assertTrue(app(CloudflareDnsService::class)->isConfigured());

        $summary = app(MailDnsService::class)->syncAllCloudflareMailDomains(false);

        $this->assertSame(1, $summary['eligible']);
        $this->assertSame(1, $summary['applied']);
        $this->assertSame('applied', $summary['results'][0]['status']);
    }

    #[Test]
    public function sync_dry_run_does_not_write_dns(): void
    {
        $user = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $product = Product::factory()->emailHosting()->create();
        Domain::create([
            'user_id' => $user->id,
            'name' => 'mailme',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-abc',
        ]);
        Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'service_meta' => ['mailcow_domain' => 'mailme.com'],
        ]);

        Http::fake();

        $summary = app(MailDnsService::class)->syncAllCloudflareMailDomains(true);

        $this->assertSame(1, $summary['eligible']);
        $this->assertSame(0, $summary['applied']);
        $this->assertSame('dry-run', $summary['results'][0]['status']);
        Http::assertNothingSent();
    }
}
