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

    private function createLanguage(): ContainerTemplate
    {
        $language = ContainerTemplate::firstOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel Application',
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

    public function test_laravel_shared_platform_routes_to_directadmin_products(): void
    {
        $language = $this->createLanguage();
        $database = $this->createDatabase('directadmin');

        $routing = TechStackRoutingService::determineHostingType($language, $database, 'shared');

        $this->assertSame('directadmin', $routing['hosting_type']);
        $this->assertSame('shared', $routing['deployment_platform']);
    }

    public function test_laravel_container_platform_routes_to_container_products(): void
    {
        $language = $this->createLanguage();
        $database = $this->createDatabase('container');

        $routing = TechStackRoutingService::determineHostingType($language, $database, 'container');

        $this->assertSame('container', $routing['hosting_type']);
        $this->assertSame('container', $routing['deployment_platform']);
    }

    public function test_laravel_databases_are_filtered_by_deployment_platform(): void
    {
        DatabaseTemplate::query()->delete();

        $language = $this->createLanguage();
        $this->createDatabase('directadmin');
        $this->createDatabase('container');

        $sharedDatabases = TechStackRoutingService::getAvailableDatabasesForLanguage($language, 'shared');
        $containerDatabases = TechStackRoutingService::getAvailableDatabasesForLanguage($language, 'container');

        $this->assertCount(1, $sharedDatabases);
        $this->assertSame('directadmin', $sharedDatabases->first()->hosting_type);
        $this->assertCount(1, $containerDatabases);
        $this->assertSame('container', $containerDatabases->first()->hosting_type);
    }
}
