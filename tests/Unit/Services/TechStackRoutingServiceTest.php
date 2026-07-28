<?php

namespace Tests\Unit\Services;

use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Services\TechStackRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechStackRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createLanguage(string $slug = 'laravel'): ContainerTemplate
    {
        $language = ContainerTemplate::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst($slug).' Application',
                'description' => 'Test language',
                'category' => 'web',
                'docker_image' => 'test:latest',
                'default_port' => 8000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 1,
                'is_active' => true,
                'order' => 1,
            ]
        );

        $language->forceFill(['hosting_type' => 'directadmin'])->save();

        return $language->fresh();
    }

    private function createDatabase(string $hostingType): DatabaseTemplate
    {
        return DatabaseTemplate::updateOrCreate(
            ['slug' => 'mysql-test-'.$hostingType],
            [
                'name' => 'MySQL Test '.$hostingType,
                'description' => 'Test database',
                'type' => 'mysql',
                'docker_image' => 'mysql:8.0',
                'default_port' => 3306,
                'required_ram_mb' => 256,
                'hosting_type' => $hostingType,
                'is_active' => true,
                'order' => 1,
            ]
        );
    }

    public function test_platform_techstack_always_routes_to_container(): void
    {
        $language = $this->createLanguage();
        $database = $this->createDatabase('container');

        $routing = TechStackRoutingService::determineHostingType($language, $database, 'shared');

        $this->assertSame('container', $routing['hosting_type']);
        $this->assertNull($routing['deployment_platform']);
    }

    public function test_laravel_container_platform_routes_to_container_products(): void
    {
        $language = $this->createLanguage();
        $database = $this->createDatabase('container');

        $routing = TechStackRoutingService::determineHostingType($language, $database, 'container');

        $this->assertSame('container', $routing['hosting_type']);
        $this->assertSame('container', $routing['deployment_platform']);
    }

    public function test_laravel_databases_are_container_only(): void
    {
        DatabaseTemplate::query()->delete();

        $language = $this->createLanguage();
        $this->createDatabase('directadmin');
        $this->createDatabase('container');

        $databases = TechStackRoutingService::getAvailableDatabasesForLanguage($language);

        $this->assertCount(1, $databases);
        $this->assertSame('container', $databases->first()->hosting_type);
    }

    public function test_directadmin_databases_are_invalid_for_php_stacks(): void
    {
        $language = $this->createLanguage('php');
        $database = $this->createDatabase('directadmin');

        $this->assertFalse(TechStackRoutingService::isValidCombination($language, $database));
        $this->assertTrue(TechStackRoutingService::isValidCombination(
            $language,
            $this->createDatabase('container')
        ));
    }

    public function test_wordpress_routes_to_container_only(): void
    {
        $language = $this->createLanguage('wordpress');
        $database = $this->createDatabase('container');

        $routing = TechStackRoutingService::determineHostingType($language, $database, 'shared');

        $this->assertSame('container', $routing['hosting_type']);
        $this->assertFalse(TechStackRoutingService::supportsDeploymentPlatformChoice($language));
    }

    public function test_directadmin_database_languages_are_empty(): void
    {
        $database = $this->createDatabase('directadmin');

        $this->assertCount(0, TechStackRoutingService::getAvailableLanguagesForDatabase($database));
    }
}
