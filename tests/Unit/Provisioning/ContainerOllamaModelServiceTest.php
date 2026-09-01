<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerOllamaModelService;
use App\Services\Provisioning\ContainerStackCommandService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerOllamaModelServiceTest extends TestCase
{
    #[Test]
    public function it_maps_mistral_family_sizes_to_official_library_tags(): void
    {
        $this->assertSame('7b', ContainerOllamaModelService::normalizeSize('7B'));
        $this->assertSame('8b', ContainerOllamaModelService::normalizeSize('8B'));
        $this->assertSame('7b', ContainerOllamaModelService::normalizeSize('latest'));
        $this->assertSame('mistral:7b', ContainerOllamaModelService::modelTag('7b'));
        $this->assertSame('ministral-3:8b', ContainerOllamaModelService::modelTag('8b'));
        $this->assertSame('mistral:7b', ContainerOllamaModelService::modelTag(null));
    }

    #[Test]
    public function it_parses_ollama_list_table_output(): void
    {
        $output = <<<'TXT'
NAME              ID              SIZE      MODIFIED
mistral:latest    abc123          4.4 GB    2 minutes ago
mistral:7b        def456          4.1 GB    1 hour ago
TXT;

        $this->assertSame(
            ['mistral:latest', 'mistral:7b'],
            $this->service()->parseListOutput($output)
        );
    }

    #[Test]
    public function it_keeps_user_text_out_of_the_shell_chat_command(): void
    {
        $deployment = new ContainerDeployment([
            'container_name' => 'user-4-service-338-ollama',
            'assigned_port' => 32101,
        ]);
        $payload = $this->service()->chatPayload('mistral:latest', [
            ['role' => 'user', 'content' => 'hello; rm -rf /'],
        ]);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $command = $this->service()->buildChatCommand($deployment, $json);

        $this->assertStringContainsString('http://127.0.0.1:32101/api/chat', $command);
        $this->assertStringContainsString('base64 -d', $command);
        $this->assertStringNotContainsString('rm -rf', $command);
        $this->assertStringNotContainsString('hello;', $command);
        $this->assertStringContainsString(base64_encode($json), $command);
    }

    #[Test]
    public function it_parses_a_non_streaming_chat_response(): void
    {
        $reply = $this->service()->parseChatResponse(
            json_encode([
                'model' => 'mistral:latest',
                'message' => ['role' => 'assistant', 'content' => 'Hello. How can I help?'],
                'done' => true,
            ]),
            'mistral:7b'
        );

        $this->assertSame('mistral:latest', $reply['model']);
        $this->assertSame('Hello. How can I help?', $reply['content']);
    }

    #[Test]
    public function it_rejects_an_empty_chat_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->chatPayload('mistral', [['role' => 'user', 'content' => '   ']]);
    }

    #[Test]
    public function it_prefers_an_installed_mistral_tag_over_the_planned_size(): void
    {
        $service = new Service;
        $service->service_meta = ['selected_version' => '7b'];
        $deployment = new ContainerDeployment([
            'selected_version' => '7b',
            'env_values' => ['OLLAMA_MODEL' => 'mistral:7b'],
        ]);

        $this->assertSame(
            'mistral:latest',
            $this->service()->defaultModelName($service, $deployment, ['mistral:latest'])
        );
    }

    private function service(): ContainerOllamaModelService
    {
        return new ContainerOllamaModelService($this->createMock(ContainerStackCommandService::class));
    }
}
