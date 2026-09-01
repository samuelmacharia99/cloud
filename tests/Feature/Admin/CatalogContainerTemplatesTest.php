<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerTemplate;
use App\Models\User;
use Database\Seeders\ContainerTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogContainerTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_installs_catalog_images(): void
    {
        $expected = [
            'n8n' => 'n8nio/n8n:latest',
            'go' => 'golang:1.23-bookworm',
            'directus' => 'directus/directus:latest',
            'chatwoot' => 'chatwoot/chatwoot:latest',
            'odoo' => 'odoo:18',
            'erpnext' => 'frappe/erpnext:v15',
        ];

        foreach ($expected as $slug => $image) {
            $template = ContainerTemplate::query()->where('slug', $slug)->first();
            $this->assertNotNull($template, $slug.' template missing');
            $this->assertSame($image, $template->docker_image);
            $this->assertTrue($template->is_active);
        }
    }

    public function test_seeder_definitions_match_official_images(): void
    {
        $definitions = ContainerTemplateSeeder::catalogStackDefinitions();

        $this->assertSame('n8nio/n8n:latest', $definitions['n8n']['docker_image']);
        $this->assertSame(5678, $definitions['n8n']['default_port']);
        $this->assertSame('golang:1.23-bookworm', $definitions['go']['docker_image']);
        $this->assertSame('directus/directus:latest', $definitions['directus']['docker_image']);
        $this->assertSame('chatwoot/chatwoot:latest', $definitions['chatwoot']['docker_image']);
        $this->assertArrayHasKey('sidekiq', $definitions['chatwoot']['compose_services']);
        $this->assertSame('odoo:18', $definitions['odoo']['docker_image']);
        $this->assertSame('frappe/erpnext:v15', $definitions['erpnext']['docker_image']);
        $this->assertArrayHasKey('db', $definitions['erpnext']['compose_services']);
    }

    public function test_admin_can_list_and_open_catalog_templates(): void
    {
        $admin = User::factory()->admin()->create();
        $n8n = ContainerTemplate::query()->where('slug', 'n8n')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.container-templates.index'))
            ->assertOk()
            ->assertSee('n8n')
            ->assertSee('Go Application')
            ->assertSee('Directus')
            ->assertSee('Chatwoot')
            ->assertSee('Odoo')
            ->assertSee('ERPNext')
            ->assertSee('#EA4B71', false)
            ->assertSee('#6644FF', false);

        $this->actingAs($admin)
            ->get(route('admin.container-templates.show', $n8n))
            ->assertOk()
            ->assertSee('n8nio/n8n:latest')
            ->assertSee('5678');
    }
}
