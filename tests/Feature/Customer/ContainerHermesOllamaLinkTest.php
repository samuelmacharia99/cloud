<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerHermesOllamaLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ContainerHermesOllamaLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_hermes_overview_lists_project_ollama_to_connect(): void
    {
        [$customer, $hermes, $ollama] = $this->makePairedServices();

        $this->mock(ContainerDeploymentService::class, function (MockInterface $mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('getStatus')->andReturn([
                'status' => 'running',
                'healthy' => true,
            ]);
        });

        $this->actingAs($customer)
            ->get(route('customer.services.container.show', $hermes))
            ->assertOk()
            ->assertSee('Ollama in this project')
            ->assertSee('Connect Ollama')
            ->assertSee($ollama->name)
            ->assertSee('not offered as a new stack');
    }

    public function test_customer_can_connect_project_ollama_to_hermes(): void
    {
        [$customer, $hermes, $ollama] = $this->makePairedServices();

        $this->mock(ContainerHermesOllamaLinkService::class, function (MockInterface $mock) use ($hermes, $ollama) {
            $mock->shouldReceive('supportsHermes')->andReturn(true);
            $mock->shouldReceive('connect')
                ->once()
                ->withArgs(function ($linkedHermes, $linkedOllama, $model) use ($hermes, $ollama) {
                    return $linkedHermes->is($hermes)
                        && $linkedOllama->is($ollama)
                        && $model === null;
                })
                ->andReturn([
                    'message' => 'Hermes now uses Project Ollama (mistral:latest) over the private Docker network.',
                    'base_url' => 'http://'.$ollama->containerDeployment->container_name.':11434',
                    'openai_base_url' => 'http://'.$ollama->containerDeployment->container_name.':11434/v1',
                    'model' => 'mistral:latest',
                    'via' => 'private Docker network',
                    'warning' => null,
                ]);
        });

        $this->actingAs($customer)
            ->post(route('customer.services.container.hermes.ollama.connect', $hermes), [
                'ollama_service_id' => $ollama->id,
            ])
            ->assertRedirect(route('customer.services.container.show', [
                'service' => $hermes,
                'tab' => 'overview',
            ]))
            ->assertSessionHas('success');
    }

    public function test_non_hermes_services_cannot_connect_ollama(): void
    {
        [$customer, $hermes, $ollama] = $this->makePairedServices();
        $hermes->update(['service_meta' => ['language_slug' => 'nodejs']]);
        $nodejs = ContainerTemplate::query()->firstOrCreate(
            ['slug' => 'nodejs'],
            [
                'name' => 'Node.js',
                'category' => 'web',
                'docker_image' => 'node:20',
                'default_port' => 3000,
                'is_active' => true,
                'hosting_type' => 'container',
            ]
        );
        $hermes->product->update(['container_template_id' => $nodejs->id]);

        $this->actingAs($customer)
            ->from(route('customer.services.container.show', $hermes))
            ->post(route('customer.services.container.hermes.ollama.connect', $hermes->fresh(['product.containerTemplate', 'containerDeployment.node'])), [
                'ollama_service_id' => $ollama->id,
            ])
            ->assertRedirect(route('customer.services.container.show', [
                'service' => $hermes,
                'tab' => 'overview',
            ]))
            ->assertSessionHasErrors('error');
    }

    public function test_stopped_ollama_cannot_be_connected(): void
    {
        [$customer, $hermes, $ollama] = $this->makePairedServices(ollamaStatus: 'stopped');

        $this->actingAs($customer)
            ->from(route('customer.services.container.show', $hermes))
            ->post(route('customer.services.container.hermes.ollama.connect', $hermes), [
                'ollama_service_id' => $ollama->id,
            ])
            ->assertRedirect(route('customer.services.container.show', [
                'service' => $hermes,
                'tab' => 'overview',
            ]))
            ->assertSessionHasErrors('error');
    }

    public function test_other_customers_cannot_connect_ollama(): void
    {
        [, $hermes, $ollama] = $this->makePairedServices();
        $other = User::factory()->customer()->create();

        $this->actingAs($other)
            ->post(route('customer.services.container.hermes.ollama.connect', $hermes), [
                'ollama_service_id' => $ollama->id,
            ])
            ->assertForbidden();
    }

    public function test_missing_ollama_service_id_is_rejected(): void
    {
        [$customer, $hermes] = $this->makePairedServices();

        $this->actingAs($customer)
            ->from(route('customer.services.container.show', $hermes))
            ->post(route('customer.services.container.hermes.ollama.connect', $hermes), [])
            ->assertRedirect()
            ->assertSessionHasErrors('ollama_service_id');
    }

    /**
     * @return array{0: User, 1: Service, 2: Service}
     */
    private function makePairedServices(string $ollamaStatus = 'running'): array
    {
        $customer = User::factory()->customer()->create();
        $project = CustomerProject::factory()->create(['user_id' => $customer->id]);
        $node = Node::factory()->create([
            'type' => 'container_host',
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'is_active' => true,
        ]);

        $hermes = $this->makeStackService($customer, $project, $node, 'hermes', 'Hermes Agent', 31010);
        $ollama = $this->makeStackService($customer, $project, $node, 'ollama', 'Project Ollama', 32101, $ollamaStatus);

        return [
            $customer,
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
