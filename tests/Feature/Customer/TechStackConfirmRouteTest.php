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

    public function test_hermes_confirms_without_database_or_frontend(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('hermes');
        $this->makeProductForLanguage($language);

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'database_id' => '',
                'frontend' => '',
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.confirm-techstack'));

        $techstack = session('selected_techstack');
        $this->assertSame($language->id, $techstack['language_id']);
        $this->assertSame('hermes', $techstack['language_slug']);
        $this->assertSame('none', $techstack['frontend']);
        $this->assertArrayNotHasKey('database_id', $techstack);
    }

    public function test_openclaw_stack_options_skip_the_builder_modal(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('openclaw');

        $this->actingAs($customer)
            ->getJson(route('api.languages.stack-options', $language))
            ->assertOk()
            ->assertJsonPath('skip_modal', true)
            ->assertJsonPath('backend', 'openclaw')
            ->assertJsonPath('database.show', false);
    }

    public function test_a_stack_with_a_required_version_picker_rejects_a_confirm_without_a_version(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('sized-runtime');
        $this->makeProductForLanguage($language);
        config(['stack_builder.stacks.sized-runtime' => $this->sizedRuntimeStackDefinition()]);

        $this->actingAs($customer)
            ->getJson(route('api.languages.stack-options', $language))
            ->assertOk()
            ->assertJsonPath('skip_modal', false)
            ->assertJsonPath('backend', 'sized-runtime')
            ->assertJsonPath('database.show', false)
            ->assertJsonPath('version_picker.show', true)
            ->assertJsonPath('version_picker.required', true)
            ->assertJsonPath('version_picker.value', 'small');

        $this->actingAs($customer)
            ->from(route('customer.select-techstack'))
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.select-techstack'));

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'selected_version' => 'large',
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.confirm-techstack'));

        $this->assertSame('large', session('selected_techstack.selected_version'));
    }

    public function test_nodejs_next_framework_locks_frontend_in_stack_options(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('nodejs');

        $this->actingAs($customer)
            ->getJson(route('api.languages.stack-options', ['language' => $language->id, 'framework' => 'nextjs']))
            ->assertOk()
            ->assertJsonPath('frontend.value', 'nextjs')
            ->assertJsonPath('version_picker.show', true)
            ->assertJsonPath('version_picker.required', false)
            ->assertJsonPath('version_picker.value', null)
            ->assertJsonFragment(['value' => '24-alpine', 'label' => 'Node 24-alpine'])
            ->assertJsonCount(1, 'frontend.options');
    }

    public function test_laravel_stack_options_offer_php_versions_and_confirm_keeps_the_choice(): void
    {
        $customer = User::factory()->customer()->create();
        $language = $this->makeLanguage('laravel');
        $this->makeProductForLanguage($language);
        $mysql = DatabaseTemplate::query()->firstOrCreate(['slug' => 'mysql-container-test'], [
            'name' => 'MySQL 8',
            'type' => 'mysql',
            'docker_image' => 'mysql:8.0',
            'default_port' => 3306,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 1,
        ]);

        $this->actingAs($customer)
            ->getJson(route('api.languages.stack-options', $language))
            ->assertOk()
            ->assertJsonPath('version_picker.show', true)
            ->assertJsonPath('version_picker.required', false)
            ->assertJsonPath('version_picker.label', 'PHP version')
            ->assertJsonPath('version_picker.value', '8.3')
            ->assertJsonPath('version_picker.options.3.value', '8.1');

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'frontend' => 'none',
                'database_id' => $mysql->id,
                'selected_version' => '8.2',
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.confirm-techstack'));
        $this->assertSame('8.2', session('selected_techstack.selected_version'));

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'frontend' => 'none',
                'database_id' => $mysql->id,
                'selected_version' => '7.4',
                'deployment_platform' => 'container',
            ])
            ->assertSessionHasErrors('selected_version');
    }

    /**
     * A stack whose version picker is a required, non-image-tag option. No
     * catalog stack declares one today, so the path is pinned through config.
     *
     * @return array<string, mixed>
     */
    private function sizedRuntimeStackDefinition(): array
    {
        return [
            'backend' => 'sized-runtime',
            'skip_modal' => false,
            'version_as_image_tag' => false,
            'version_picker' => [
                'show' => true,
                'required' => true,
                'label' => 'Size',
                'options' => [
                    ['value' => 'small', 'label' => 'Small'],
                    ['value' => 'large', 'label' => 'Large'],
                ],
            ],
            'framework' => ['required' => false, 'show' => false, 'options' => [], 'locked' => 'sized-runtime'],
            'frontend' => ['required' => false, 'show' => false, 'options' => ['none'], 'locked' => 'none'],
            'database' => ['required' => false, 'show' => false, 'allow_none' => true, 'types' => []],
        ];
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
