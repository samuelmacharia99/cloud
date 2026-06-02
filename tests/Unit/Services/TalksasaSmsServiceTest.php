<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\TalksasaSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TalksasaSmsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_sms_rejects_non_reseller_context(): void
    {
        $customer = User::factory()->create([
            'settings' => [],
        ]);

        Http::fake();

        $result = app(TalksasaSmsService::class)->sendSms($customer, '0712345678', 'Test notification');

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        Http::assertNothingSent();
    }

    public function test_send_sms_uses_reseller_sms_settings(): void
    {
        $reseller = User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'sms' => [
                    'enabled' => true,
                    'api_key' => 'reseller-token',
                    'sender_id' => 'ResellerID',
                ],
            ],
        ]);

        Http::fake([
            'https://bulksms.talksasa.com/api/v3/sms/send' => Http::response([
                'status' => 'accepted',
                'message' => 'Queued',
                'queue_uid' => 'queue_123',
            ], 202),
        ]);

        $result = app(TalksasaSmsService::class)->sendSms($reseller, '0712345678', 'Test notification');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer reseller-token')
                && $request['sender_id'] === 'ResellerID';
        });
    }
}

