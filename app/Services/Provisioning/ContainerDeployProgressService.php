<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\ContainerDeploymentEvent;
use App\Models\Service;
use Illuminate\Support\Collection;

class ContainerDeployProgressService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Service $service): array
    {
        $status = $service->status instanceof ServiceStatus
            ? $service->status->value
            : (string) $service->status;

        $events = ContainerDeploymentEvent::query()
            ->where('service_id', $service->id)
            ->orderBy('id')
            ->get();

        $lines = $events->map(fn (ContainerDeploymentEvent $event) => $this->formatLine($event))->all();
        $steps = $this->stepsFor($service, $events);
        $completed = count(array_filter($steps, fn (array $step) => $step['status'] === 'completed'));
        $awaitingConfiguration = $status === ServiceStatus::AwaitingConfiguration->value;
        $failed = $status === ServiceStatus::Failed->value || $events->contains(fn ($event) => $event->event === 'deploy_failed');
        $active = in_array($status, [ServiceStatus::Pending->value, ServiceStatus::Provisioning->value], true)
            && ! $failed
            && ! $awaitingConfiguration;
        $percent = $failed
            ? max(8, (int) floor(($completed / max(count($steps), 1)) * 100))
            : ($status === ServiceStatus::Active->value
                ? 100
                : min(92, max(6, (int) floor((($completed + ($active ? 0.45 : 0)) / max(count($steps), 1)) * 100))));

        $latest = $events->last();
        $headline = $this->headline($status, $latest, $service);

        return [
            'service_id' => $service->id,
            'name' => $service->name,
            'stack' => $service->effectiveContainerTemplate()?->name ?? $service->service_meta['application_stack'] ?? 'Application',
            'status' => $status,
            'is_active' => $active,
            'is_ready' => $status === ServiceStatus::Active->value,
            'is_failed' => $failed,
            'percent' => $percent,
            'headline' => $headline,
            'steps' => $steps,
            'log' => implode("\n", $lines) ?: $this->waitingLog($service),
            'lines' => $lines,
            'is_awaiting_configuration' => $awaitingConfiguration,
            'missing_variables' => $awaitingConfiguration
                ? app(ApplicationEnvironmentRequirements::class)->outstandingRequired($service, $service->containerDeployment)
                : [],
            'can_retry' => $failed || $awaitingConfiguration,
            // Send the customer straight to the tab where they can act.
            'redirect' => match (true) {
                $status === ServiceStatus::Active->value => route('customer.services.container.show', $service),
                $awaitingConfiguration => route('customer.services.container.show', [
                    'service' => $service,
                    'tab' => 'environment',
                ]),
                default => null,
            },
        ];
    }

    /**
     * @param  Collection<int, ContainerDeploymentEvent>  $events
     * @return list<array{key: string, label: string, status: string}>
     */
    private function stepsFor(Service $service, Collection $events): array
    {
        $seen = $events->pluck('event')->all();
        $failed = in_array('deploy_failed', $seen, true);
        $slug = strtolower((string) ($service->effectiveContainerTemplate()?->slug ?? ''));

        $definitions = [
            ['key' => 'deploy_started', 'label' => 'Prepare the application'],
            ['key' => 'node_selected', 'label' => 'Place it on a host'],
            ['key' => 'compose_up_started', 'label' => 'Pull the image and start the runtime'],
            ['key' => 'health_check_started', 'label' => 'Wait until the process is healthy'],
        ];

        if ($slug === 'ollama') {
            $definitions[] = ['key' => 'ollama_model_pulled', 'label' => 'Download the selected model'];
        }

        $definitions[] = ['key' => 'deploy_succeeded', 'label' => 'Finish and go live'];

        $reachedFailure = false;
        $out = [];
        foreach ($definitions as $index => $definition) {
            $done = in_array($definition['key'], $seen, true)
                || ($definition['key'] === 'compose_up_started' && in_array('health_check_started', $seen, true))
                || ($definition['key'] === 'health_check_started' && in_array('health_check_passed', $seen, true))
                || ($definition['key'] === 'health_check_started' && in_array('deploy_succeeded', $seen, true));

            if ($definition['key'] === 'deploy_succeeded' && in_array('deploy_succeeded', $seen, true)) {
                $status = 'completed';
            } elseif ($done) {
                $status = 'completed';
            } elseif ($failed && ! $reachedFailure) {
                $status = 'failed';
                $reachedFailure = true;
            } elseif ($failed) {
                $status = 'pending';
            } elseif ($index === 0 || ($out[$index - 1]['status'] ?? null) === 'completed') {
                $status = 'running';
            } else {
                $status = 'pending';
            }

            $out[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'status' => $status,
            ];
        }

        return $out;
    }

    private function formatLine(ContainerDeploymentEvent $event): string
    {
        $at = $event->recorded_at?->format('H:i:s') ?? now()->format('H:i:s');
        $payload = is_array($event->payload) ? $event->payload : [];

        $text = match ($event->event) {
            'deploy_started' => 'Starting deploy'.(isset($payload['template_slug']) ? ' ('.$payload['template_slug'].')' : ''),
            'node_selected' => 'Host selected'.(isset($payload['node_hostname']) ? ': '.$payload['node_hostname'] : ''),
            'port_reserved' => 'Public port reserved',
            'port_reassigned' => 'Host port was in use; reserved '
                .(isset($payload['assigned_port']) ? (string) $payload['assigned_port'] : 'another port'),
            'compose_up_started' => 'Pulling image and starting containers'
                .(isset($payload['timeout_seconds']) ? ' (up to '.$payload['timeout_seconds'].'s)' : '')
                .'. Large images can take several minutes.',
            'health_check_started' => 'Waiting for the process to stay running'
                .(isset($payload['timeout_seconds']) ? ' (up to '.$payload['timeout_seconds'].'s)' : ''),
            'health_check_passed' => 'Runtime is healthy',
            'health_check_timed_out_relaxed' => 'Health check timed out; continuing in relaxed mode',
            'ollama_model_pulled' => ($payload['skipped'] ?? false)
                ? (string) ($payload['message'] ?? 'Model pull skipped')
                : 'Pulled model '.($payload['model'] ?? ''),
            'ollama_model_pull_failed' => 'Model pull failed: '.($payload['error'] ?? 'unknown error'),
            'deploy_succeeded' => 'Deploy finished',
            'deploy_failed' => 'Deploy failed: '.($payload['error'] ?? $payload['message'] ?? 'unknown error'),
            // Cased rather than left to the default arm below, which would show
            // the customer the raw event name with its underscores stripped.
            'application_readiness_started' => 'Checking that the application starts and stays up'
                .(isset($payload['timeout_seconds']) ? ' (up to '.$payload['timeout_seconds'].'s)' : ''),
            'application_readiness_passed' => 'Application is running and staying up',
            'application_readiness_failed' => 'Application did not stay up: '
                .($payload['cause'] === 'crash_loop' ? 'it keeps restarting' : (string) ($payload['cause'] ?? 'unknown')),
            'application_readiness_awaiting_configuration' => 'Application needs settings before it can start',
            'application_environment_keys_refreshed' => 'Read the settings this repository asks for',
            default => str_replace('_', ' ', $event->event),
        };

        return '['.$at.'] '.$text;
    }

    private function headline(string $status, ?ContainerDeploymentEvent $latest, Service $service): string
    {
        if ($status === ServiceStatus::Active->value) {
            return 'Your application is ready.';
        }

        if ($status === ServiceStatus::AwaitingConfiguration->value) {
            $missing = app(ApplicationEnvironmentRequirements::class)
                ->outstandingRequired($service, $service->containerDeployment);

            return $missing === []
                ? 'Your app needs a few settings before it starts. Add them under Environment.'
                : 'Your app needs these settings before it starts: '.implode(', ', $missing)
                    .'. Add them under Environment.';
        }

        if ($status === ServiceStatus::Failed->value || $latest?->event === 'deploy_failed') {
            $error = is_array($latest?->payload) ? ($latest->payload['error'] ?? $latest->payload['message'] ?? null) : null;

            return $error ? 'Deploy failed: '.$error : 'Deploy failed. You can retry from this page.';
        }

        $slug = strtolower((string) ($service->effectiveContainerTemplate()?->slug ?? ''));
        if ($latest?->event === 'compose_up_started' && $slug === 'ollama') {
            return 'Pulling the Ollama image. This often takes several minutes the first time.';
        }

        if ($latest?->event === 'health_check_started') {
            return 'Waiting for the runtime to come up. This page will not time out.';
        }

        if ($latest?->event === 'ollama_model_pulled' || $latest?->event === 'health_check_passed') {
            return 'Downloading the selected model. You can leave this page open.';
        }

        return 'Deploy is running. Keep this page open — first-time image pulls are slow.';
    }

    private function waitingLog(Service $service): string
    {
        return '['.now()->format('H:i:s').'] Queued '.$service->name.'. Waiting for the worker to start…';
    }
}
