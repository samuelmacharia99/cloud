<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Service;
use App\Services\Provisioning\ContainerDatabaseSidecarResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A DatabaseTemplate record exists only when the database was bought at
 * checkout. Templates that ship their own sidecar, WordPress above all, never
 * get one, so code that asked for the record to learn the type concluded those
 * sites had no database and refused to repair a container that was running the
 * whole time. The running stack is the authority.
 */
class ContainerDatabaseSidecarResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_wordpress_stack_with_no_database_record_still_reports_mysql(): void
    {
        $compose = <<<'YAML'
        services:
          wordpress:
            image: wordpress:latest
          mysql:
            image: mysql:8.0
        YAML;

        $this->assertSame('mysql', $this->resolver()->typeForService($this->service(), $compose));
    }

    #[Test]
    public function the_database_bought_at_checkout_wins_over_whatever_is_running(): void
    {
        $service = $this->service();
        $template = DatabaseTemplate::create([
            'name' => 'PostgreSQL 16',
            'slug' => 'postgresql-16-'.uniqid(),
            'type' => 'postgresql',
            'version' => '16',
            'default_port' => 5432,
            'hosting_type' => 'container',
            'is_active' => true,
        ]);
        $service->update(['service_meta' => array_merge($service->service_meta, ['database_id' => $template->id])]);

        $compose = "services:\n  db:\n    image: mysql:8.0\n";

        $this->assertSame('postgresql', $this->resolver()->typeForService($service->fresh(), $compose));
    }

    #[Test]
    public function the_image_decides_when_the_service_is_only_called_db(): void
    {
        // A service key of "db" says nothing. mariadb and mysql are different
        // enough at the socket that guessing between them is not acceptable.
        $compose = "services:\n  db:\n    image: mariadb:11\n";

        $this->assertSame('mariadb', $this->resolver()->typeFromComposeYaml($compose));
    }

    #[Test]
    public function a_service_key_is_read_only_when_no_image_names_a_database(): void
    {
        $compose = "services:\n  app:\n    build: .\n  postgres:\n    build: ./db\n";

        $this->assertSame('postgresql', $this->resolver()->typeFromComposeYaml($compose));
    }

    #[Test]
    public function the_container_template_answers_when_the_node_compose_cannot_be_read(): void
    {
        $service = $this->service(['mysql' => ['image' => 'mysql:8.0']]);

        $this->assertSame('mysql', $this->resolver()->typeForService($service, ''));
    }

    #[Test]
    public function a_stack_with_no_database_anywhere_is_still_refused(): void
    {
        $service = $this->service(['web' => ['image' => 'nginx:alpine']]);

        $this->assertNull($this->resolver()->typeForService($service, "services:\n  web:\n    image: nginx:alpine\n"));
    }

    #[Test]
    public function unreadable_compose_is_not_mistaken_for_an_answer(): void
    {
        $this->assertNull($this->resolver()->typeFromComposeYaml('services: [ this is not: valid: yaml'));
        $this->assertNull($this->resolver()->typeFromComposeYaml(''));
        $this->assertNull($this->resolver()->typeFromComposeYaml(null));
    }

    private function resolver(): ContainerDatabaseSidecarResolver
    {
        return app(ContainerDatabaseSidecarResolver::class);
    }

    /**
     * @param  array<string, mixed>|null  $composeServices
     */
    private function service(?array $composeServices = null): Service
    {
        $template = ContainerTemplate::factory()->create([
            'slug' => 'wordpress-'.uniqid(),
            'compose_services' => $composeServices ?? ['wordpress' => [], 'mysql' => []],
        ]);

        return Service::factory()->create([
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'service_meta' => ['provision_template_slug' => $template->slug, 'container_template_id' => $template->id],
        ]);
    }
}
