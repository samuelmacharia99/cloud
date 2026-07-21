<?php

namespace Tests\Unit\Services\Dns;

use App\Models\Setting;
use App\Services\Dns\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsServiceRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('cloudflare_enabled', 'true');
        Setting::setValue('cloudflare_api_token', 'test-token-abcdefghijklmnopqrstuvwxyz');
        Setting::setValue('cloudflare_account_id', 'acct123');
    }

    public function test_create_record_sends_proxied_and_auto_ttl(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/*/dns_records' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'rec1',
                    'type' => 'A',
                    'name' => 'example.com',
                    'content' => '1.2.3.4',
                    'ttl' => 1,
                    'proxied' => true,
                ],
            ]),
        ]);

        $service = app(CloudflareDnsService::class);
        $result = $service->createRecord('zone1', 'A', 'example.com', '1.2.3.4', 3600, null, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['record']['proxied']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && ($body['proxied'] ?? null) === true
                && ($body['ttl'] ?? null) === 1;
        });
    }

    public function test_update_record_can_disable_proxy(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/*/dns_records/*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'rec1',
                    'type' => 'A',
                    'name' => 'example.com',
                    'content' => '1.2.3.4',
                    'ttl' => 3600,
                    'proxied' => false,
                ],
            ]),
        ]);

        $service = app(CloudflareDnsService::class);
        $result = $service->updateRecord('zone1', 'rec1', 'A', 'example.com', '1.2.3.4', 3600, null, false);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['record']['proxied']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && ($body['proxied'] ?? null) === false
                && ($body['ttl'] ?? null) === 3600;
        });
    }

    public function test_mx_records_do_not_send_proxied(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/*/dns_records' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'rec2',
                    'type' => 'MX',
                    'name' => 'example.com',
                    'content' => 'mail.example.com',
                    'ttl' => 3600,
                    'priority' => 10,
                    'proxied' => false,
                ],
            ]),
        ]);

        $service = app(CloudflareDnsService::class);
        $service->createRecord('zone1', 'MX', 'example.com', 'mail.example.com', 3600, 10, true);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && ! array_key_exists('proxied', $body)
                && ($body['priority'] ?? null) === 10;
        });
    }
}
