<?php

namespace App\Services\Provisioning;

use App\Jobs\PullContainerGitRepositoryJob;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ContainerAutoDeployService
{
    public function __construct(
        private ContainerGitRepositoryService $gitRepository,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     has_secret: bool,
     *     webhook_url: string,
     *     branch: string,
     *     run_composer: bool,
     *     run_migrations: bool,
     *     force_rebuild: bool
     * }
     */
    public function panelState(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $settings = $this->gitRepository->repositorySettings($service);

        return [
            'enabled' => (bool) ($meta['auto_deploy_enabled'] ?? false),
            'has_secret' => filled($meta['auto_deploy_secret_hash'] ?? null),
            'webhook_url' => route('webhooks.containers.git-deploy', $service),
            'branch' => (string) ($settings['branch'] ?? 'main'),
            'run_composer' => (bool) ($meta['auto_deploy_run_composer'] ?? true),
            'run_migrations' => (bool) ($meta['auto_deploy_run_migrations'] ?? true),
            'force_rebuild' => (bool) ($meta['auto_deploy_force_rebuild'] ?? false),
        ];
    }

    /**
     * @return array{secret: string, state: array}
     */
    public function enable(Service $service, bool $rotateSecret = true): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment');

        if (! $this->gitRepository->supportsTemplate($service->product?->containerTemplate?->slug)) {
            throw new \InvalidArgumentException('Git auto-deploy is not supported for this template.');
        }

        $settings = $this->gitRepository->repositorySettings($service);
        if (($settings['url'] ?? '') === '') {
            throw new \InvalidArgumentException('Connect a Git repository before enabling auto-deploy.');
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $plainSecret = null;

        if ($rotateSecret || empty($meta['auto_deploy_secret_hash'])) {
            $plainSecret = Str::random(40);
            $meta['auto_deploy_secret_hash'] = Hash::make($plainSecret);
        }

        $meta['auto_deploy_enabled'] = true;
        $service->update(['service_meta' => $meta]);

        return [
            'secret' => $plainSecret ?? '',
            'state' => $this->panelState($service->fresh()),
        ];
    }

    public function disable(Service $service): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['auto_deploy_enabled'] = false;
        $service->update(['service_meta' => $meta]);
    }

    public function updateOptions(Service $service, array $options): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        if (array_key_exists('run_composer', $options)) {
            $meta['auto_deploy_run_composer'] = (bool) $options['run_composer'];
        }
        if (array_key_exists('run_migrations', $options)) {
            $meta['auto_deploy_run_migrations'] = (bool) $options['run_migrations'];
        }
        if (array_key_exists('force_rebuild', $options)) {
            $meta['auto_deploy_force_rebuild'] = (bool) $options['force_rebuild'];
        }

        $service->update(['service_meta' => $meta]);
    }

    public function secretMatches(Service $service, ?string $provided): bool
    {
        $hash = $service->service_meta['auto_deploy_secret_hash'] ?? null;
        if (! is_string($hash) || $hash === '' || ! is_string($provided) || $provided === '') {
            return false;
        }

        return Hash::check($provided, $hash);
    }

    /**
     * @return array{queued: bool, message: string, pull_id?: int}
     */
    public function handleWebhook(Service $service, Request $request): array
    {
        $service->loadMissing('user', 'product.containerTemplate', 'containerDeployment');

        if ($service->product?->type !== 'container_hosting') {
            throw new \InvalidArgumentException('Not a container service.');
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (! ($meta['auto_deploy_enabled'] ?? false)) {
            throw new \InvalidArgumentException('Auto-deploy is disabled for this service.');
        }

        $token = $request->header('X-Talksasa-Token')
            ?? $request->header('X-Gitlab-Token')
            ?? $request->query('token');

        if (! $this->secretMatches($service, is_string($token) ? $token : null)) {
            if (! $this->verifyGithubSignature($service, $request)) {
                throw new \InvalidArgumentException('Invalid deploy webhook token.');
            }
        }

        if (! $this->gitRepository->supportsTemplate($service->product?->containerTemplate?->slug)) {
            throw new \InvalidArgumentException('Git auto-deploy is not supported for this template.');
        }

        $settings = $this->gitRepository->repositorySettings($service);
        if (($settings['url'] ?? '') === '') {
            throw new \InvalidArgumentException('Connect a Git repository before enabling auto-deploy.');
        }

        $branch = $settings['branch'] ?? 'main';
        if (! $this->eventMatchesBranch($request, $branch)) {
            return [
                'queued' => false,
                'message' => 'Ignored: push was not for the connected branch ('.$branch.').',
            ];
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || $deployment->status !== 'running') {
            throw new \InvalidArgumentException('Container must be running to auto-deploy.');
        }

        if ($this->gitRepository->hasActivePull($service)) {
            return [
                'queued' => false,
                'message' => 'A Git pull is already in progress.',
            ];
        }

        $owner = $service->user;
        if (! $owner) {
            throw new \InvalidArgumentException('Service owner is missing.');
        }

        $pull = $this->gitRepository->requestPull(
            $service,
            $owner,
            replaceExisting: false,
            runComposer: (bool) ($meta['auto_deploy_run_composer'] ?? true),
            runMigrations: (bool) ($meta['auto_deploy_run_migrations'] ?? true),
            forceRebuild: (bool) ($meta['auto_deploy_force_rebuild'] ?? false),
        );

        $options = is_array($pull->options) ? $pull->options : [];
        $options['trigger'] = 'webhook';
        $pull->update(['options' => $options]);

        PullContainerGitRepositoryJob::dispatch($pull->id);

        return [
            'queued' => true,
            'message' => 'Auto-deploy queued.',
            'pull_id' => $pull->id,
        ];
    }

    private function verifyGithubSignature(Service $service, Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        $hash = $service->service_meta['auto_deploy_secret_hash'] ?? null;
        if (! is_string($signature) || ! str_starts_with($signature, 'sha256=') || ! is_string($hash)) {
            return false;
        }

        // GitHub HMAC requires the plain secret; we only store a hash.
        // Customers should use X-Talksasa-Token / query token with our generated secret.
        return false;
    }

    private function eventMatchesBranch(Request $request, string $expectedBranch): bool
    {
        $payload = $request->all();
        $ref = $payload['ref'] ?? null;

        if (! is_string($ref) || $ref === '') {
            // Generic ping / non-push: allow deploy (manual curl)
            return true;
        }

        $expected = 'refs/heads/'.ltrim($expectedBranch, '/');

        return hash_equals($expected, $ref) || hash_equals($expectedBranch, $ref);
    }
}
