<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AdminAttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AdminAttentionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_seen_persists_section_timestamp_and_clears_cache(): void
    {
        Cache::put('admin_attention_42', ['domain_orders_new' => 3], 60);

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 42;
        $user->settings = [];

        $user->shouldReceive('forceFill')
            ->once()
            ->with(Mockery::on(fn (array $data) => isset($data['settings']['admin_seen']['domain_orders'])))
            ->andReturnSelf();

        $user->shouldReceive('save')->once();

        $service = new AdminAttentionService;
        $service->markSeen($user, 'domain_orders');

        $this->assertFalse(Cache::has('admin_attention_42'));
    }

    public function test_mark_seen_ignores_unknown_sections(): void
    {
        $this->expectNotToPerformAssertions();

        $user = Mockery::mock(User::class);
        $user->shouldNotReceive('save');

        $service = new AdminAttentionService;
        $service->markSeen($user, 'invalid_section');
    }

    public function test_snapshot_only_counts_admin_actionable_items(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->customer()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
        ]);

        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'status' => PaymentStatus::Pending,
            'payment_method' => PaymentMethod::Mpesa,
        ]);

        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 2500,
            'status' => PaymentStatus::Pending,
            'payment_method' => PaymentMethod::Manual,
        ]);

        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'status' => PaymentStatus::Pending,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        $product = Product::factory()->create();
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'provisioning',
        ]);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'failed',
        ]);

        Ticket::create([
            'user_id' => $customer->id,
            'title' => 'Help needed',
            'description' => 'Test ticket',
            'status' => 'open',
            'priority' => 'normal',
            'handled_by' => 'platform',
        ]);

        $snapshot = app(AdminAttentionService::class)->snapshot($admin);

        $this->assertSame(2, $snapshot['payments']);
        $this->assertSame(0, $snapshot['orders']);
        $this->assertSame(1, $snapshot['services_failed']);
        $this->assertSame(1, $snapshot['tickets']);
        $this->assertSame(4, $snapshot['total']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
