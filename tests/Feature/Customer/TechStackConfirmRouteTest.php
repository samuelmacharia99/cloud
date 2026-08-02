<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechStackConfirmRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_confirm_techstack_without_session_redirects_to_selection(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('customer.confirm-techstack'))
            ->assertRedirect(route('customer.select-techstack'));
    }

    public function test_get_confirm_techstack_works_after_post_redirect(): void
    {
        $customer = User::factory()->customer()->create();

        session(['selected_techstack' => [
            'language_id' => 999,
            'language_name' => 'Missing',
            'hosting_type' => 'container',
        ]]);

        $this->actingAs($customer)
            ->get(route('customer.confirm-techstack'))
            ->assertRedirect(route('customer.select-techstack'));
    }

    public function test_confirm_techstack_stores_stack_builder_roles_in_session(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('laravel');
        $database = $this->makeDatabase('postgresql');
        $this->makeProductForLanguage($language);

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'database_id' => $database->id,
                'framework' => 'laravel',
                'frontend' => 'nextjs',
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.confirm-techstack'));

        $techstack = session('selected_techstack');
        $this->assertIsArray($techstack);
        $this->assertSame($language->id, $techstack['language_id']);
        $this->assertSame('laravel', $techstack['language_slug']);
        $this->assertSame('laravel', $techstack['backend']);
        $this->assertSame('nextjs', $techstack['frontend']);
        $this->assertSame($database->id, $techstack['database_id']);
        $this->assertSame(1, $techstack['stack_builder_version']);
    }

    public function test_confirm_techstack_rejects_invalid_wordpress_frontend(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('wordpress');
        $database = $this->makeDatabase('mysql');
        $this->makeProductForLanguage($language);

        $this->actingAs($customer)
            ->from(route('customer.select-techstack'))
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'database_id' => $database->id,
                'frontend' => 'nextjs',
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.select-techstack'))
            ->assertSessionHas('error');
    }

    public function test_stack_options_endpoint_returns_role_matrix(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('nodejs');

        $response = $this->actingAs($customer)
            ->getJson(route('api.languages.stack-options', $language))
            ->assertOk()
            ->assertJsonPath('backend', 'nodejs')
            ->assertJsonPath('framework.required', true)
            ->assertJsonPath('frontend.show', true);

        $frameworks = collect($response->json('framework.options'))->pluck('value')->all();
        $this->assertContains('express', $frameworks);
        $this->assertContains('nextjs', $frameworks);
    }

    public function test_nodejs_next_framework_locks_frontend_in_stack_options(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('nodejs');

        $this->actingAs($customer)
            ->getJson(route('api.languages.stack-options', ['language' => $language->id, 'framework' => 'nextjs']))
            ->assertOk()
            ->assertJsonPath('frontend.value', 'nextjs')
            ->assertJsonCount(1, 'frontend.options');
    }

    private function makeLanguage(string $slug): ContainerTemplate
    {
        $language = ContainerTemplate::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst($slug),
                'description' => 'Test '.$slug,
                'category' => 'web',
                'docker_image' => 'nginx:latest',
                'default_port' => 80,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 2,
                'is_active' => true,
                'order' => 0,
                'hosting_type' => 'container',
            ]
        );

        $language->forceFill([
            'hosting_type' => 'container',
            'is_active' => true,
            'name' => ucfirst($slug),
        ])->save();

        return $language->fresh();
    }

    private function makeDatabase(string $type): DatabaseTemplate
    {
        return DatabaseTemplate::create([
            'name' => strtoupper($type),
            'slug' => $type.'-stack-feature-'.uniqid(),
            'description' => $type,
            'type' => $type,
            'docker_image' => $type.':latest',
            'default_port' => $type === 'postgresql' ? 5432 : 3306,
            'required_ram_mb' => 256,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 1,
        ]);
    }

    private function makeProductForLanguage(ContainerTemplate $language): Product
    {
        return Product::factory()->containerHosting()->create([
            'container_template_id' => $language->id,
            'is_active' => true,
            'name' => $language->name.' Plan',
            'monthly_price' => 19.99,
        ]);
    }
}
