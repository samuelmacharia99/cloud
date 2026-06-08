<?php

namespace Tests\Feature\Admin;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTicketFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_filters_stack_status_and_priority(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create();

        Ticket::create([
            'user_id' => $customer->id,
            'title' => 'Urgent open issue',
            'description' => 'Needs help',
            'status' => 'open',
            'priority' => 'urgent',
        ]);

        Ticket::create([
            'user_id' => $customer->id,
            'title' => 'Low priority closed',
            'description' => 'Done',
            'status' => 'closed',
            'priority' => 'low',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('tickets.index', ['status' => 'open', 'priority' => 'urgent']));

        $response->assertOk();
        $response->assertSee('Urgent open issue');
        $response->assertDontSee('Low priority closed');
    }
}
