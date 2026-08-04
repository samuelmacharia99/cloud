<?php

namespace Tests\Unit\Billing;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Services\Billing\ProjectRecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectRecipeServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ContainerTemplate, 1: ContainerTemplate}
     */
    private function seedLaravelAndNodeTemplates(): array
    {
        $laravel = ContainerTemplate::query()->updateOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel',
                'description' => 'Laravel app',
                'category' => 'web',
                'docker_image' => 'laravel:latest',
                'default_port' => 8000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 5,
                'is_active' => true,
                'order' => 1,
            ]
        );

        $nodejs = ContainerTemplate::query()->updateOrCreate(
            ['slug' => 'nodejs'],
            [
                'name' => 'Node.js',
                'description' => 'Node app',
                'category' => 'web',
                'docker_image' => 'node:20',
                'default_port' => 3000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 5,
                'is_active' => true,
                'order' => 2,
            ]
        );

        return [$laravel, $nodejs];
    }

    public function test_matches_laravel_next_session(): void
    {
        $service = app(ProjectRecipeService::class);

        $this->assertSame('laravel_next', $service->matchKeyFromSession([
            'language_slug' => 'laravel',
            'frontend' => 'nextjs',
        ]));

        $this->assertNull($service->matchKeyFromSession([
            'language_slug' => 'laravel',
            'frontend' => 'vite-spa',
        ]));
    }

    public function test_expand_roles_builds_named_services(): void
    {
        [$laravel] = $this->seedLaravelAndNodeTemplates();

        $product = Product::factory()->create([
            'type' => 'container_hosting',
            'container_template_id' => $laravel->id,
            'is_active' => true,
        ]);

        $roles = app(ProjectRecipeService::class)->expandRoles(
            $product->fresh(['containerTemplate']),
            ['language_slug' => 'laravel', 'frontend' => 'nextjs'],
            'Acme App',
        );

        $this->assertCount(2, $roles);
        $this->assertSame('backend', $roles[0]['key']);
        $this->assertTrue($roles[0]['billing_anchor']);
        $this->assertSame('acme-app-api', $roles[0]['service_name']);
        $this->assertSame('frontend', $roles[1]['key']);
        $this->assertFalse($roles[1]['billing_anchor']);
        $this->assertSame('acme-app-web', $roles[1]['service_name']);
        $this->assertSame('nodejs', $roles[1]['provision_template_slug']);
    }

    public function test_should_skip_renewal_for_non_anchor_roles(): void
    {
        $service = app(ProjectRecipeService::class);

        $this->assertTrue($service->shouldSkipRenewalInvoice([
            'project_recipe' => 'laravel_next',
            'project_role' => 'frontend',
            'project_billing_anchor' => false,
        ]));

        $this->assertFalse($service->shouldSkipRenewalInvoice([
            'project_recipe' => 'laravel_next',
            'project_role' => 'backend',
            'project_billing_anchor' => true,
        ]));
    }
}
