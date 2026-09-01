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

    #[Test]
    public function it_builds_a_hermes_alias_modelfile_with_64k_num_ctx(): void
    {
        $deployment = new ContainerDeployment([
            'container_name' => 'user-4-service-338-ollama',
            'assigned_port' => 32101,
        ]);
        $models = $this->service();

        $this->assertSame('mistral-hermes', $models->hermesAliasName('mistral:7b'));
        $this->assertSame('ministral-3-hermes', $models->hermesAliasName('ministral-3:8b'));
        $this->assertSame('llama3-1-hermes', $models->hermesAliasName('llama3.1:8b'));

        $create = $models->buildCreateHermesAliasCommand($deployment, 'mistral:7b', 'mistral-hermes');
        $this->assertStringContainsString("docker exec 'user-4-service-338-ollama' sh -c", $create);
        $this->assertStringNotContainsString('docker exec -i', $create);
        $this->assertStringContainsString('-f /tmp/talksasa-hermes.Modelfile', $create);
        $this->assertStringContainsString(base64_encode("FROM mistral:7b\nPARAMETER num_ctx 65536\n"), $create);

        $preload = $models->buildPreloadContextCommand($deployment, 'mistral-hermes');
        $this->assertStringContainsString('http://127.0.0.1:32101/api/generate', $preload);
        $this->assertStringContainsString(base64_encode('{"model":"mistral-hermes","keep_alive":"24h","options":{"num_ctx":65536}}'), $preload);
        $this->assertStringContainsString("docker exec 'user-4-service-338-ollama' ollama stop 'mistral:7b'", $models->buildStopModelCommand($deployment, 'mistral:7b'));
    }

    #[Test]
    public function it_reads_native_context_from_ollama_show(): void
    {
        $show = json_encode([
            'modelfile' => "FROM mistral:7b\nPARAMETER num_ctx 65536\n",
            'model_info' => [
                'general.architecture' => 'llama',
                'llama.context_length' => 32768,
            ],
        ]);

        $this->assertSame(32768, $this->service()->nativeContextFromShow($show));
        $this->assertSame(0, $this->service()->nativeContextFromShow('not-json'));
    }

    #[Test]
    public function it_reads_runtime_context_from_ollama_ps(): void
    {
        $ps = json_encode([
            'models' => [
                ['name' => 'mistral:7b', 'context_length' => 32768],
                ['name' => 'llama3-1-hermes', 'context_length' => 65536],
            ],
        ]);

        $this->assertSame(32768, $this->service()->runtimeContextFromPs($ps, 'mistral:7b'));
        $this->assertSame(65536, $this->service()->runtimeContextFromPs($ps, 'llama3-1-hermes'));
        $this->assertSame(0, $this->service()->runtimeContextFromPs($ps, 'missing:tag'));
    }

    #[Test]
    public function it_refuses_mistral_7b_when_no_64k_model_is_installed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mistral:7b only has 32,768 tokens of context');
        $this->expectExceptionMessage('ollama pull llama3.1:8b');

        $this->service()->selectHermesBaseModel('mistral:7b', [
            'mistral:7b' => 32768,
        ]);
    }

    #[Test]
    public function it_selects_llama31_when_preferred_model_is_capped_at_32k(): void
    {
        $this->assertSame(
            'llama3.1:8b',
            $this->service()->selectHermesBaseModel('mistral:7b', [
                'mistral:7b' => 32768,
                'llama3.1:8b' => 131072,
            ])
        );
    }

    #[Test]
    public function it_keeps_a_preferred_model_that_already_has_64k_context(): void
    {
        $this->assertSame(
            'qwen2.5:7b',
            $this->service()->selectHermesBaseModel('qwen2.5:7b', [
                'qwen2.5:7b' => 131072,
            ])
        );
    }

    #[Test]
    public function it_skips_hermes_aliases_when_selecting_a_64k_base_model(): void
    {
        $this->assertSame(
            'llama3.1:8b',
            $this->service()->selectHermesBaseModel('mistral:7b', [
                'mistral:7b' => 32768,
                'mistral-hermes' => 32768,
                'llama3.1:8b' => 131072,
            ])
        );
    }

    private function service(): ContainerOllamaModelService
    {
        return new ContainerOllamaModelService($this->createMock(ContainerStackCommandService::class));
    }
}
