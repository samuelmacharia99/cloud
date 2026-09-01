<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerHermesOllamaLinkService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerHermesOllamaLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_docker_dns_when_hermes_and_ollama_share_a_node(): void
    {
        [$hermes, $ollama] = $this->makePairedServices();

        $url = $this->service()->resolveOllamaBaseUrl($hermes, $ollama);

        $this->assertSame('http://'.$ollama->containerDeployment->container_name.':11434', $url);
        $this->assertSame($url.'/v1', $this->service()->openaiCompatibleUrl($url));
        $this->assertSame('private Docker network', $this->service()->describeEndpoint($url));
    }

    #[Test]
    public function it_uses_ollama_public_url_when_containers_are_on_different_nodes(): void
    {
        [$hermes, $ollama] = $this->makePairedServices(sameNode: false);

        $url = $this->service()->resolveOllamaBaseUrl($hermes, $ollama);

        $this->assertSame('http://'.$ollama->containerDeployment->node->hostname.':32101', $url);
        $this->assertSame($url.'/v1', $this->service()->openaiCompatibleUrl($url));
        $this->assertSame('public URL', $this->service()->describeEndpoint($url));
    }

    #[Test]
    public function it_does_not_double_the_openai_v1_suffix(): void
    {
        $this->assertSame(
            'http://user-4-service-338-ollama:11434/v1',
            $this->service()->openaiCompatibleUrl('http://user-4-service-338-ollama:11434/v1/')
        );
    }

    #[Test]
    public function it_sets_ollama_env_without_replacing_a_real_openai_key(): void
    {
        [$hermes, $ollama] = $this->makePairedServices();
        $hermes->containerDeployment->env_values = array_merge(
            $hermes->containerDeployment->env_values ?? [],
            ['OPENAI_API_KEY' => 'sk-live-not-a-dummy']
        );

        $base = 'http://'.$ollama->containerDeployment->container_name.':11434';
        $patch = $this->service()->buildLinkEnvironment(
            $hermes,
            $ollama,
            $base,
            $base.'/v1',
            'mistral:latest'
        );

        $this->assertSame($base, $patch['OLLAMA_BASE_URL']);
        $this->assertSame($base.'/v1', $patch['OPENAI_BASE_URL']);
        $this->assertSame('1800', $patch['HERMES_API_TIMEOUT']);
        $this->assertSame('180', $patch['HERMES_WS_WRITE_TIMEOUT']);
        $this->assertSame((string) $ollama->id, $patch['TALKSASA_OLLAMA_SERVICE_ID']);
        $this->assertSame('mistral:latest', $patch['TALKSASA_OLLAMA_MODEL']);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $patch);
    }

    #[Test]
    public function it_sets_a_dummy_openai_key_when_none_exists(): void
    {
        [$hermes, $ollama] = $this->makePairedServices();
        $base = 'http://'.$ollama->containerDeployment->container_name.':11434';

        $patch = $this->service()->buildLinkEnvironment($hermes, $ollama, $base, $base.'/v1', 'mistral:latest');

        $this->assertSame('no-key', $patch['OPENAI_API_KEY']);
    }

    #[Test]
    public function it_builds_escaped_hermes_config_commands(): void
    {
        $commands = $this->service()->buildGatewayConfigCommands(
            'user-4-service-339-hermes',
            'http://user-4-service-338-ollama:11434',
            'llama3-1-hermes'
        );

        $this->assertCount(5, $commands);
        $this->assertStringContainsString("docker exec 'user-4-service-339-hermes' hermes config set", $commands[0]);
        $this->assertStringContainsString("'model.provider' 'ollama'", $commands[0]);
        $this->assertStringContainsString("'model.base_url' 'http://user-4-service-338-ollama:11434'", $commands[1]);
        $this->assertStringNotContainsString('/v1', $commands[1]);
        $this->assertStringContainsString("'model.default' 'llama3-1-hermes'", $commands[2]);
        $this->assertStringContainsString("'model.context_length' '65536'", $commands[3]);
        $this->assertStringContainsString("'model.ollama_num_ctx' '65536'", $commands[4]);
    }

    #[Test]
    public function it_flags_ollama_when_context_is_below_the_hermes_minimum(): void
    {
        [, $ollama] = $this->makePairedServices();

        $this->assertTrue($this->service()->ollamaNeedsAgentContext($ollama));

        $ollama->containerDeployment->env_values = array_merge(
            $ollama->containerDeployment->env_values ?? [],
            [
                'OLLAMA_CONTEXT_LENGTH' => '65536',
                'OLLAMA_NUM_CTX' => '65536',
            ]
        );

        $this->assertFalse($this->service()->ollamaNeedsAgentContext($ollama));
    }

    #[Test]
    public function it_rejects_linking_a_stopped_ollama(): void
    {
        [$hermes, $ollama] = $this->makePairedServices(ollamaStatus: 'stopped');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Start Ollama before connecting it to Hermes.');

        $this->service()->assertLinkable($hermes, $ollama);
    }

    #[Test]
    public function it_rejects_linking_ollama_from_another_project(): void
    {
        [$hermes, $ollama] = $this->makePairedServices();
        $otherProject = CustomerProject::factory()->create(['user_id' => $hermes->user_id]);
        $ollama->update(['project_id' => $otherProject->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Hermes and Ollama must be in the same project.');

        $this->service()->assertLinkable($hermes->fresh(), $ollama->fresh());
    }

    #[Test]
    public function panel_lists_same_project_ollama_services(): void
    {
        [$hermes, $ollama] = $this->makePairedServices();

        $panel = $this->service()->panelState($hermes);

        $this->assertSame($hermes->project_id, $panel['project_id']);
        $this->assertCount(1, $panel['candidates']);
        $this->assertSame($ollama->id, $panel['candidates'][0]['id']);
        $this->assertTrue($panel['candidates'][0]['running']);
        $this->assertNull($panel['connected']);
    }

    private function service(): ContainerHermesOllamaLinkService
    {
        return app(ContainerHermesOllamaLinkService::class);
    }

    /**
     * @return array{0: Service, 1: Service}
     */
    private function makePairedServices(bool $sameNode = true, string $ollamaStatus = 'running'): array
    {
        $customer = User::factory()->customer()->create();
        $project = CustomerProject::factory()->create(['user_id' => $customer->id]);
        $node = Node::factory()->create([
            'type' => 'container_host',
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'is_active' => true,
        ]);
        $ollamaNode = $sameNode ? $node : Node::factory()->create([
            'type' => 'container_host',
            'hostname' => 'ollama-remote.example.test',
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'is_active' => true,
        ]);

        $hermes = $this->makeStackService($customer, $project, $node, 'hermes', 'Hermes Agent', 31010);
        $ollama = $this->makeStackService($customer, $project, $ollamaNode, 'ollama', 'Project Ollama', 32101, $ollamaStatus);

        return [
            $hermes->fresh(['product.containerTemplate', 'containerDeployment.node']),
            $ollama->fresh(['product.containerTemplate', 'containerDeployment.node']),
        ];
    }

    private function makeStackService(
        User $customer,
        CustomerProject $project,
        Node $node,
        string $slug,
        string $name,
        int $port,
        string $status = 'running',
    ): Service {
        $defaults = $slug === 'ollama'
            ? [
                'description' => 'Local LLM',
                'docker_image' => 'ollama/ollama:latest',
                'default_port' => 11434,
                'required_ram_mb' => 8192,
                'required_cpu_cores' => 2,
                'required_storage_gb' => 20,
                'volume_paths' => ['ollama_data' => '/root/.ollama'],
            ]
            : [
                'description' => 'Hermes',
                'docker_image' => 'nousresearch/hermes-agent:latest',
                'default_port' => 9119,
                'required_ram_mb' => 2048,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 10,
                'volume_paths' => ['hermes_data' => '/opt/data'],
            ];

        $template = ContainerTemplate::query()->firstOrCreate(
            ['slug' => $slug],
            array_merge([
                'name' => $name,
                'category' => 'web',
                'is_active' => true,
                'order' => 0,
                'hosting_type' => 'container',
            ], $defaults)
        );
        $template->forceFill([
            'hosting_type' => 'container',
            'is_active' => true,
            'name' => $name,
        ])->save();

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'name' => $name.' Plan',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'status' => 'active',
            'service_meta' => ['language_slug' => $slug],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => $status,
            'assigned_port' => $port,
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-'.$slug,
            'env_values' => $slug === 'hermes'
                ? [
                    'HERMES_DASHBOARD' => '1',
                    'HERMES_DASHBOARD_BASIC_AUTH_USERNAME' => 'admin',
                    'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD' => 'keep-this-dashboard-password',
                ]
                : ['OLLAMA_KEEP_ALIVE' => '24h'],
            'selected_version' => $slug === 'ollama' ? '7b' : 'latest',
        ]);

        return $service;
    }
}
