<?php

namespace Tests\Unit\Services;

use App\Mail\InvoiceGeneratedMail;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ResellerCustomerEmailBrandingTest extends TestCase
{
    public function test_invoice_email_uses_reseller_branding_in_header_and_signature(): void
    {
        $reseller = new User([
            'is_reseller' => true,
            'settings' => [
                'branding' => [
                    'company_name' => 'Acme Hosting',
                    'primary_color' => '#ff0000',
                ],
            ],
        ]);
        $reseller->id = 1;

        $customer = new User([
            'reseller_id' => $reseller->id,
            'name' => 'Jane Customer',
            'email' => 'jane@example.test',
        ]);
        $customer->id = 2;
        $customer->setRelation('reseller', $reseller);

        $invoice = new Invoice;
        $invoice->forceFill([
            'invoice_number' => 'INV-2026-00001',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
            'status' => 'unpaid',
            'due_date' => Carbon::now()->addDays(7),
            'created_at' => Carbon::now(),
        ]);
        $invoice->id = 10;
        $invoice->setRelation('user', $customer);
        $invoice->setRelation('items', collect());

        $branding = [
            'company_name' => 'Acme Hosting',
            'logo_url' => null,
            'footer_text' => '',
            'primary_color' => '#ff0000',
            'support_email' => 'help@acme.test',
            'support_phone' => null,
            'portal_url' => 'https://acme.test',
            'reseller_id' => $reseller->id,
            'is_white_label' => true,
        ];
        View::share('emailBranding', $branding);

        $html = (new InvoiceGeneratedMail($invoice))->render();

        $this->assertStringContainsString('Acme Hosting', $html);
        $this->assertStringNotContainsString('Talksasa Cloud', $html);
    }

    public function test_email_company_name_helper_reads_shared_branding(): void
    {
        View::share('emailBranding', [
            'company_name' => 'Reseller Co',
            'logo_url' => null,
            'footer_text' => '',
            'primary_color' => '#000000',
            'support_email' => 'help@reseller.test',
            'support_phone' => null,
            'portal_url' => 'https://reseller.test',
            'reseller_id' => 1,
            'is_white_label' => true,
        ]);

        $this->assertSame('Reseller Co', email_company_name());
        $this->assertSame('help@reseller.test', email_support_email());
    }
}
