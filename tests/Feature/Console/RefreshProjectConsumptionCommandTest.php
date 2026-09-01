<?php

namespace Tests\Feature\Console;

use App\Models\CustomerProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshProjectConsumptionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_writes_a_snapshot_for_each_project(): void
    {
        $customer = User::factory()->customer()->create();
        $project = CustomerProject::factory()->create(['user_id' => $customer->id]);

        $this->artisan('cron:refresh-project-consumption')
            ->assertSuccessful();

        $project->refresh();
        $this->assertNotNull($project->consumption_snapshot_at);
        $this->assertIsArray($project->consumption_snapshot);
        $this->assertSame(6, $project->consumption_snapshot['window_hours']);
    }
}
