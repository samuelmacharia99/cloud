<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerTemplate;
use App\Models\User;
use Database\Seeders\ContainerTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentContainerTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_installs_official_hermes_and_openclaw_images(): void
    {
        $hermes = ContainerTemplate::query()->where('slug', 'hermes')->first();
        $openclaw = ContainerTemplate::query()->where('slug', 'openclaw')->first();

        $this->assertNotNull($hermes);
        $this->assertNotNull($openclaw);
        $this->assertSame('nousresearch/hermes-agent:latest', $hermes->docker_image);
        $this->assertSame(9119, $hermes->default_port);
        $this->assertSame('openclaw/openclaw:latest', $openclaw->docker_image);
        $this->assertSame(18789, $openclaw->default_port);
        $this->assertTrue($hermes->is_active);
        $this->assertTrue($openclaw->is_active);
    }

    public function test_seeder_definitions_match_official_images(): void
    {
        $definitions = ContainerTemplateSeeder::agentStackDefinitions();

        $this->assertSame('nousresearch/hermes-agent:latest', $definitions['hermes']['docker_image']);
        $this->assertSame('openclaw/openclaw:latest', $definitions['openclaw']['docker_image']);
        $this->assertSame('/opt/data', $definitions['hermes']['volume_paths']['hermes_data']);
        $this->assertSame('/home/node/.openclaw', $definitions['openclaw']['volume_paths']['openclaw_state']);
    }

    public function test_admin_index_lists_hermes_and_openclaw(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.container-templates.index'))
            ->assertOk()
            ->assertSee('Hermes Agent')
            ->assertSee('nousresearch/hermes-agent:latest')
            ->assertSee('OpenClaw')
            ->assertSee('openclaw/openclaw:latest')
            ->assertSee('#C9A227', false)
            ->assertSee('#FF4D1A', false);
    }
}
