<?php

namespace Tests\Feature;

use App\Enums\TicketHandledBy;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_customer_ticket_is_routed_to_reseller(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($customer)->post(route('customer.tickets.store'), [
            'title' => 'Billing question',
            'description' => 'Need help with invoice',
            'priority' => 'medium',
        ])->assertRedirect();

        $ticket = Ticket::first();
        $this->assertSame($reseller->id, $ticket->reseller_id);
        $this->assertSame(TicketHandledBy::Reseller, $ticket->handled_by);
    }

    public function test_platform_customer_ticket_is_visible_to_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create();

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'handled_by' => TicketHandledBy::Platform->value,
            'title' => 'Direct customer ticket',
            'description' => 'Help',
            'status' => 'open',
            'priority' => 'low',
        ]);

        $ticket = Ticket::findOrFail($ticket->id);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_admin_can_view_platform_ticket_after_enum_cast_from_database(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create();

        $ticketId = Ticket::create([
            'user_id' => $customer->id,
            'handled_by' => TicketHandledBy::Platform->value,
            'title' => 'Cast regression',
            'description' => 'Help',
            'status' => 'open',
            'priority' => 'low',
        ])->id;

        $ticket = Ticket::findOrFail($ticketId);
        $this->assertInstanceOf(TicketHandledBy::class, $ticket->handled_by);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_admin_created_ticket_for_reseller_customer_is_platform_handled(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($admin)->post(route('tickets.store'), [
            'user_id' => $customer->id,
            'title' => 'Admin opened ticket',
            'description' => 'Follow up required',
            'priority' => 'medium',
        ])->assertRedirect();

        $ticket = Ticket::first();
        $this->assertNotNull($ticket);
        $this->assertSame(TicketHandledBy::Platform, $ticket->fresh()->handled_by);
        $this->assertSame($reseller->id, $ticket->reseller_id);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_unescalated_reseller_customer_ticket_is_hidden_from_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'handled_by' => TicketHandledBy::Reseller->value,
            'title' => 'Hidden from admin',
            'description' => 'Reseller handles this',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($admin)->get(route('tickets.index'))->assertOk()->assertDontSee('Hidden from admin');
    }

    public function test_reseller_can_escalate_customer_ticket_to_platform(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $admin = User::factory()->create(['is_admin' => true]);

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'handled_by' => TicketHandledBy::Reseller->value,
            'title' => 'Needs platform help',
            'description' => 'Complex issue',
            'status' => 'open',
            'priority' => 'high',
        ]);

        $this->actingAs($reseller)->post(route('reseller.tickets.escalate', $ticket), [
            'escalation_note' => 'Please take over',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketHandledBy::Platform, $ticket->handled_by);
        $this->assertNotNull($ticket->escalated_at);
        $this->assertSame('Please take over', $ticket->escalation_note);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_reseller_own_ticket_goes_to_platform(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);

        $this->actingAs($reseller)->post(route('reseller.tickets.store'), [
            'title' => 'Reseller platform issue',
            'description' => 'Need help from talksasa',
            'priority' => 'low',
        ])->assertRedirect();

        $ticket = Ticket::first();
        $this->assertNull($ticket->reseller_id);
        $this->assertSame(TicketHandledBy::Platform, $ticket->handled_by);
    }
}
