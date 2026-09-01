<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerOllamaModelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ContainerOllamaChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_ollama_container_page_shows_a_chat_tab(): void
    {
        [$customer, $service] = $this->makeOllamaService();

        $this->mock(ContainerDeploymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getStatus')->andReturn([
                'status' => 'running',
                'healthy' => true,
            ]);
        });

        $this->actingAs($customer)
            ->get(route('customer.services.container.show', [
                'service' => $service,
                'tab' => 'chat',
            ]))
            ->assertOk()
            ->assertSee('Talk to the model running in this container')
            ->assertSee('Chat')
            ->assertSee('Message the model');
    }

    public function test_customer_can_chat_with_an_installed_model(): void
    {
        [$customer, $service] = $this->makeOllamaService();

        $this->mock(ContainerOllamaModelService::class, function (MockInterface $mock) use ($service) {
            $mock->shouldReceive('supportsService')->andReturn(true);
            $mock->shouldReceive('supportsTemplate')->andReturn(true);
            $mock->shouldReceive('defaultModelName')->andReturn('mistral:latest');
            $mock->shouldReceive('listModels')->andReturn(['mistral:latest']);
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($ssh, $deployment, $model, $messages) use ($service) {
                    return $deployment->is($service->containerDeployment)
                        && $model === 'mistral:latest'
                        && ($messages[array_key_last($messages)]['content'] ?? null) === 'hello';
                })
                ->andReturn([
                    'model' => 'mistral:latest',
                    'content' => 'Hello. How can I help you today?',
                ]);
        });

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.ollama.chat', $service), [
                'message' => 'hello',
                'model' => 'mistral:latest',
            ])
            ->assertOk()
            ->assertJsonPath('message.role', 'assistant')
            ->assertJsonPath('message.content', 'Hello. How can I help you today?');
    }

    public function test_empty_chat_message_is_rejected(): void
    {
        [$customer, $service] = $this->makeOllamaService();

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.ollama.chat', $service), [
                'message' => '   ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_non_ollama_services_cannot_use_chat(): void
    {
        [$customer, $service] = $this->makeOllamaService();
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
        $service->product->update(['container_template_id' => $nodejs->id]);
        $service->update(['service_meta' => ['language_slug' => 'nodejs']]);

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.ollama.chat', $service->fresh(['product.containerTemplate', 'containerDeployment.node'])), [
                'message' => 'hello',
            ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'Chat is only available on Ollama services.');
    }

    public function test_other_customers_cannot_chat(): void
    {
        [, $service] = $this->makeOllamaService();
        $other = User::factory()->customer()->create();

        $this->actingAs($other)
            ->postJson(route('customer.services.container.ollama.chat', $service), [
                'message' => 'hello',
            ])
            ->assertForbidden();
    }

    public function test_stopped_container_cannot_chat(): void
    {
        [$customer, $service] = $this->makeOllamaService('stopped');

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.ollama.chat', $service), [
                'message' => 'hello',
            ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'Start the app before chatting with the model.');
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function makeOllamaService(string $deploymentStatus = 'running'): array
    {
        $customer = User::factory()->customer()->create();
        $template = ContainerTemplate::query()->firstOrCreate(
            ['slug' => 'ollama'],
            [
                'name' => 'Ollama',
                'description' => 'Local LLM',
                'category' => 'web',
                'docker_image' => 'ollama/ollama:latest',
                'default_port' => 11434,
                'required_ram_mb' => 8192,
                'required_cpu_cores' => 2,
                'required_storage_gb' => 20,
                'is_active' => true,
                'order' => 0,
                'hosting_type' => 'container',
                'volume_paths' => ['ollama_data' => '/root/.ollama'],
            ]
        );
        $template->forceFill([
            'hosting_type' => 'container',
            'is_active' => true,
            'name' => 'Ollama',
        ])->save();

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'name' => 'Ollama App',
        ]);
        $node = Node::factory()->create([
            'type' => 'container_host',
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'is_active' => true,
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'service_meta' => ['language_slug' => 'ollama', 'selected_version' => '7b'],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => $deploymentStatus,
            'assigned_port' => 32101,
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-ollama',
            'selected_version' => '7b',
        ]);

        return [$customer, $service->fresh(['product.containerTemplate', 'containerDeployment.node'])];
    }
}
