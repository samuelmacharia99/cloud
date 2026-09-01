<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Point a Hermes Agent container at an Ollama container in the same project.
 *
 * Same-node stacks share talksasa-net, so Hermes reaches Ollama by Docker
 * container name on port 11434. Different nodes fall back to Ollama's public URL.
 */
class ContainerHermesOllamaLinkService
{
    public const OLLAMA_INTERNAL_PORT = 11434;

    public const HERMES_API_TIMEOUT_SECONDS = 1800;

    public const ENV_OLLAMA_SERVICE_ID = 'TALKSASA_OLLAMA_SERVICE_ID';

    public const ENV_OLLAMA_MODEL = 'TALKSASA_OLLAMA_MODEL';

    /**
     * Tool schemas Hermes sends on every turn. On CPU these dominate prefill.
     *
     * @var list<string>
     */
    public const LOCAL_DISABLED_TOOLSETS = ['browser', 'vision', 'web', 'memory'];

    public function __construct(
        private ContainerEnvironmentService $environment,
        private ContainerOllamaModelService $ollamaModels,
        private ContainerDeploymentService $deployments,
    ) {}

    public function supportsHermes(Service $service): bool
    {
        return ($service->effectiveContainerTemplate()?->slug ?? '') === 'hermes';
    }

    /**
     * @return array{
     *     container_running: bool,
     *     project_id: int|null,
     *     candidates: list<array{id: int, name: string, running: bool}>,
     *     connected: array{service_id: int, service_name: ?string, base_url: string, model: string, via: string}|null,
     *     empty_reason: ?string
     * }
     */
    public function panelState(Service $service, ?ContainerDeployment $deployment = null): array
    {
        $deployment ??= $service->containerDeployment;
        $env = is_array($deployment?->env_values) ? $deployment->env_values : [];
        $candidates = $this->projectOllamaServices($service);

        $linkedId = (int) ($env[self::ENV_OLLAMA_SERVICE_ID] ?? 0);
        $linked = $linkedId > 0
            ? $candidates->first(fn (Service $ollama) => $ollama->id === $linkedId)
            : null;
        $baseUrl = $this->normalizeBaseUrl((string) ($env['OLLAMA_BASE_URL'] ?? ''));
        $model = trim((string) ($env[self::ENV_OLLAMA_MODEL] ?? $env['OLLAMA_MODEL'] ?? ''));

        $connected = null;
        if ($linkedId > 0 || $baseUrl !== '') {
            $connected = [
                'service_id' => $linked?->id ?? $linkedId,
                'service_name' => $linked?->name,
                'base_url' => $baseUrl,
                'model' => $model,
                'via' => $this->describeEndpoint($baseUrl),
            ];
        }

        $emptyReason = null;
        if ($candidates->isEmpty()) {
            $emptyReason = $service->project_id
                ? 'Deploy Ollama into this project, start it, then connect it here.'
                : 'Add Hermes and Ollama to the same project first, then connect them here.';
        }

        return [
            'container_running' => (bool) $deployment?->isRunning(),
            'project_id' => $service->project_id ? (int) $service->project_id : null,
            'candidates' => $candidates->map(fn (Service $ollama) => [
                'id' => $ollama->id,
                'name' => $ollama->name,
                'running' => (bool) $ollama->containerDeployment?->isRunning(),
            ])->values()->all(),
            'connected' => $connected,
            'empty_reason' => $emptyReason,
        ];
    }

    /**
     * @return Collection<int, Service>
     */
    public function projectOllamaServices(Service $hermes): Collection
    {
        if (! $hermes->project_id) {
            return collect();
        }

        return Service::query()
            ->where('user_id', $hermes->user_id)
            ->where('project_id', $hermes->project_id)
            ->whereKeyNot($hermes->id)
            ->whereHas('product', fn ($query) => $query->where('type', 'container_hosting'))
            ->with(['product.containerTemplate', 'containerDeployment'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Service $candidate) => $this->isOllamaService($candidate))
            ->values();
    }

    /**
     * @return array{message: string, base_url: string, model: string, openai_base_url: string, via: string, warning: ?string}
     */
    public function connect(Service $hermes, Service $ollama, ?string $model = null): array
    {
        $this->assertLinkable($hermes, $ollama);

        $hermes->loadMissing('containerDeployment.node');
        $ollama->loadMissing('containerDeployment.node', 'product.containerTemplate');

        $baseUrl = $this->resolveOllamaBaseUrl($hermes, $ollama);
        $openaiUrl = $this->openaiCompatibleUrl($baseUrl);
        $resolvedModel = $this->resolveModel($ollama, $model);
        $via = $this->describeEndpoint($baseUrl);

        $this->ensureOllamaAgentContext($ollama);
        $resolvedModel = $this->prepareHermesRuntimeModel($ollama, $resolvedModel);

        $this->environment->updateVariables(
            $hermes,
            $this->buildLinkEnvironment($hermes, $ollama, $baseUrl, $openaiUrl, $resolvedModel),
            restart: true
        );

        $warning = $this->applyGatewayModelConfig($hermes->fresh(['containerDeployment.node']), $openaiUrl, $resolvedModel);

        $ollamaName = $ollama->name;
        $sessionNote = ' Open a new Chat session — existing sessions stay on the old model.';

        return [
            'message' => $warning === null
                ? "Hermes now uses {$ollamaName} ({$resolvedModel}) over the {$via}.".$sessionNote
                : "Hermes env now points at {$ollamaName}. {$warning}",
            'base_url' => $baseUrl,
            'openai_base_url' => $openaiUrl,
            'model' => $resolvedModel,
            'via' => $via,
            'warning' => $warning,
        ];
    }

    public function assertLinkable(Service $hermes, Service $ollama): void
    {
        if (! $this->supportsHermes($hermes)) {
            throw new DomainException('Connect Ollama from a Hermes Agent service.');
        }

        if (! $this->isOllamaService($ollama)) {
            throw new DomainException('Choose an Ollama service in this project.');
        }

        if ((int) $hermes->user_id !== (int) $ollama->user_id) {
            throw new DomainException('Ollama must belong to the same account as Hermes.');
        }

        if (! $hermes->project_id || (int) $hermes->project_id !== (int) $ollama->project_id) {
            throw new DomainException('Hermes and Ollama must be in the same project.');
        }

        $hermesDeployment = $hermes->containerDeployment;
        if (! $hermesDeployment || ! $hermesDeployment->isRunning() || ! $hermesDeployment->node) {
            throw new DomainException('Start Hermes before connecting Ollama.');
        }

        $ollamaDeployment = $ollama->containerDeployment;
        if (! $ollamaDeployment || ! $ollamaDeployment->isRunning()) {
            throw new DomainException('Start Ollama before connecting it to Hermes.');
        }
    }

    public function resolveOllamaBaseUrl(Service $hermes, Service $ollama): string
    {
        $ollama->loadMissing('containerDeployment.node');
        $hermes->loadMissing('containerDeployment');

        $ollamaDeployment = $ollama->containerDeployment;
        if (! $ollamaDeployment) {
            throw new DomainException('Ollama is not deployed yet.');
        }

        $hermesNodeId = $hermes->containerDeployment?->node_id;
        $ollamaNodeId = $ollamaDeployment->node_id;
        $containerName = trim((string) $ollamaDeployment->container_name);

        if ($hermesNodeId && $ollamaNodeId && (int) $hermesNodeId === (int) $ollamaNodeId) {
            if (! $this->isValidContainerName($containerName)) {
                throw new DomainException('Ollama has no valid container name to reach on the private network.');
            }

            return 'http://'.$containerName.':'.self::OLLAMA_INTERNAL_PORT;
        }

        $public = $ollamaDeployment->getAccessUrl();
        $normalized = $this->normalizeBaseUrl((string) $public);
        if ($normalized === '') {
            throw new DomainException('Ollama has no reachable URL yet. Bind a domain or wait until the host port is assigned.');
        }

        return $normalized;
    }

    public function openaiCompatibleUrl(string $baseUrl): string
    {
        $normalized = $this->normalizeBaseUrl($baseUrl);
        if ($normalized === '') {
            throw new InvalidArgumentException('Ollama URL is missing.');
        }

        if (str_ends_with($normalized, '/v1')) {
            return $normalized;
        }

        return $normalized.'/v1';
    }

    /**
     * @return array<string, string>
     */
    public function buildLinkEnvironment(
        Service $hermes,
        Service $ollama,
        string $baseUrl,
        string $openaiUrl,
        string $model,
    ): array {
        $env = is_array($hermes->containerDeployment?->env_values)
            ? $hermes->containerDeployment->env_values
            : [];

        $patch = [
            'OLLAMA_BASE_URL' => $baseUrl,
            'OPENAI_BASE_URL' => $openaiUrl,
            'HERMES_API_TIMEOUT' => (string) self::HERMES_API_TIMEOUT_SECONDS,
            'HERMES_STREAM_READ_TIMEOUT' => (string) self::HERMES_API_TIMEOUT_SECONDS,
            'FORWARDED_ALLOW_IPS' => '127.0.0.1,::1,172.16.0.0/12,10.0.0.0/8',
            'HERMES_WS_PING_INTERVAL' => '30',
            'HERMES_WS_PING_TIMEOUT' => '120',
            'HERMES_WS_WRITE_TIMEOUT' => '180',
            self::ENV_OLLAMA_SERVICE_ID => (string) $ollama->id,
            self::ENV_OLLAMA_MODEL => $model,
        ];

        $publicUrl = $hermes->containerDeployment?->getAccessUrl();
        if (is_string($publicUrl) && $publicUrl !== '') {
            $patch['HERMES_DASHBOARD_PUBLIC_URL'] = rtrim($publicUrl, '/');
        }

        $existingKey = trim((string) ($env['OPENAI_API_KEY'] ?? ''));
        if ($existingKey === '' || $this->isDummyOpenAiKey($existingKey)) {
            $patch['OPENAI_API_KEY'] = 'no-key';
        }

        return $patch;
    }

    /**
     * Hermes talks to Ollama through the OpenAI SDK, which appends
     * /chat/completions. The SDK base_url must already include /v1 or Ollama
     * returns HTTP 404 "404 page not found".
     * llama3.1:8b has no thinking capability; Hermes still sends reasoning_effort
     * unless we set it to none (HTTP 400 "does not support thinking").
     * Ollama's /v1 hang with stream=true + many tools is avoided by turning
     * streaming off. Heavy toolsets are disabled so CPU prefill is not 10+ minutes.
     *
     * @return list<string>
     */
    public function buildGatewayConfigCommands(string $containerName, string $openaiBaseUrl, string $model): array
    {
        $this->assertContainerName($containerName);
        $this->assertHttpUrl($openaiBaseUrl);
        $this->assertModelName($model);

        $exec = 'docker exec '.escapeshellarg($containerName).' hermes config set ';
        $disabledToolsets = json_encode(self::LOCAL_DISABLED_TOOLSETS, JSON_UNESCAPED_SLASHES);
        if (! is_string($disabledToolsets) || $disabledToolsets === '') {
            throw new InvalidArgumentException('Could not encode Hermes toolset list.');
        }

        return [
            $exec.escapeshellarg('model.provider').' '.escapeshellarg('custom'),
            $exec.escapeshellarg('model.base_url').' '.escapeshellarg($openaiBaseUrl),
            $exec.escapeshellarg('model.default').' '.escapeshellarg($model),
            $exec.escapeshellarg('model.context_length').' '.escapeshellarg((string) ContainerOllamaModelService::AGENT_CONTEXT_LENGTH),
            $exec.escapeshellarg('model.ollama_num_ctx').' '.escapeshellarg((string) ContainerOllamaModelService::AGENT_CONTEXT_LENGTH),
            $exec.escapeshellarg('agent.reasoning_effort').' '.escapeshellarg('none'),
            $exec.escapeshellarg('model.streaming').' '.escapeshellarg('false'),
            $exec.escapeshellarg('agent.disabled_toolsets').' '.escapeshellarg($disabledToolsets),
        ];
    }

    public function isValidContainerName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $name) === 1;
    }

    public function isOllamaService(Service $service): bool
    {
        return $this->ollamaModels->supportsService($service);
    }

    public function ollamaNeedsAgentContext(Service $ollama): bool
    {
        $env = is_array($ollama->containerDeployment?->env_values)
            ? $ollama->containerDeployment->env_values
            : [];
        $context = (int) ($env['OLLAMA_CONTEXT_LENGTH'] ?? 0);
        $numCtx = (int) ($env['OLLAMA_NUM_CTX'] ?? 0);
        $floor = ContainerOllamaModelService::AGENT_CONTEXT_LENGTH;
        $flash = trim((string) ($env['OLLAMA_FLASH_ATTENTION'] ?? ''));

        return $context < $floor || $numCtx < $floor || $flash !== '1';
    }

    public function ensureOllamaAgentContext(Service $ollama): void
    {
        $ollama->loadMissing('containerDeployment');
        if (! $this->ollamaNeedsAgentContext($ollama)) {
            return;
        }

        $length = (string) ContainerOllamaModelService::AGENT_CONTEXT_LENGTH;
        $this->environment->updateVariables(
            $ollama,
            [
                'OLLAMA_CONTEXT_LENGTH' => $length,
                'OLLAMA_NUM_CTX' => $length,
                'OLLAMA_FLASH_ATTENTION' => '1',
            ],
            restart: true
        );
        $ollama->unsetRelation('containerDeployment');
        $ollama->load('containerDeployment.node');
    }

    /**
     * Hermes reads live /api/ps context_length. Mistral 7B's GGUF max is 32K —
     * a Modelfile cannot raise that — so we refuse it and require a 64K+ model.
     * Models that already have 64K+ are used as-is: a derived alias is unnecessary
     * and `ollama create -f -` fails over SSH (empty stdin).
     */
    public function prepareHermesRuntimeModel(Service $ollama, string $model): string
    {
        $ollama->loadMissing('containerDeployment.node');
        $deployment = $ollama->containerDeployment;
        $node = $deployment?->node;
        if (! $deployment || ! $node) {
            throw new DomainException('Ollama is not deployed yet.');
        }

        try {
            $ssh = SSHService::forNode($node);
            $nativeContexts = $this->probeInstalledNativeContexts($ssh, $deployment, $model);
            $base = $this->ollamaModels->selectHermesBaseModel($model, $nativeContexts);

            $this->ollamaModels->stopModel($ssh, $deployment, $model);
            if ($base !== $model) {
                $this->ollamaModels->stopModel($ssh, $deployment, $base);
            }
            $this->ollamaModels->preloadWithAgentContext($ssh, $deployment, $base);

            $runtime = $this->ollamaModels->probeRuntimeContext($ssh, $deployment, $base);
            if ($runtime < ContainerOllamaModelService::HERMES_MIN_CONTEXT_LENGTH) {
                $shown = $runtime > 0 ? number_format($runtime) : 'unknown';

                throw new DomainException(
                    'Ollama loaded '.$base.' with '.$shown.' tokens of runtime context. Hermes needs 64,000. '
                    .'Wait a minute for '.$base.' to finish loading, then Connect Ollama again. Open a new Chat session after that.'
                );
            }

            return $base;
        } catch (DomainException|InvalidArgumentException $e) {
            throw $e instanceof DomainException
                ? $e
                : new DomainException($e->getMessage(), 0, $e);
        } catch (SSHCommandException $e) {
            Log::warning('Could not load a 64K Ollama model for Hermes', [
                'service_id' => $ollama->id,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw new DomainException(
                'Could not load a 64K model for Hermes. Confirm `ollama list` shows llama3.1:8b on the Ollama service, then Connect again.'
            );
        } catch (\Throwable $e) {
            Log::warning('Could not load a 64K Ollama model for Hermes', [
                'service_id' => $ollama->id,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw new DomainException(
                'Could not load a 64K model for Hermes. Confirm `ollama list` shows llama3.1:8b on the Ollama service, then Connect again.'
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function probeInstalledNativeContexts(
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $preferred,
    ): array {
        $installed = [];
        try {
            $installed = $this->ollamaModels->listModels($ssh, $deployment);
        } catch (\Throwable) {
            $installed = [];
        }

        $names = array_values(array_unique(array_filter(
            array_merge([$preferred, ContainerOllamaModelService::HERMES_BASE_MODEL], $installed),
            fn ($name) => is_string($name) && $this->ollamaModels->isValidModelName($name)
        )));

        $contexts = [];
        foreach ($names as $name) {
            $contexts[$name] = $this->ollamaModels->showNativeContext($ssh, $deployment, $name);
        }

        return $contexts;
    }

    public function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = rtrim($url, '/');
        if (str_ends_with(strtolower($url), '/v1')) {
            $url = substr($url, 0, -3);
            $url = rtrim($url, '/');
        }

        return $this->isValidHttpUrl($url) ? $url : '';
    }

    public function describeEndpoint(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (is_string($host) && $this->isValidContainerName($host) && ! str_contains($host, '.')) {
            return 'private Docker network';
        }

        return 'public URL';
    }

    private function resolveModel(Service $ollama, ?string $requested): string
    {
        $requested = trim((string) $requested);
        if ($requested !== '') {
            $this->assertModelName($requested);

            return $requested;
        }

        $deployment = $ollama->containerDeployment;
        if (! $deployment) {
            return ContainerOllamaModelService::modelTag(null);
        }

        $available = [];
        try {
            $node = $deployment->node;
            if ($node) {
                $available = $this->ollamaModels->listModels(SSHService::forNode($node), $deployment);
            }
        } catch (\Throwable) {
            $available = [];
        }

        return $this->ollamaModels->defaultModelName($ollama, $deployment, $available);
    }

    private function applyGatewayModelConfig(Service $hermes, string $openaiUrl, string $model): ?string
    {
        $deployment = $hermes->containerDeployment;
        $containerName = trim((string) ($deployment?->container_name ?? ''));
        $node = $deployment?->node;

        if (! $deployment || ! $node || ! $this->isValidContainerName($containerName)) {
            return 'Confirm the model provider is Custom with '.$openaiUrl.' in the Hermes dashboard if chat fails.';
        }

        try {
            $ssh = SSHService::forNode($node);
            $this->deployments->waitForContainerRunning($ssh, $containerName, 90);

            foreach ($this->buildGatewayConfigCommands($containerName, $openaiUrl, $model) as $command) {
                $ssh->exec($command, 30);
            }

            $ssh->exec('docker restart '.escapeshellarg($containerName), 90);
            $this->deployments->waitForContainerRunning($ssh, $containerName, 90);
        } catch (\Throwable $e) {
            Log::warning('Hermes Ollama env applied but gateway config write failed', [
                'service_id' => $hermes->id,
                'error' => $e->getMessage(),
            ]);

            return 'Confirm the model provider is Custom with '.$openaiUrl.' in the Hermes dashboard if chat fails.';
        }

        return null;
    }

    private function isDummyOpenAiKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, ['no-key', 'nokey', 'ollama', 'none', 'dummy'], true);
    }

    private function assertContainerName(string $name): void
    {
        if (! $this->isValidContainerName($name)) {
            throw new InvalidArgumentException('Invalid container name.');
        }
    }

    private function assertModelName(string $model): void
    {
        if (! $this->ollamaModels->isValidModelName($model)) {
            throw new InvalidArgumentException('Choose a valid Ollama model.');
        }
    }

    private function assertHttpUrl(string $url): void
    {
        if (! $this->isValidHttpUrl($url)) {
            throw new InvalidArgumentException('Invalid Ollama URL.');
        }
    }

    private function isValidHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        if (! $this->isValidContainerName($host) && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && (! is_int($port) || $port < 1 || $port > 65535)) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && preg_match('#^/[A-Za-z0-9._/-]*$#', $path) !== 1) {
            return false;
        }

        return true;
    }
}
