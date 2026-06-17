<?php

namespace Tests\Unit\Services;

use App\Mail\AccountWelcomeMail;
use App\Mail\InvoiceGeneratedMail;
use App\Mail\PasswordChangedMail;
use App\Mail\TicketRepliedMail;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\TicketReply;
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

    public function test_ticket_reply_email_uses_branded_support_team_for_staff_replies(): void
    {
        View::share('emailBranding', [
            'company_name' => 'Acme Hosting',
            'logo_url' => null,
            'footer_text' => '',
            'primary_color' => '#ff0000',
            'support_email' => 'help@acme.test',
            'support_phone' => null,
            'portal_url' => 'https://acme.test',
            'reseller_id' => 1,
            'is_white_label' => true,
        ]);

        $reseller = new User(['is_reseller' => true, 'name' => 'Internal Reseller Account']);
        $reseller->id = 1;

        $customer = new User(['reseller_id' => $reseller->id, 'name' => 'Jane Customer']);
        $customer->id = 2;
        $customer->setRelation('reseller', $reseller);

        $ticket = new Ticket([
            'user_id' => $customer->id,
            'title' => 'Need help',
            'status' => 'open',
            'priority' => 'medium',
        ]);
        $ticket->id = 5;
        $ticket->setRelation('user', $customer);

        $reply = new TicketReply([
            'ticket_id' => $ticket->id,
            'user_id' => $reseller->id,
            'message' => 'We are looking into this.',
            'is_staff_reply' => true,
        ]);
        $reply->id = 1;
        $reply->created_at = now();
        $reply->setRelation('user', $reseller);

        $html = (new TicketRepliedMail($ticket, $reply))->render();

        $this->assertStringContainsString('Reply from Acme Hosting Support Team', $html);
        $this->assertStringNotContainsString('Internal Reseller Account', $html);
        $this->assertStringNotContainsStringIgnoringCase('reseller', $html);
    }

    public function test_password_changed_email_subject_uses_reseller_branding(): void
    {
        View::share('emailBranding', [
            'company_name' => 'Acme Hosting',
            'logo_url' => null,
            'footer_text' => '',
            'primary_color' => '#ff0000',
            'support_email' => 'help@acme.test',
            'support_phone' => null,
            'portal_url' => 'https://acme.test',
            'reseller_id' => 1,
            'is_white_label' => true,
        ]);

        $customer = new User(['reseller_id' => 1, 'name' => 'Jane Customer']);

        $mail = new PasswordChangedMail($customer);

        $this->assertSame('Password Changed Successfully — Acme Hosting', $mail->envelope()->subject);
    }

    public function test_account_welcome_email_avoids_reseller_portal_wording(): void
    {
        View::share('emailBranding', [
            'company_name' => 'Acme Hosting',
            'logo_url' => null,
            'footer_text' => '',
            'primary_color' => '#ff0000',
            'support_email' => 'help@acme.test',
            'support_phone' => null,
            'portal_url' => 'https://portal.acme.test',
            'reseller_id' => 1,
            'is_white_label' => true,
        ]);

        $customer = new User(['reseller_id' => 1, 'name' => 'Jane Customer', 'email' => 'jane@example.test']);
        $customer->id = 2;

        $html = (new AccountWelcomeMail($customer, 'secret-pass', 'customer'))->render();

        $this->assertStringContainsString('Your account has been created', $html);
        $this->assertStringContainsString('https://portal.acme.test', $html);
        $this->assertStringNotContainsStringIgnoringCase('reseller', $html);
        $this->assertStringNotContainsString('customer portal', $html);
    }
}
