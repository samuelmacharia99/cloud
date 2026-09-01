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

    public function test_laravel_offers_all_container_database_types(): void
    {
        DatabaseTemplate::query()->delete();

        $language = $this->createLanguage('laravel');
        foreach (['mysql', 'mariadb', 'postgresql', 'mongodb', 'redis'] as $type) {
            DatabaseTemplate::create([
                'name' => strtoupper($type),
                'slug' => $type.'-laravel-test',
                'description' => $type,
                'type' => $type,
                'docker_image' => $type.':latest',
                'default_port' => 5432,
                'required_ram_mb' => 256,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 1,
            ]);
        }

        $databases = TechStackRoutingService::getAvailableDatabasesForLanguage($language);
        $types = $databases->pluck('type')->sort()->values()->all();

        $this->assertSame(['mariadb', 'mongodb', 'mysql', 'postgresql', 'redis'], $types);
        $this->assertTrue(TechStackRoutingService::isValidCombination(
            $language,
            $databases->firstWhere('type', 'postgresql')
        ));
    }

    public function test_wordpress_still_limited_to_mysql_family(): void
    {
        DatabaseTemplate::query()->delete();

        $language = $this->createLanguage('wordpress');
        DatabaseTemplate::create([
            'name' => 'PostgreSQL',
            'slug' => 'postgres-wp-test',
            'description' => 'Postgres',
            'type' => 'postgresql',
            'docker_image' => 'postgres:16',
            'default_port' => 5432,
            'required_ram_mb' => 256,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 1,
        ]);
        $this->createDatabase('container');

        $databases = TechStackRoutingService::getAvailableDatabasesForLanguage($language);

        $this->assertCount(1, $databases);
        $this->assertSame('mysql', $databases->first()->type);
        $this->assertFalse(TechStackRoutingService::isValidCombination(
            $language,
            DatabaseTemplate::where('slug', 'postgres-wp-test')->first()
        ));
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

    public function test_wordpress_rejects_frontend_and_allows_mysql_only(): void
    {
        DatabaseTemplate::query()->delete();

        $language = $this->createLanguage('wordpress');
        $mysql = $this->createDatabase('container');
        $postgres = DatabaseTemplate::create([
            'name' => 'PostgreSQL',
            'slug' => 'postgres-wp-stack',
            'description' => 'Postgres',
            'type' => 'postgresql',
            'docker_image' => 'postgres:16',
            'default_port' => 5432,
            'required_ram_mb' => 256,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 1,
        ]);

        $this->assertTrue(TechStackRoutingService::isValidStackSelection($language, null, 'none', $mysql));
        $this->assertFalse(TechStackRoutingService::isValidStackSelection($language, null, 'nextjs', $mysql));
        $this->assertFalse(TechStackRoutingService::isValidStackSelection($language, null, 'none', $postgres));
        $this->assertFalse(TechStackRoutingService::isValidStackSelection($language, null, 'none', null));
    }

    public function test_laravel_allows_next_frontend_with_postgres(): void
    {
        DatabaseTemplate::query()->delete();

        $language = $this->createLanguage('laravel');
        $postgres = DatabaseTemplate::create([
            'name' => 'PostgreSQL',
            'slug' => 'postgres-laravel-stack',
            'description' => 'Postgres',
            'type' => 'postgresql',
            'docker_image' => 'postgres:16',
            'default_port' => 5432,
            'required_ram_mb' => 256,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 1,
        ]);

        $this->assertTrue(TechStackRoutingService::isValidStackSelection($language, null, 'nextjs', $postgres));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($language, 'laravel', 'vite-spa', $postgres));
        $this->assertFalse(TechStackRoutingService::isValidStackSelection($language, null, 'nextjs', null));
    }

    public function test_nodejs_requires_framework_and_locks_next_frontend(): void
    {
        $language = $this->createLanguage('nodejs');
        $language->forceFill(['hosting_type' => 'container'])->save();

        $this->assertFalse(TechStackRoutingService::isValidStackSelection($language, null, 'none', null));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($language, 'express', 'none', null));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($language, 'nextjs', 'nextjs', null));
        $this->assertFalse(TechStackRoutingService::isValidStackSelection($language, 'nextjs', 'vite-spa', null));
    }

    public function test_static_site_allows_null_database(): void
    {
        $language = $this->createLanguage('static-site');

        $this->assertTrue(TechStackRoutingService::isValidStackSelection($language, null, 'static', null));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($language, null, null, null));
        $this->assertTrue(TechStackRoutingService::isValidCombination($language, null));
        $this->assertTrue(TechStackRoutingService::skipsStackModal($language));
    }

    public function test_hermes_and_openclaw_skip_modal_and_allow_no_database(): void
    {
        $hermes = $this->createLanguage('hermes');
        $openclaw = $this->createLanguage('openclaw');

        $this->assertTrue(TechStackRoutingService::skipsStackModal($hermes));
        $this->assertTrue(TechStackRoutingService::skipsStackModal($openclaw));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($hermes, null, null, null));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($openclaw, null, '', null));
        $this->assertTrue(TechStackRoutingService::isValidCombination($hermes, null));
        $this->assertTrue(TechStackRoutingService::isValidCombination($openclaw, null));

        $payload = TechStackRoutingService::stackOptionsPayload($hermes);
        $this->assertTrue($payload['skip_modal']);
        $this->assertFalse($payload['framework']['show']);
        $this->assertFalse($payload['frontend']['show']);
        $this->assertFalse($payload['database']['show']);
    }

    public function test_catalog_stacks_require_the_right_databases(): void
    {
        DatabaseTemplate::query()->delete();

        $postgres = $this->createDatabase('container');
        $postgres->forceFill(['type' => 'postgresql', 'slug' => 'postgres-catalog-test'])->save();
        $mysql = DatabaseTemplate::create([
            'name' => 'MySQL',
            'slug' => 'mysql-catalog-test',
            'description' => 'MySQL',
            'type' => 'mysql',
            'docker_image' => 'mysql:8.0',
            'default_port' => 3306,
            'required_ram_mb' => 256,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 1,
        ]);

        $n8n = $this->createLanguage('n8n');
        $go = $this->createLanguage('go');
        $directus = $this->createLanguage('directus');
        $chatwoot = $this->createLanguage('chatwoot');
        $odoo = $this->createLanguage('odoo');
        $erpnext = $this->createLanguage('erpnext');

        $this->assertTrue(TechStackRoutingService::skipsStackModal($n8n));
        $this->assertTrue(TechStackRoutingService::skipsStackModal($erpnext));
        $this->assertFalse(TechStackRoutingService::skipsStackModal($directus));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($n8n, null, null, null));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($go, null, 'none', null));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($directus, null, null, $mysql));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($directus, null, null, $postgres));
        $this->assertFalse(TechStackRoutingService::isValidStackSelection($directus, null, null, null));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($chatwoot, null, null, $postgres));
        $this->assertFalse(TechStackRoutingService::isValidStackSelection($chatwoot, null, null, $mysql));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($odoo, null, null, $postgres));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($erpnext, null, null, null));

        $ollama = $this->createLanguage('ollama');
        $this->assertFalse(TechStackRoutingService::skipsStackModal($ollama));
        $this->assertTrue(TechStackRoutingService::isValidStackSelection($ollama, null, null, null));
        $this->assertTrue(TechStackRoutingService::isValidCombination($ollama, null));
        $this->assertFalse(TechStackRoutingService::usesSelectedVersionAsImageTag('ollama'));
        $this->assertSame(['7b', '8b'], TechStackRoutingService::requiredSelectedVersions($ollama));

        $payload = TechStackRoutingService::stackOptionsPayload($ollama);
        $this->assertFalse($payload['skip_modal']);
        $this->assertTrue($payload['version_picker']['show']);
        $this->assertTrue($payload['version_picker']['required']);
        $this->assertSame('Model size', $payload['version_picker']['label']);
        $this->assertSame('7b', $payload['version_picker']['value']);
        $this->assertSame('Mistral 7B', TechStackRoutingService::versionLabel($ollama, '7b'));
        $this->assertSame('Ministral 8B', TechStackRoutingService::versionLabel($ollama, '8b'));
    }

    public function test_apply_session_selection_copies_stack_builder_roles(): void
    {
        session(['selected_techstack' => [
            'language_id' => 12,
            'language_name' => 'Laravel',
            'language_slug' => 'laravel',
            'backend' => 'laravel',
            'framework' => 'laravel',
            'frontend' => 'nextjs',
            'database_id' => 4,
            'database_name' => 'PostgreSQL',
            'deployment_platform' => 'container',
            'stack_builder_version' => 1,
        ]]);

        $meta = TechStackRoutingService::applySessionSelectionToServiceMeta([]);

        $this->assertSame(12, $meta['container_template_id']);
        $this->assertSame('Laravel', $meta['application_stack']);
        $this->assertSame('laravel', $meta['language_slug']);
        $this->assertSame('laravel', $meta['backend']);
        $this->assertSame('laravel', $meta['framework']);
        $this->assertSame('nextjs', $meta['frontend']);
        $this->assertSame(4, $meta['database_id']);
        $this->assertSame(1, $meta['stack_builder_version']);
    }

    public function test_selection_summary_includes_frontend_label(): void
    {
        $summary = TechStackRoutingService::selectionSummary([
            'language_name' => 'Laravel',
            'frontend' => 'nextjs',
            'database_name' => 'PostgreSQL',
        ]);

        $this->assertStringContainsString('Laravel', $summary);
        $this->assertStringContainsString('Next.js', $summary);
        $this->assertStringNotContainsString('(later)', $summary);
        $this->assertStringContainsString('PostgreSQL', $summary);
    }
}
