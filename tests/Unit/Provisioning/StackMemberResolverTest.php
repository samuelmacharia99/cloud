<?php

namespace Tests\Unit\Provisioning;

use App\Enums\StackMemberKind;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\StackMember;
use App\Services\Provisioning\StackMemberResolver;
use App\Services\Provisioning\StackMemberState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\BootsBareFacades;

/**
 * The project page must show every container a stack runs, classified the
 * way the deploy path classifies them, with the words the page has always
 * used: Backend, Frontend, Edge, Database.
 */
class StackMemberResolverTest extends TestCase
{
    use BootsBareFacades;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBareFacades();
    }

    protected function tearDown(): void
    {
        $this->tearDownBareFacades();
        parent::tearDown();
    }

    #[Test]
    public function wordpress_shows_the_app_and_its_mysql_as_a_database(): void
    {
        $members = $this->resolve(<<<'YAML'
services:
  user-1-service-9-wordpress:
    image: wordpress:6
    container_name: user-1-service-9-wordpress
  mysql:
    image: mysql:8.0
    container_name: user-1-service-9-wordpress-mysql
YAML, 'user-1-service-9-wordpress');

        $this->assertSame(['App', 'Database'], $this->labels($members));
        $this->assertSame([StackMemberKind::App, StackMemberKind::Database], $this->kinds($members));
        $this->assertSame('user-1-service-9-wordpress-mysql', $members[1]->containerName);
        $this->assertSame('mysql', $members[1]->databaseType);
        $this->assertTrue($members[1]->canRestartAlone());
        $this->assertFalse($members[0]->canRestartAlone());
    }

    #[Test]
    public function an_injected_postgres_sidecar_is_a_database(): void
    {
        $members = $this->resolve(<<<'YAML'
services:
  user-1-service-9-laravel:
    image: talksasa/laravel-runtime:8.3
  db:
    image: pgvector/pgvector:pg16
    container_name: user-1-service-9-laravel-db
YAML, 'user-1-service-9-laravel');

        $this->assertSame(['App', 'Database'], $this->labels($members));
        $this->assertSame('postgresql', $members[1]->databaseType);
        $this->assertSame('db', $members[1]->composeKey);
    }

    #[Test]
    public function a_split_stack_lists_backend_frontend_edge_and_database(): void
    {
        $members = $this->resolve(<<<'YAML'
services:
  backend:
    image: talksasa/laravel-runtime:8.3
    container_name: user-1-service-9-laravel
  frontend:
    image: node:20
    container_name: user-1-service-9-laravel-frontend
  edge:
    image: nginx:alpine
    container_name: user-1-service-9-laravel-edge
  db:
    image: mysql:8
    container_name: user-1-service-9-laravel-db
YAML, 'user-1-service-9-laravel');

        $this->assertSame(['Backend', 'Frontend', 'Edge', 'Database'], $this->labels($members));
        $this->assertSame(
            ['user-1-service-9-laravel', 'user-1-service-9-laravel-frontend', 'user-1-service-9-laravel-edge', 'user-1-service-9-laravel-db'],
            array_map(fn (StackMember $m) => $m->containerName, $members)
        );
    }

    #[Test]
    public function chatwoot_sidecars_without_container_names_get_compose_v2_names(): void
    {
        $members = $this->resolve(<<<'YAML'
services:
  user-1-service-9-chatwoot:
    image: chatwoot/chatwoot:latest
  redis:
    image: redis:7-alpine
  sidekiq:
    image: chatwoot/chatwoot:latest
  db:
    image: postgres:16
    container_name: user-1-service-9-chatwoot-db
YAML, 'user-1-service-9-chatwoot');

        $this->assertSame(['App', 'Database', 'Redis', 'Sidekiq'], $this->labels($members));
        $this->assertSame([StackMemberKind::App, StackMemberKind::Database, StackMemberKind::Cache, StackMemberKind::Worker], $this->kinds($members));
        $this->assertSame('user-1-service-9-chatwoot-redis-1', $members[2]->containerName);
        $this->assertSame('user-1-service-9-chatwoot-sidekiq-1', $members[3]->containerName);
        $this->assertTrue($members[2]->canRestartAlone(), 'a cache may be restarted alone');
        $this->assertFalse($members[3]->canRestartAlone(), 'a worker restarts with the stack');
    }

    #[Test]
    public function a_role_split_api_service_labels_its_app_container_backend(): void
    {
        $members = $this->resolve(<<<'YAML'
services:
  user-1-service-9-laravel:
    image: talksasa/laravel-runtime:8.3
YAML, 'user-1-service-9-laravel', ['project_role' => 'backend', 'project_recipe' => 'laravel_next']);

        $this->assertSame(['Backend'], $this->labels($members));
    }

    #[Test]
    public function unreadable_yaml_falls_back_to_a_synthesized_app_with_unknown_state(): void
    {
        $service = $this->service("garbage: [unclosed\n  - :", 'user-1-service-9-nodejs');

        $members = (new StackMemberResolver)->membersForService($service);

        $this->assertCount(1, $members);
        $this->assertTrue($members[0]->synthesized);
        $this->assertSame(StackMemberState::UNKNOWN, $members[0]->state->state);
        $this->assertFalse($members[0]->canRestartAlone());
    }

    #[Test]
    public function a_laravel_next_intent_without_a_deployment_is_synthesized_as_pending(): void
    {
        $service = new Service;
        $service->id = 9;
        $service->service_meta = ['frontend' => 'nextjs', 'backend' => 'laravel', 'database_id' => 'mysql'];
        $service->setRelation('containerDeployment', null);
        $service->setRelation('product', null);

        $members = (new StackMemberResolver)->membersForService($service);

        $this->assertSame(['Backend', 'Frontend', 'Edge', 'Database'], $this->labels($members));
        foreach ($members as $member) {
            $this->assertTrue($member->synthesized);
            $this->assertSame(StackMemberState::PENDING, $member->state->state);
        }
    }

    #[Test]
    public function a_template_with_bundled_services_is_synthesized_before_the_first_deploy(): void
    {
        $template = new ContainerTemplate(['slug' => 'ospos', 'compose_services' => ['db' => ['image' => 'mariadb:10.11']]]);
        $product = new Product;
        $product->setRelation('containerTemplate', $template);

        $service = new Service;
        $service->id = 9;
        $service->service_meta = [];
        $service->setRelation('containerDeployment', null);
        $service->setRelation('product', $product);

        $members = (new StackMemberResolver)->membersForService($service);

        $this->assertSame(['App', 'Database'], $this->labels($members));
        $this->assertSame('mariadb', $members[1]->databaseType);
        $this->assertTrue($members[1]->synthesized);
    }

    #[Test]
    public function the_database_member_lookup_and_key_lookup_find_the_right_container(): void
    {
        $service = $this->service(<<<'YAML'
services:
  user-1-service-9-laravel:
    image: talksasa/laravel-runtime:8.3
  db:
    image: mysql:8
    container_name: user-1-service-9-laravel-db
YAML, 'user-1-service-9-laravel');

        $resolver = new StackMemberResolver;
        $this->assertSame('user-1-service-9-laravel-db', $resolver->databaseMember($service)?->containerName);
        $this->assertSame(StackMemberKind::App, $resolver->member($service, 'user-1-service-9-laravel')?->kind);
        $this->assertNull($resolver->member($service, 'nope'));
        $this->assertTrue($resolver->hasDatabaseMember($service->containerDeployment));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<StackMember>
     */
    private function resolve(string $yaml, string $containerName, array $meta = []): array
    {
        return (new StackMemberResolver)->membersForService($this->service($yaml, $containerName, $meta));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function service(string $yaml, string $containerName, array $meta = []): Service
    {
        $deployment = new ContainerDeployment;
        $deployment->container_name = $containerName;
        $deployment->status = 'running';
        $deployment->setAttribute('docker_compose_content', $yaml);

        $service = new Service;
        $service->id = 9;
        $service->service_meta = $meta;
        $service->setRelation('containerDeployment', $deployment);
        $service->setRelation('product', null);
        $deployment->setRelation('service', $service);

        return $service;
    }

    /**
     * @param  list<StackMember>  $members
     * @return list<string>
     */
    private function labels(array $members): array
    {
        return array_map(fn (StackMember $m) => $m->label, $members);
    }

    /**
     * @param  list<StackMember>  $members
     * @return list<StackMemberKind>
     */
    private function kinds(array $members): array
    {
        return array_map(fn (StackMember $m) => $m->kind, $members);
    }
}
