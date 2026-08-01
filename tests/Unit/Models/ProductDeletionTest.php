<?php

namespace Tests\Unit\Models;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerProvisionProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_product_can_be_deleted(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue($product->canBeDeleted());
        $this->assertSame('', $product->deletionBlockedMessage());

        $product->delete();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_product_with_invoice_items_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-001',
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Test item',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
        ]);

        $this->assertFalse($product->fresh()->canBeDeleted());
        $this->assertStringContainsString('invoice line item', $product->deletionBlockedMessage());
    }

    public function test_system_shell_product_cannot_be_deleted(): void
    {
        $product = Product::factory()->create([
            'slug' => ResellerProvisionProductResolver::SHELL_PRODUCT_SLUG,
            'name' => 'Reseller DirectAdmin Hosting (system)',
        ]);

        $this->assertFalse($product->canBeDeleted());
        $this->assertStringContainsString('system product', $product->deletionBlockedMessage());
    }

    public function test_product_with_services_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();

        Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        $this->assertFalse($product->fresh()->canBeDeleted());
        $this->assertStringContainsString('service', $product->deletionBlockedMessage());
    }
}
