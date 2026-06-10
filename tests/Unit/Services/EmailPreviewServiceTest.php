<?php

namespace Tests\Unit\Services;

use App\Enums\NotificationEvent;
use App\Models\Email;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wraps_plain_body_in_branded_layout(): void
    {
        Setting::setValue('company_name', 'Talksasa Cloud');

        $customer = User::factory()->create(['name' => 'Jane Customer']);

        $email = Email::create([
            'recipient' => $customer->email,
            'user_id' => $customer->id,
            'subject' => 'Payment received',
            'event_key' => NotificationEvent::PaymentReceived->value,
            'body' => "Hello Jane,\n\nYour payment was received.",
            'status' => 'sent',
            'created_at' => now(),
        ]);

        $service = app(EmailPreviewService::class);
        $html = $service->customerHtml($email);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Talksasa Cloud', $html);
        $this->assertStringContainsString('Your payment was received.', $html);
    }

    public function test_uses_stored_html_body_when_available(): void
    {
        $email = Email::create([
            'recipient' => 'customer@example.com',
            'subject' => 'Welcome',
            'body' => 'Plain fallback',
            'html_body' => '<html><body><p>Stored HTML preview</p></body></html>',
            'status' => 'sent',
            'created_at' => now(),
        ]);

        $service = app(EmailPreviewService::class);

        $this->assertSame($email->html_body, $service->customerHtml($email));
        $this->assertStringContainsString('Stored HTML preview', $service->plainTextContent($email));
    }

    public function test_generic_layout_for_ticket_replies(): void
    {
        Setting::setValue('company_name', 'Talksasa Cloud');

        $email = Email::create([
            'recipient' => 'customer@example.com',
            'subject' => 'Re: Support ticket #12',
            'event_key' => NotificationEvent::TicketReplied->value,
            'body' => 'We have updated your ticket.',
            'status' => 'sent',
            'created_at' => now(),
        ]);

        $html = app(EmailPreviewService::class)->customerHtml($email);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Re: Support ticket #12', $html);
        $this->assertStringContainsString('We have updated your ticket.', $html);
    }
}
