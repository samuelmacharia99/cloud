<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_ticket_with_attachment(): void
    {
        Storage::fake('local');

        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)->post(route('customer.tickets.store'), [
            'title' => 'Billing issue',
            'description' => 'Please review my invoice.',
            'priority' => 'medium',
            'attachments' => [
                UploadedFile::fake()->image('screenshot.png'),
            ],
        ]);

        $ticket = Ticket::first();
        $this->assertNotNull($ticket);
        $response->assertRedirect(route('customer.tickets.show', $ticket));

        $attachment = TicketAttachment::first();
        $this->assertNotNull($attachment);
        $this->assertSame($ticket->id, $attachment->ticket_id);
        $this->assertNull($attachment->ticket_reply_id);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_admin_can_reply_with_attachment(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'title' => 'Help needed',
            'description' => 'Issue details',
            'priority' => 'low',
            'status' => 'open',
        ]);

        $response = $this->actingAs($admin)->post(route('tickets.reply', $ticket), [
            'message' => 'Please see the attached guide.',
            'attachments' => [
                UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('ticket_replies', 1);
        $this->assertDatabaseCount('ticket_attachments', 1);

        $attachment = TicketAttachment::first();
        $this->assertNotNull($attachment->ticket_reply_id);
        Storage::disk('local')->assertExists($attachment->path);
    }
}
