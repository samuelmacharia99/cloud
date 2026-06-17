<?php

namespace Tests\Unit\Services;

use App\Enums\ResellerDomainOrderType;
use App\Models\Domain;
use App\Models\ResellerDomainOrder;
use App\Models\User;
use App\Services\DomainTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fail_transfer_uses_valid_domain_status_and_fails_linked_order(): void
    {
        $customer = User::factory()->customer()->create();

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'failme',
            'extension' => '.com',
            'type' => 'transfer',
            'status' => 'pending',
            'transfer_status' => 'in_progress',
            'epp_code' => 'BAD-CODE',
            'old_registrar' => 'GoDaddy',
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'domain_name' => 'failme',
            'extension' => '.com',
            'order_type' => ResellerDomainOrderType::Transfer,
            'years' => 1,
            'wholesale_amount' => 1500,
            'retail_amount' => 0,
            'status' => 'pushed',
            'pushed_at' => now(),
        ]);

        $result = DomainTransferService::failTransfer($domain, 'Authorization code is invalid');

        $this->assertTrue($result);

        $domain->refresh();
        $order->refresh();

        $this->assertSame('pending', $domain->status);
        $this->assertSame('failed', $domain->transfer_status);
        $this->assertStringContainsString('Authorization code is invalid', $domain->transfer_notes);
        $this->assertSame('failed', $order->status);
        $this->assertStringContainsString('Authorization code is invalid', $order->failure_reason);
    }
}
