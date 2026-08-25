<?php

namespace Tests\Feature\Admin;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceBulkDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_bulk_delete_terminated_services(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['name' => 'Fabian Test']);

        $first = Service::factory()->create([
            'user_id' => $customer->id,
            'status' => 'terminated',
        ]);
        $second = Service::factory()->create([
            'user_id' => $customer->id,
            'status' => 'terminated',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.services.index', ['status' => 'terminated']))
            ->post(route('admin.services.bulk-destroy'), [
                'ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('admin.services.index', ['status' => 'terminated']))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('services', ['id' => $first->id]);
        $this->assertDatabaseMissing('services', ['id' => $second->id]);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'service.bulk_destroy',
            'admin_user_id' => $admin->id,
        ]);
    }

    public function test_bulk_delete_rejects_active_services_and_deletes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $terminated = Service::factory()->create(['status' => 'terminated']);
        $active = Service::factory()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->from(route('admin.services.index'))
            ->post(route('admin.services.bulk-destroy'), [
                'ids' => [$terminated->id, $active->id],
            ])
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('services', ['id' => $terminated->id]);
        $this->assertDatabaseHas('services', ['id' => $active->id]);
    }

    public function test_bulk_delete_requires_at_least_one_id(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.services.index'))
            ->post(route('admin.services.bulk-destroy'), [
                'ids' => [],
            ])
            ->assertSessionHasErrors('ids');
    }

    public function test_customer_cannot_bulk_delete_services(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'status' => 'terminated',
        ]);

        $this->actingAs($customer)
            ->post(route('admin.services.bulk-destroy'), [
                'ids' => [$service->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }

    public function test_terminated_filter_shows_bulk_delete_controls(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['name' => 'Fabian Test']);
        $terminated = Service::factory()->create([
            'user_id' => $customer->id,
            'status' => 'terminated',
        ]);
        Service::factory()->create([
            'user_id' => $customer->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.services.index', [
                'search' => 'fabian',
                'status' => 'terminated',
                'type' => 'all',
            ]))
            ->assertOk()
            ->assertSee('Delete selected', false)
            ->assertSee(route('admin.services.bulk-destroy'), false)
            ->assertSee('aria-label="Select service #'.$terminated->id.'"', false);
    }
}
