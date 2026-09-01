<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use InvalidArgumentException;
use RuntimeException;

class ContainerOllamaModelService
{
    /**
     * Size keys shown to customers → official Ollama library tags.
     * Never treat these as Docker image tags.
     *
     * @var array<string, string>
     */
    public const MODEL_TAGS = [
        '7b' => 'mistral:7b',
        '8b' => 'ministral-3:8b',
    ];

    public const MAX_MESSAGE_CHARS = 8000;

    public const MAX_HISTORY = 20;

    public const CHAT_TIMEOUT_SECONDS = 180;

    /**
     * Hermes Agent rejects models below 64K context. Ollama must raise num_ctx
     * server-side; /api/show still reports the GGUF max, so Hermes also needs
     * model.context_length set to this value.
     */
    public const AGENT_CONTEXT_LENGTH = 65536;

    public function __construct(
        private ContainerStackCommandService $stackCommands,
    ) {}

    public static function normalizeSize(?string $selectedVersion): string
    {
        $size = strtolower(trim((string) $selectedVersion));

        return array_key_exists($size, self::MODEL_TAGS) ? $size : '7b';
    }

    public static function modelTag(?string $selectedVersion): string
    {
        return self::MODEL_TAGS[self::normalizeSize($selectedVersion)];
    }

    public function supportsTemplate(?string $slug): bool
    {
        return $slug === 'ollama';
    }

    public function supportsService(Service $service): bool
    {
        return $this->supportsTemplate($service->effectiveContainerTemplate()?->slug);
    }

    /**
     * @return array{skipped: bool, model: string, message: string}
     */
    public function pullIfNeeded(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        string $containerPath,
        string $composeService,
    ): array {
        if (! $this->supportsService($service)) {
            return [
                'skipped' => true,
                'model' => '',
                'message' => 'Not an Ollama stack.',
            ];
        }

        $selectedVersion = $deployment->selected_version
            ?? (is_array($service->service_meta) ? ($service->service_meta['selected_version'] ?? null) : null);
        $model = self::modelTag(is_string($selectedVersion) ? $selectedVersion : null);

        $this->stackCommands->execInContainer(
            $ssh,
            $containerPath,
            $composeService,
            'ollama pull '.$model,
            '/',
            1200,
        );

        return [
            'skipped' => false,
            'model' => $model,
            'message' => 'Pulled '.$model,
        ];
    }

    /**
     * @return list<string>
     */
    public function listModels(SSHService $ssh, ContainerDeployment $deployment): array
    {
        $output = trim($ssh->exec($this->buildListCommand($deployment), 30));

        return $this->parseListOutput($output);
    }

    public function buildListCommand(ContainerDeployment $deployment): string
    {
        $container = trim((string) $deployment->container_name);
        if ($container === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $container)) {
            throw new InvalidArgumentException('Invalid container name.');
        }

        return 'docker exec '.escapeshellarg($container).' ollama list';
    }

    /**
     * @return list<string>
     */
    public function parseListOutput(string $output): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && isset($decoded['models']) && is_array($decoded['models'])) {
            $names = [];
            foreach ($decoded['models'] as $model) {
                $name = is_array($model) ? trim((string) ($model['name'] ?? '')) : '';
                if ($this->isValidModelName($name)) {
                    $names[] = $name;
                }
            }

            return array_values(array_unique($names));
        }

        $names = [];
        foreach (preg_split("/\r\n|\n|\r/", $trimmed) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^NAME\b/i', $line) === 1) {
                continue;
            }

            $first = preg_split('/\s+/', $line)[0] ?? '';
            if ($this->isValidModelName($first)) {
                $names[] = $first;
            }
        }

        return array_values(array_unique($names));
    }

    public function defaultModelName(Service $service, ContainerDeployment $deployment, array $available = []): string
    {
        $envModel = trim((string) (($deployment->env_values['OLLAMA_MODEL'] ?? '') ?: ''));
        $selectedVersion = $deployment->selected_version
            ?? (is_array($service->service_meta) ? ($service->service_meta['selected_version'] ?? null) : null);
        $planned = self::modelTag(is_string($selectedVersion) ? $selectedVersion : null);

        foreach ([$envModel, $planned] as $candidate) {
            if ($candidate === '' || $available === []) {
                if ($this->isValidModelName($candidate)) {
                    return $candidate;
                }

                continue;
            }

            if (in_array($candidate, $available, true)) {
                return $candidate;
            }

            $base = explode(':', $candidate)[0];
            foreach ($available as $name) {
                if ($name === $candidate || $name === $base || str_starts_with($name, $base.':')) {
                    return $name;
                }
            }
        }

        return $available[0] ?? ($this->isValidModelName($planned) ? $planned : 'mistral:7b');
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{model: string, content: string}
     */
    public function chat(
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $model,
        array $messages,
    ): array {
        $model = $this->assertModelName($model);
        $payload = $this->chatPayload($model, $messages);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || $json === '') {
            throw new RuntimeException('Could not encode the chat request.');
        }

        $command = $this->buildChatCommand($deployment, $json);

        try {
            $output = trim($ssh->exec($command, self::CHAT_TIMEOUT_SECONDS));
        } catch (SSHCommandException $e) {
            throw new RuntimeException($this->chatFailureMessage($e), 0, $e);
        }

        return $this->parseChatResponse($output, $model);
    }

    /**
     * @param  list<array{role?: mixed, content?: mixed}>  $messages
     * @return array{model: string, messages: list<array{role: string, content: string}>, stream: false}
     */
    public function chatPayload(string $model, array $messages): array
    {
        $normalized = [];
        foreach ($messages as $message) {
            $role = strtolower(trim((string) ($message['role'] ?? '')));
            $content = trim((string) ($message['content'] ?? ''));
            if (! in_array($role, ['system', 'user', 'assistant'], true)) {
                continue;
            }
            if ($content === '') {
                continue;
            }
            if (strlen($content) > self::MAX_MESSAGE_CHARS) {
                throw new InvalidArgumentException('Each chat message must be '.self::MAX_MESSAGE_CHARS.' characters or fewer.');
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Write a message to send to the model.');
        }

        if (count($normalized) > self::MAX_HISTORY + 1) {
            $normalized = array_slice($normalized, -(self::MAX_HISTORY + 1));
        }

        $last = $normalized[array_key_last($normalized)];
        if ($last['role'] !== 'user') {
            throw new InvalidArgumentException('The last message must come from you.');
        }

        return [
            'model' => $this->assertModelName($model),
            'messages' => $normalized,
            'stream' => false,
        ];
    }

    public function buildChatCommand(ContainerDeployment $deployment, string $jsonPayload): string
    {
        $port = (int) ($deployment->assigned_port ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Ollama is not reachable: no published port.');
        }

        $encoded = base64_encode($jsonPayload);

        return 'printf %s '.escapeshellarg($encoded)
            .' | base64 -d | curl -fsS --max-time '.self::CHAT_TIMEOUT_SECONDS
            .' -X POST '.escapeshellarg('http://127.0.0.1:'.$port.'/api/chat')
            .' -H '.escapeshellarg('Content-Type: application/json')
            .' -d @-';
    }

    /**
     * @return array{model: string, content: string}
     */
    public function parseChatResponse(string $output, string $fallbackModel): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            throw new RuntimeException('The model returned an empty reply. Try again in a moment.');
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            $lastJson = $this->lastJsonObject($trimmed);
            $decoded = is_string($lastJson) ? json_decode($lastJson, true) : null;
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Ollama did not return a valid reply. Check Logs if this keeps happening.');
        }

        if (isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '') {
            throw new RuntimeException('Ollama error: '.$decoded['error']);
        }

        $content = '';
        if (isset($decoded['message']['content']) && is_string($decoded['message']['content'])) {
            $content = trim($decoded['message']['content']);
        } elseif (isset($decoded['response']) && is_string($decoded['response'])) {
            $content = trim($decoded['response']);
        }

        if ($content === '') {
            throw new RuntimeException('The model returned an empty reply. Try again in a moment.');
        }

        $model = trim((string) ($decoded['model'] ?? $fallbackModel));

        return [
            'model' => $this->isValidModelName($model) ? $model : $fallbackModel,
            'content' => $content,
        ];
    }

    public function isValidModelName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/', $name) === 1;
    }

    private function assertModelName(string $name): string
    {
        $model = trim($name);
        if (! $this->isValidModelName($model)) {
            throw new InvalidArgumentException('Choose a valid Ollama model.');
        }

        return $model;
    }

    private function lastJsonObject(string $output): ?string
    {
        $start = strrpos($output, '{');
        if ($start === false) {
            return null;
        }

        $chunk = substr($output, $start);

        return $chunk !== '' ? $chunk : null;
    }

    private function chatFailureMessage(SSHCommandException $e): string
    {
        $detail = strtolower($e->errorDetail.' '.$e->output);
        if (str_contains($detail, 'not found') || str_contains($detail, '404')) {
            return 'That model is not installed. Pull it from the Terminal with `ollama pull`, or wait for the deploy pull to finish.';
        }
        if (str_contains($detail, 'timed out') || str_contains($detail, 'timeout') || str_contains($detail, 'status 124')) {
            return 'The model is still loading or the reply timed out. Wait a minute and try a shorter message.';
        }
        if (str_contains($detail, 'connection refused') || str_contains($detail, 'failed to connect')) {
            return 'Ollama is not accepting chat yet. Confirm the app is running, then try again.';
        }

        return 'Could not reach Ollama. Confirm the app is running and try again.';
    }
}
