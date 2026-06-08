<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_credits_index(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.credits.index'))
            ->assertOk()
            ->assertSee('Customer Credits');
    }

    public function test_admin_can_view_reports_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Platform Reports');
    }

    public function test_admin_can_view_activity_log(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Admin Audit Log');
    }
}
