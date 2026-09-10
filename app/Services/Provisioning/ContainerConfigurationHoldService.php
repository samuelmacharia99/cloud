<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Exceptions\ApplicationConfigurationRequiredException;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\InAppNotificationService;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Park a provisioned stack whose application cannot start until the customer
 * supplies values only they hold.
 *
 * The database, the browser frontend and the edge router all came up, so this
 * is not a failed deploy. The API container is stopped rather than left to
 * crash-loop against a missing credential, and the edge holds the site on a
 * setup notice until the values arrive.
 */
class ContainerConfigurationHoldService
{
    public function __construct(
        private ApplicationEnvironmentRequirements $requirements,
    ) {}

    /**
     * @param  list<string>  $missingVariables
     */
    public function hold(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        array $missingVariables,
    ): void {
        $this->requirements->rememberRequired($service, $missingVariables);
        $outstanding = $this->requirements->outstandingRequired($service->fresh() ?? $service, $deployment);

        $this->stopApplicationContainer($ssh, $deployment);
        $this->showSetupNotice($ssh, $deployment, $outstanding);

        $deployment->update([
            'status' => 'stopped',
            'last_status_check_at' => now(),
            'last_status_check_output' => ApplicationConfigurationRequiredException::describe($outstanding),
        ]);

        $service->update(['status' => ServiceStatus::AwaitingConfiguration]);

        $this->notifyCustomer($service, $outstanding);
    }

    /**
     * The application started, so drop the reminder and let the service read as
     * active again. Safe to call on every successful start.
     */
    public function release(Service $service, ?ContainerDeployment $deployment = null): void
    {
        $service = $service->fresh();
        if (! $service instanceof Service) {
            return;
        }

        $this->requirements->forgetRequired($service);

        if ($service->status === ServiceStatus::AwaitingConfiguration) {
            $service->update(['status' => ServiceStatus::Active]);
        }

        $deployment?->update(['status' => 'running']);
    }

    /**
     * A stopped container is not restarted by Docker's restart policy, which is
     * what ends the crash loop.
     */
    private function stopApplicationContainer(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        try {
            $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose -f docker-compose.yml stop '
                .escapeshellarg(NodeWebGatewayProxy::BACKEND_SERVICE),
                60,
            );
        } catch (\Throwable $e) {
            Log::warning('Could not stop the API container while holding for configuration', [
                'service_id' => $deployment->service_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<string>  $missingVariables
     */
    private function showSetupNotice(SSHService $ssh, ContainerDeployment $deployment, array $missingVariables): void
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $deploymentService = app(ContainerDeploymentService::class);

        try {
            $yaml = $ssh->exec('cat '.escapeshellarg($containerPath.'/docker-compose.yml'), 20);
            $patched = $deploymentService->patchComposeServiceEnvironment(
                $yaml,
                NodeWebGatewayProxy::EDGE_SERVICE,
                [NodeWebGatewayProxy::SETUP_REQUIRED_ENV => implode(',', $missingVariables) ?: '1'],
            );

            $ssh->upload($patched, $containerPath.'/docker-compose.yml');
            $deployment->update(['docker_compose_content' => $patched]);

            $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose -f docker-compose.yml up -d --force-recreate '
                .escapeshellarg(NodeWebGatewayProxy::EDGE_SERVICE),
                120,
            );
        } catch (\Throwable $e) {
            Log::warning('Could not switch the edge to the setup notice', [
                'service_id' => $deployment->service_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<string>  $missingVariables
     */
    private function notifyCustomer(Service $service, array $missingVariables): void
    {
        $user = $service->loadMissing('user')->user;
        if ($user === null) {
            return;
        }

        try {
            app(InAppNotificationService::class)->push(
                $user,
                'service_awaiting_configuration',
                'Your app needs a few settings before it starts',
                ApplicationConfigurationRequiredException::describe($missingVariables),
                route('customer.services.show', $service->id),
                ['service_id' => $service->id, 'missing_variables' => $missingVariables],
            );
        } catch (\Throwable $e) {
            Log::warning('Could not notify the customer about a configuration hold', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
