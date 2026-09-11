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
     * @param  list<string>  $missingVariables  names with no value
     * @param  list<string>  $invalidVariables  names whose value the application rejected
     */
    public function hold(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        array $missingVariables,
        array $invalidVariables = [],
    ): void {
        $this->requirements->rememberRequired($service, $missingVariables);
        $this->requirements->rememberInvalid($service, $invalidVariables);

        $service = $service->fresh() ?? $service;
        $outstanding = $this->requirements->outstandingRequired($service, $deployment);

        // Recomputed for what is unset, then joined with what is set and
        // unusable. Everything that reports missing settings filters the second
        // group out, because they are set: that is the whole problem with them,
        // and a site that says nothing at all is the result.
        $toFix = array_values(array_unique([...$outstanding, ...$invalidVariables]));

        $this->stopApplicationContainer($ssh, $deployment);
        $this->showSetupNotice($ssh, $deployment, $toFix);

        $deployment->update([
            'status' => 'stopped',
            'last_status_check_at' => now(),
            'last_status_check_output' => ApplicationConfigurationRequiredException::describe(
                $outstanding,
                $invalidVariables,
            ),
        ]);

        $service->update(['status' => ServiceStatus::AwaitingConfiguration]);

        $this->notifyCustomer($service, $outstanding, $invalidVariables);
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
     * @param  list<string>  $invalidVariables
     */
    private function notifyCustomer(Service $service, array $missingVariables, array $invalidVariables = []): void
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
                ApplicationConfigurationRequiredException::describe($missingVariables, $invalidVariables),
                route('customer.services.show', $service->id),
                [
                    'service_id' => $service->id,
                    'missing_variables' => $missingVariables,
                    'invalid_variables' => $invalidVariables,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Could not notify the customer about a configuration hold', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
