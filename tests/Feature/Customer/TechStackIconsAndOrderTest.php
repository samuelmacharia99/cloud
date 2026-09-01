<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechStackIconsAndOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_techstack_page_shows_icons_and_priority_order(): void
    {
        foreach ([
            ['slug' => 'strapi', 'name' => 'Strapi', 'order' => 9],
            ['slug' => 'wordpress', 'name' => 'WordPress', 'order' => 50],
            ['slug' => 'python', 'name' => 'Python', 'order' => 40],
            ['slug' => 'static-site', 'name' => 'Static Website', 'order' => 30],
            ['slug' => 'nodejs', 'name' => 'Node.js', 'order' => 20],
        ] as $row) {
            ContainerTemplate::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => $row['name'].' stack',
                    'category' => 'web',
                    'docker_image' => 'test:latest',
                    'default_port' => 80,
                    'required_ram_mb' => 256,
                    'required_cpu_cores' => 1,
                    'required_storage_gb' => 1,
                    'is_active' => true,
                    'order' => $row['order'],
                ]
            );
        }

        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $this->actingAs($customer)
            ->get(route('customer.select-techstack'))
            ->assertOk()
            ->assertSee('#21759B', false)
            ->assertSee('#339933', false)
            ->assertSee('#C9A227', false)
            ->assertSee('#FF4D1A', false)
            ->assertSee('#EA4B71', false)
            ->assertSee('#00ADD8', false)
            ->assertSee('#111111', false)
            ->assertSeeInOrder([
                'WordPress',
                'Node.js',
                'Python',
                'Static Website',
                'Hermes Agent',
                'OpenClaw',
                'Ollama',
                'n8n',
                'Go Application',
                'Directus',
                'Chatwoot',
                'Odoo',
                'ERPNext',
                'Strapi',
            ]);
    }
}
