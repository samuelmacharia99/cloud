<?php

namespace Tests\Unit\Models;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_disk_usage_item_displays_usage_title(): void
    {
        $item = InvoiceItem::make([
            'product_type' => 'reseller_disk_usage',
            'description' => 'Disk usage (Jun 13, 2026 to Jul 13, 2026) — DirectAdmin 0.00 GB',
        ]);

        $this->assertSame('Disk Usage', $item->displayTitle());
    }

    public function test_reseller_package_item_displays_package_name(): void
    {
        $item = InvoiceItem::make([
            'product_type' => 'reseller_package',
            'description' => 'Reseller Package Renewal: Turbo (monthly)',
        ]);

        $this->assertSame('Turbo', $item->displayTitle());
    }

    public function test_legacy_subscription_invoice_synthesizes_package_line_for_display(): void
    {
        $reseller = User::factory()->reseller()->create();

        $invoice = Invoice::create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'invoice_number' => 'INV-LEGACY-001',
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
            'subtotal' => 1850,
            'tax' => 296,
            'total' => 2146,
            'notes' => 'Reseller Package Renewal: Turbo (monthly) [package:5]',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_type' => 'reseller_disk_usage',
            'description' => 'Disk usage (Jun 13, 2026 to Jul 13, 2026) — DirectAdmin 0.00 GB',
            'quantity' => 1,
            'unit_price' => 0,
            'amount' => 0,
        ]);

        $items = $invoice->fresh()->itemsForDisplay();

        $this->assertCount(2, $items);
        $this->assertSame('reseller_package', $items->first()->product_type);
        $this->assertSame('Turbo', $items->first()->displayTitle());
        $this->assertSame(1850.0, (float) $items->first()->amount);
        $this->assertSame('Disk Usage', $items->last()->displayTitle());
    }
}
