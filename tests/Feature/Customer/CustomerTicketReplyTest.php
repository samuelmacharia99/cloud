<?php

namespace Tests\Feature\Customer;

use App\Enums\TicketHandledBy;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTicketReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_reply_form_when_ticket_is_in_progress(): void
    {
        [$customer, $ticket] = $this->customerTicket('in_progress');

        $this->actingAs($customer)
            ->get(route('customer.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Add Reply', false)
            ->assertSee('Send Reply', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee(route('customer.tickets.reply', $ticket), false);
    }

    public function test_customer_sees_reply_form_when_ticket_is_on_hold(): void
    {
        [$customer, $ticket] = $this->customerTicket('on_hold');

        $this->actingAs($customer)
            ->get(route('customer.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Add Reply', false)
            ->assertSee(route('customer.tickets.reply', $ticket), false);
    }

    public function test_customer_can_reply_to_an_in_progress_ticket(): void
    {
        [$customer, $ticket] = $this->customerTicket('in_progress');

        $this->actingAs($customer)
            ->post(route('customer.tickets.reply', $ticket), [
                'message' => 'Here is the extra log output you asked for.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_replies', [
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'message' => 'Here is the extra log output you asked for.',
            'is_staff_reply' => false,
        ]);
        $this->assertSame('in_progress', $ticket->fresh()->status);
    }

    public function test_customer_reply_on_closed_ticket_reopens_it(): void
    {
        [$customer, $ticket] = $this->customerTicket('closed', resolvedAt: now());

        $this->actingAs($customer)
            ->get(route('customer.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Reopen with a reply', false)
            ->assertSee('Sending a reply will reopen it', false);

        $this->actingAs($customer)
            ->post(route('customer.tickets.reply', $ticket), [
                'message' => 'This is not resolved yet.',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertSame(1, TicketReply::where('ticket_id', $ticket->id)->count());
    }

    public function test_another_customer_cannot_reply(): void
    {
        [, $ticket] = $this->customerTicket('in_progress');
        $stranger = User::factory()->customer()->create();

        $this->actingAs($stranger)
            ->post(route('customer.tickets.reply', $ticket), [
                'message' => 'Not my ticket.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('ticket_replies', 0);
    }

    /**
     * @return array{0: User, 1: Ticket}
     */
    private function customerTicket(string $status, mixed $resolvedAt = null): array
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'handled_by' => TicketHandledBy::Platform->value,
            'title' => 'Site is slow',
            'description' => 'POS dashboard hangs after login.',
            'priority' => 'high',
            'status' => $status,
            'resolved_at' => $resolvedAt,
        ]);

        return [$customer, $ticket];
    }
}
