<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * The audit timeline for one service's infrastructure.
 *
 * This was a private method on ContainerDeploymentService, which meant the only
 * code that could leave a trace was code already inside the largest class in the
 * repository. Extracting cohesive units rather than appending to that class is
 * the standing rule here, and a unit nobody can call is not much of a unit.
 *
 * Recording is fail-soft on purpose. An event is a record of work, never the
 * work itself, and a full disk or a locked table must not take down a deploy
 * that is otherwise succeeding.
 */
class ContainerDeploymentEventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Service $service,
        ?ContainerDeployment $deployment,
        string $event,
        array $payload = [],
    ): void {
        try {
            ContainerDeploymentEvent::create([
                'service_id' => $service->id,
                'container_deployment_id' => $deployment?->id,
                'event' => $event,
                'payload' => $payload ?: null,
                'recorded_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to record container deployment event '{$event}'", [
                'service_id' => $service->id,
                'deployment_id' => $deployment?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
