<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaCatalogInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_factory_only_uses_current_price_columns(): void
    {
        $product = Product::factory()->create([
            'monthly_price' => 25,
            'yearly_price' => 250,
        ]);

        $this->assertNotContains('price', $product->getFillable());
        $this->assertSame(25.0, $product->priceForBillingCycle('monthly'));
        $this->assertSame(250.0, $product->priceForBillingCycle('annual'));
    }

    public function test_direct_admin_node_factory_supplies_connection_defaults(): void
    {
        $node = Node::factory()->directAdmin()->create();

        $this->assertSame('directadmin', $node->type);
        $this->assertSame('admin', $node->da_admin_username);
        $this->assertSame('2222', $node->da_port);
        $this->assertNotEmpty($node->da_login_key);
        $this->assertTrue($node->isHealthy());
    }

    public function test_sqlite_payment_purpose_accepts_credit_topups(): void
    {
        $payment = Payment::factory()->create([
            'user_id' => User::factory()->customer(),
            'invoice_id' => null,
            'payment_purpose' => 'credit_topup',
        ]);

        $this->assertSame('credit_topup', $payment->payment_purpose);
    }
}
