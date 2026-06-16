<?php

namespace Tests\Unit\Models;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemAttachedDomainTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(array $attributes = []): InvoiceItem
    {
        $invoice = Invoice::factory()->create();

        return InvoiceItem::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => 'Test item',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
        ], $attributes));
    }

    public function test_service_invoice_item_shows_attached_domain_from_service_meta(): void
    {
        $user = User::factory()->customer()->create();
        $product = Product::factory()->create(['type' => 'shared_hosting']);

        $service = Service::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'name' => 'Starter Hosting',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'service_meta' => ['domain' => 'client.example.com'],
        ]);

        $item = $this->makeItem([
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Starter Hosting — Monthly',
        ]);

        $item->setRelation('service', $service);
        $item->setRelation('product', $product);

        $this->assertSame('client.example.com', $item->attachedDomainLabel());
    }

    public function test_vps_invoice_item_does_not_show_attached_domain(): void
    {
        $user = User::factory()->customer()->create();
        $product = Product::factory()->create(['type' => 'vps']);

        $service = Service::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'name' => 'Cloud VPS',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'service_meta' => ['domain' => 'should-not-show.example.com'],
        ]);

        $item = $this->makeItem([
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Cloud VPS — Monthly',
            'unit_price' => 5000,
            'amount' => 5000,
        ]);

        $item->setRelation('service', $service);
        $item->setRelation('product', $product);

        $this->assertNull($item->attachedDomainLabel());
    }

    public function test_domain_line_item_shows_domain_fqdn(): void
    {
        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'renewme',
            'extension' => '.co.ke',
            'status' => 'active',
        ]);

        $item = $this->makeItem([
            'domain_id' => $domain->id,
            'description' => 'Domain renewal: renewme.co.ke',
            'unit_price' => 1500,
            'amount' => 1500,
        ]);

        $item->setRelation('domain', $domain);

        $this->assertSame('renewme.co.ke', $item->attachedDomainLabel());
    }
}
