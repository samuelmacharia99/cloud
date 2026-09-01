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
     * Hermes Agent rejects models below 64K context. Mistral 7B's GGUF max is
     * 32,768 — a Modelfile cannot raise that. llama3.1:8b is 128K and fits an 8 GB plan.
     */
    public const AGENT_CONTEXT_LENGTH = 65536;

    public const HERMES_MIN_CONTEXT_LENGTH = 64000;

    public const HERMES_BASE_MODEL = 'llama3.1:8b';

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

    /**
     * Derived Ollama tag with PARAMETER num_ctx baked in (Hermes 64K floor).
     */
    public function hermesAliasName(string $baseModel): string
    {
        $base = strtolower(trim(explode(':', $this->assertModelName($baseModel))[0]));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $base) ?? 'model';
        $slug = trim($slug, '-') ?: 'model';
        $alias = $slug.'-hermes';

        return $this->assertModelName($alias);
    }

    public function buildStopModelCommand(ContainerDeployment $deployment, string $model): string
    {
        return 'docker exec '.escapeshellarg($this->assertContainerName($deployment))
            .' ollama stop '.escapeshellarg($this->assertModelName($model));
    }

    public function buildCreateHermesAliasCommand(ContainerDeployment $deployment, string $fromModel, string $alias): string
    {
        $from = $this->assertModelName($fromModel);
        $alias = $this->assertModelName($alias);
        $modelfile = 'FROM '.$from."\nPARAMETER num_ctx ".self::AGENT_CONTEXT_LENGTH."\n";
        $inside = 'printf %s '.escapeshellarg(base64_encode($modelfile))
            .' | base64 -d > /tmp/talksasa-hermes.Modelfile'
            .' && ollama create '.escapeshellarg($alias).' -f /tmp/talksasa-hermes.Modelfile';

        return 'docker exec '.escapeshellarg($this->assertContainerName($deployment))
            .' sh -c '.escapeshellarg($inside);
    }

    public function buildPreloadContextCommand(ContainerDeployment $deployment, string $model): string
    {
        $port = (int) ($deployment->assigned_port ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Ollama is not reachable: no published port.');
        }

        $json = json_encode([
            'model' => $this->assertModelName($model),
            'keep_alive' => '24h',
            'options' => ['num_ctx' => self::AGENT_CONTEXT_LENGTH],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || $json === '') {
            throw new RuntimeException('Could not encode the Ollama preload request.');
        }

        return 'printf %s '.escapeshellarg(base64_encode($json))
            .' | base64 -d | curl -fsS --max-time 180 -X POST '
            .escapeshellarg('http://127.0.0.1:'.$port.'/api/generate')
            .' -H '.escapeshellarg('Content-Type: application/json')
            .' -d @-';
    }

    public function createHermesAlias(SSHService $ssh, ContainerDeployment $deployment, string $fromModel, string $alias): void
    {
        $ssh->exec($this->buildCreateHermesAliasCommand($deployment, $fromModel, $alias), 120);
    }

    public function stopModel(SSHService $ssh, ContainerDeployment $deployment, string $model): void
    {
        try {
            $ssh->exec($this->buildStopModelCommand($deployment, $model), 30);
        } catch (SSHCommandException) {
            // Not loaded.
        }
    }

    public function preloadWithAgentContext(SSHService $ssh, ContainerDeployment $deployment, string $model): void
    {
        $ssh->exec($this->buildPreloadContextCommand($deployment, $model), 180);
    }

    public function buildShowCommand(ContainerDeployment $deployment, string $model): string
    {
        $json = json_encode(['name' => $this->assertModelName($model)], JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || $json === '') {
            throw new RuntimeException('Could not encode the Ollama show request.');
        }

        return $this->buildHostJsonPostCommand($deployment, '/api/show', $json, 15);
    }

    public function buildPsCommand(ContainerDeployment $deployment): string
    {
        $port = $this->publishedPort($deployment);

        return 'curl -fsS --max-time 15 '.escapeshellarg('http://127.0.0.1:'.$port.'/api/ps');
    }

    public function nativeContextFromShow(string $output): int
    {
        $decoded = json_decode(trim($output), true);
        if (! is_array($decoded)) {
            return 0;
        }

        $info = $decoded['model_info'] ?? [];
        if (! is_array($info)) {
            return 0;
        }

        foreach ($info as $key => $value) {
            if (is_string($key) && str_ends_with($key, '.context_length')) {
                return max(0, (int) $value);
            }
        }

        return 0;
    }

    public function runtimeContextFromPs(string $output, string $model): int
    {
        $decoded = json_decode(trim($output), true);
        $models = is_array($decoded) ? ($decoded['models'] ?? []) : [];
        if (! is_array($models)) {
            return 0;
        }

        foreach ($models as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $row['model'] ?? ''));
            if ($name === $model || str_starts_with($name, $model)) {
                return max(0, (int) ($row['context_length'] ?? 0));
            }
        }

        return 0;
    }

    /**
     * Pick a model whose GGUF native context is at least 64K. Mistral 7B cannot
     * be raised with a Modelfile — /api/show still reports 32,768.
     *
     * @param  array<string, int>  $nativeContexts  model tag => /api/show context_length
     */
    public function selectHermesBaseModel(string $preferred, array $nativeContexts): string
    {
        $preferredCtx = max(0, (int) ($nativeContexts[$preferred] ?? 0));
        if ($preferredCtx >= self::HERMES_MIN_CONTEXT_LENGTH && $this->isValidModelName($preferred)) {
            return $preferred;
        }

        $knownGood = (int) ($nativeContexts[self::HERMES_BASE_MODEL] ?? 0);
        if ($knownGood >= self::HERMES_MIN_CONTEXT_LENGTH) {
            return self::HERMES_BASE_MODEL;
        }

        foreach ($nativeContexts as $name => $context) {
            if (! is_string($name) || ! $this->isValidModelName($name)) {
                continue;
            }
            if (str_ends_with($name, '-hermes')) {
                continue;
            }
            if ((int) $context >= self::HERMES_MIN_CONTEXT_LENGTH) {
                return $name;
            }
        }

        throw new InvalidArgumentException(
            $this->missingHermesModelMessage($preferred, $preferredCtx)
        );
    }

    public function missingHermesModelMessage(string $preferred, int $nativeContext): string
    {
        $window = $nativeContext > 0 ? number_format($nativeContext) : 'under 64,000';

        return $preferred.' only has '.$window.' tokens of context. Hermes needs 64,000. '
            .'Mistral 7B cannot be raised above 32K. On the Ollama service, run `ollama pull llama3.1:8b` in Terminal, '
            .'wait until the pull finishes, then Connect Ollama again. Open a new Hermes Chat session after that — '
            .'this session stays on '.$preferred.'.';
    }

    public function showNativeContext(SSHService $ssh, ContainerDeployment $deployment, string $model): int
    {
        try {
            $output = $ssh->exec($this->buildShowCommand($deployment, $model), 20);
        } catch (SSHCommandException) {
            return 0;
        }

        return $this->nativeContextFromShow($output);
    }

    public function probeRuntimeContext(SSHService $ssh, ContainerDeployment $deployment, string $model): int
    {
        try {
            $output = $ssh->exec($this->buildPsCommand($deployment), 20);
        } catch (SSHCommandException) {
            return 0;
        }

        return $this->runtimeContextFromPs($output, $model);
    }

    private function buildHostJsonPostCommand(ContainerDeployment $deployment, string $path, string $json, int $timeout): string
    {
        $port = $this->publishedPort($deployment);

        return 'printf %s '.escapeshellarg(base64_encode($json))
            .' | base64 -d | curl -fsS --max-time '.$timeout
            .' -X POST '.escapeshellarg('http://127.0.0.1:'.$port.$path)
            .' -H '.escapeshellarg('Content-Type: application/json')
            .' -d @-';
    }

    private function publishedPort(ContainerDeployment $deployment): int
    {
        $port = (int) ($deployment->assigned_port ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Ollama is not reachable: no published port.');
        }

        return $port;
    }

    private function assertModelName(string $name): string
    {
        $model = trim($name);
        if (! $this->isValidModelName($model)) {
            throw new InvalidArgumentException('Choose a valid Ollama model.');
        }

        return $model;
    }

    private function assertContainerName(ContainerDeployment $deployment): string
    {
        $container = trim((string) $deployment->container_name);
        if ($container === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $container) !== 1) {
            throw new InvalidArgumentException('Invalid container name.');
        }

        return $container;
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
