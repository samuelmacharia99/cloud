<?php

namespace Tests\Unit\Services;

use App\Enums\TicketHandledBy;
use App\Mail\GenericNotificationMail;
use App\Mail\TicketCreatedMail;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('mail_from_address', 'noreply@example.com');
        Setting::setValue('notify_ticket', 'true');
        Setting::setValue('notify_ticket_platform', 'true');
        Setting::setValue('notify_ticket_reseller', 'true');
        Setting::setValue('email_queue_enabled', 'false');
    }

    public function test_platform_ticket_emails_admins_with_heading_and_body(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create(['email' => 'ops@talksasa.test']);
        $customer = User::factory()->customer()->create(['name' => 'Amina Otieno']);
        $ticket = $this->platformTicket($customer, 'Cannot reach mail', 'SMTP times out after 30s.');

        app(TicketNotificationService::class)->notifyCreated($ticket);

        Mail::assertSent(TicketCreatedMail::class, fn (TicketCreatedMail $mail) => $mail->hasTo($customer->email));
        Mail::assertSent(GenericNotificationMail::class, function (GenericNotificationMail $mail) use ($admin, $ticket) {
            return $mail->hasTo($admin->email)
                && $mail->mailSubject === 'New support ticket #'.$ticket->id
                && $mail->heading === 'New support ticket'
                && str_contains($mail->body, 'Amina Otieno')
                && str_contains($mail->body, 'Cannot reach mail')
                && str_contains($mail->body, 'SMTP times out after 30s.');
        });
    }

    public function test_reseller_customer_ticket_emails_reseller_with_heading_and_body(): void
    {
        Mail::fake();

        $reseller = User::factory()->reseller()->create(['email' => 'reseller@example.com']);
        $customer = User::factory()->customer()->create([
            'name' => 'Jane Customer',
            'reseller_id' => $reseller->id,
        ]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'handled_by' => TicketHandledBy::Reseller->value,
            'title' => 'Invoice mismatch',
            'description' => 'Charged twice this month.',
            'status' => 'open',
            'priority' => 'high',
        ]);

        app(TicketNotificationService::class)->notifyCreated($ticket);

        Mail::assertSent(GenericNotificationMail::class, function (GenericNotificationMail $mail) use ($reseller, $ticket) {
            return $mail->hasTo($reseller->email)
                && $mail->mailSubject === 'Customer support ticket #'.$ticket->id
                && $mail->heading === 'New customer support ticket'
                && str_contains($mail->body, 'Jane Customer')
                && str_contains($mail->body, 'Invoice mismatch')
                && str_contains($mail->body, 'Charged twice this month.');
        });
    }

    public function test_escalated_ticket_emails_admins_with_heading_and_body(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create(['email' => 'ops@talksasa.test']);
        $reseller = User::factory()->reseller()->create(['company' => 'Acme Hosting']);
        $customer = User::factory()->customer()->create([
            'name' => 'Jane Customer',
            'reseller_id' => $reseller->id,
        ]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'handled_by' => TicketHandledBy::Platform->value,
            'title' => 'Server down',
            'description' => 'Cannot SSH in.',
            'status' => 'open',
            'priority' => 'urgent',
            'escalated_at' => now(),
            'escalated_by' => $reseller->id,
            'escalation_note' => 'Needs platform network team.',
        ]);

        app(TicketNotificationService::class)->notifyEscalated($ticket);

        Mail::assertSent(GenericNotificationMail::class, function (GenericNotificationMail $mail) use ($admin, $ticket) {
            return $mail->hasTo($admin->email)
                && $mail->mailSubject === 'Ticket #'.$ticket->id.' escalated from Acme Hosting'
                && $mail->heading === 'Ticket escalated to platform support'
                && str_contains($mail->body, 'Jane Customer')
                && str_contains($mail->body, 'Needs platform network team.');
        });
    }

    public function test_reseller_customer_reply_emails_reseller_with_heading_and_body(): void
    {
        Mail::fake();

        $reseller = User::factory()->reseller()->create(['email' => 'reseller@example.com']);
        $customer = User::factory()->customer()->create([
            'name' => 'Jane Customer',
            'reseller_id' => $reseller->id,
        ]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'handled_by' => TicketHandledBy::Reseller->value,
            'title' => 'Invoice mismatch',
            'description' => 'Charged twice this month.',
            'status' => 'open',
            'priority' => 'medium',
        ]);
        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'message' => 'Here is the second receipt.',
            'is_staff_reply' => false,
        ]);

        app(TicketNotificationService::class)->notifyReplied($ticket, $reply);

        Mail::assertSent(GenericNotificationMail::class, function (GenericNotificationMail $mail) use ($reseller, $ticket) {
            return $mail->hasTo($reseller->email)
                && $mail->mailSubject === 'Customer replied: ticket #'.$ticket->id
                && $mail->heading === 'Customer replied to ticket'
                && str_contains($mail->body, 'Jane Customer')
                && str_contains($mail->body, 'Here is the second receipt.');
        });
    }

    private function platformTicket(User $customer, string $title, string $description): Ticket
    {
        return Ticket::create([
            'user_id' => $customer->id,
            'handled_by' => TicketHandledBy::Platform->value,
            'title' => $title,
            'description' => $description,
            'status' => 'open',
            'priority' => 'high',
        ]);
    }
}
