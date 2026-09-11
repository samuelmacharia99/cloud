<?php

namespace App\Services\Provisioning;

use App\Exceptions\ApplicationConfigurationRequiredException;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Prove that an application container actually started, rather than that Docker
 * reported it running once.
 *
 * A crash-looping container is running, briefly, between restarts. The check
 * these stacks had returned on the first `running` it saw, so a Git pull that
 * left the application dead reported every step green and the customer found
 * out from their own site. Laravel had an HTTP check and Node had a readiness
 * probe; Python, Ruby and Go had this.
 *
 * What makes the difference is the restart count. Two containers can both be
 * running at the instant they are inspected, and only one of them has restarted
 * three times in the last twenty seconds.
 */
class ContainerApplicationReadinessService
{
    /**
     * Stacks with no readiness check of their own. Laravel proves itself over
     * HTTP and Node has its own probe; neither is routed through here.
     *
     * @var list<string>
     */
    private const SUPPORTED_STACKS = ['python', 'ruby', 'go'];

    /** Seconds between polls while waiting for the container to settle. */
    private const POLL_DELAY = 3;

    /**
     * A container that is up must still be up this many seconds later. Anything
     * shorter and a restart loop passes on the gap between two crashes.
     */
    private const CONFIRM_DELAY = 2;

    /**
     * Restarts observed while watching before the wait is abandoned. One can be
     * the container being recreated underneath us. Two in a row is a loop, and
     * waiting out the remaining timeout only delays the same answer.
     */
    private const RESTART_ABORT_THRESHOLD = 2;

    public function __construct(
        private ContainerRuntimeInspector $inspector,
        private ContainerRuntimeCrashReader $crashReader,
        private ContainerStackCommandService $stackCommands,
        private ContainerDeploymentEventRecorder $events,
    ) {}

    public function supports(?string $stackSlug): bool
    {
        return in_array((string) $stackSlug, self::SUPPORTED_STACKS, true);
    }

    /**
     * Returns quietly when the application is up and staying up.
     *
     * @throws ApplicationConfigurationRequiredException when the crash names
     *                                                   settings only the customer can supply, so the caller parks the
     *                                                   stack instead of reporting a platform fault
     * @throws \RuntimeException for every other failure to start
     */
    public function assertReady(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        ?int $timeoutSeconds = null,
    ): void {
        $stack = (string) ($service->effectiveContainerTemplate()?->slug ?? '');
        $containerName = (string) $deployment->container_name;
        $timeoutSeconds = $this->resolveTimeout($timeoutSeconds);
        $startedAt = time();

        $this->events->record($service, $deployment, 'application_readiness_started', [
            'stack' => $stack,
            'container_name' => $containerName,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        Log::info('Application readiness check started', [
            'service_id' => $service->id,
            'deployment_id' => $deployment->id,
            'container_name' => $containerName,
            'stack' => $stack,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        $outcome = $this->watch($ssh, $deployment, $containerName, $timeoutSeconds);
        $elapsed = time() - $startedAt;

        if ($outcome['ready']) {
            $this->events->record($service, $deployment, 'application_readiness_passed', [
                'stack' => $stack,
                'elapsed_seconds' => $elapsed,
                'restart_count' => $outcome['restart_count'],
            ]);

            Log::info('Application readiness check passed', [
                'service_id' => $service->id,
                'deployment_id' => $deployment->id,
                'container_name' => $containerName,
                'stack' => $stack,
                'attempt' => $outcome['attempt'],
                'restart_count' => $outcome['restart_count'],
                'elapsed_seconds' => $elapsed,
            ]);

            return;
        }

        throw $this->explain($ssh, $service, $deployment, $stack, $outcome, $elapsed);
    }

    /**
     * Poll until the container is convincingly up, convincingly looping, or out
     * of time.
     *
     * @return array{ready: bool, reason: string, attempt: int, restart_count: int, state: string, exit_code: int|null, oom_killed: bool}
     */
    private function watch(
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $containerName,
        int $timeoutSeconds,
    ): array {
        $deadline = time() + max(5, $timeoutSeconds);
        $baselineRestarts = null;
        $attempt = 0;
        $last = null;

        while (time() < $deadline) {
            $attempt++;
            // Keeps the deployment row's heartbeat fresh so a long wait does not
            // read as a stalled job to the rest of the platform.
            $deployment->touch();

            $last = $this->inspect($ssh, $containerName);
            $baselineRestarts ??= $last['restart_count'];

            if ($last['missing']) {
                return $this->verdict(false, 'missing', $attempt, $last, $baselineRestarts);
            }

            if ($last['oom_killed']) {
                return $this->verdict(false, 'oom', $attempt, $last, $baselineRestarts);
            }

            if ($last['running'] && ! $last['restarting']) {
                $confirmed = $this->confirmStillUp($ssh, $containerName, $last['restart_count']);
                if ($confirmed !== null) {
                    return $this->verdict(true, 'ready', $attempt, $confirmed, $baselineRestarts);
                }

                $last = $this->inspect($ssh, $containerName);
            }

            if (($last['restart_count'] - $baselineRestarts) >= self::RESTART_ABORT_THRESHOLD) {
                return $this->verdict(false, 'crash_loop', $attempt, $last, $baselineRestarts);
            }

            if (time() + self::POLL_DELAY < $deadline) {
                sleep(self::POLL_DELAY);

                continue;
            }

            break;
        }

        return $this->verdict(
            false,
            'timeout',
            $attempt,
            $last ?? $this->inspect($ssh, $containerName),
            $baselineRestarts ?? 0,
        );
    }

    /**
     * The same container, a couple of seconds later, with the same restart
     * count. Null when it did not hold, so the caller keeps waiting.
     *
     * @return array<string, mixed>|null
     */
    private function confirmStillUp(SSHService $ssh, string $containerName, int $restartCount): ?array
    {
        sleep(self::CONFIRM_DELAY);

        $again = $this->inspect($ssh, $containerName);

        if (! $again['running'] || $again['restarting'] || $again['restart_count'] !== $restartCount) {
            return null;
        }

        return $again;
    }

    /**
     * @param  array<string, mixed>  $inspect
     * @return array{ready: bool, reason: string, attempt: int, restart_count: int, state: string, exit_code: int|null, oom_killed: bool}
     */
    private function verdict(bool $ready, string $reason, int $attempt, array $inspect, int $baselineRestarts): array
    {
        return [
            'ready' => $ready,
            'reason' => $reason,
            'attempt' => $attempt,
            'restart_count' => max(0, ((int) ($inspect['restart_count'] ?? 0)) - $baselineRestarts),
            'state' => (string) ($inspect['state'] ?? 'unknown'),
            'exit_code' => $inspect['exit_code'] ?? null,
            'oom_killed' => (bool) ($inspect['oom_killed'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(SSHService $ssh, string $containerName): array
    {
        try {
            return $this->inspector->inspect($ssh, $containerName);
        } catch (\Throwable $e) {
            Log::warning('Could not inspect the application container during a readiness check', [
                'container_name' => $containerName,
                'error' => $e->getMessage(),
            ]);

            return [
                'missing' => false,
                'running' => false,
                'state' => 'unknown',
                'oom_killed' => false,
                'exit_code' => null,
                'restarting' => false,
                'restart_count' => 0,
            ];
        }
    }

    /**
     * Turn a failed wait into the most specific exception the evidence allows.
     *
     * @param  array{ready: bool, reason: string, attempt: int, restart_count: int, state: string, exit_code: int|null, oom_killed: bool}  $outcome
     */
    private function explain(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        string $stack,
        array $outcome,
        int $elapsed,
    ): \RuntimeException {
        $containerName = (string) $deployment->container_name;

        // A memory kill is not a bug in the customer's code, and reading it as
        // one sends them looking for a fault that is not there.
        if ($outcome['reason'] === 'oom') {
            return $this->fail($service, $deployment, $stack, $outcome, $elapsed, [],
                'The application container was killed for using more memory than its plan allows. '
                .'Upgrade the plan, or reduce what the application loads at start-up.');
        }

        if ($outcome['reason'] === 'missing') {
            return $this->fail($service, $deployment, $stack, $outcome, $elapsed, [],
                'The application container is not on the node. Recreate the stack and try again.');
        }

        $crash = $this->crashReader->read($stack, $this->stackCommands->containerLogs($ssh, $containerName));

        $unparsable = $crash['unparsable_variables'] ?? [];
        $suggestion = app(ContainerOriginSettingsService::class)->suggestion($deployment, $unparsable);

        // A value the application rejected is as much a configuration problem
        // as one nobody supplied, so both park the site on a notice naming the
        // settings rather than leaving a visitor to meet a crash loop.
        if ($crash['missing_variables'] !== [] || $unparsable !== []) {
            $invalid = array_values(array_diff($unparsable, $crash['missing_variables']));

            $this->events->record($service, $deployment, 'application_readiness_awaiting_configuration', [
                'stack' => $stack,
                'missing_variables' => $crash['missing_variables'],
                'invalid_variables' => $invalid,
                'elapsed_seconds' => $elapsed,
            ]);

            Log::info('Application is waiting on settings only the customer holds', [
                'service_id' => $service->id,
                'deployment_id' => $deployment->id,
                'container_name' => $containerName,
                'stack' => $stack,
                'missing_variables' => $crash['missing_variables'],
                'invalid_variables' => $invalid,
            ]);

            return new ApplicationConfigurationRequiredException(
                $crash['missing_variables'],
                trim($crash['message'].($suggestion !== null ? ' '.$suggestion : '')),
                $invalid,
            );
        }

        $prefix = $outcome['reason'] === 'crash_loop'
            ? 'The application started and exited '.$outcome['restart_count'].' time(s) while the platform watched, '
                .'so it is not staying up. '
            : 'The application did not come up within '.$elapsed.' seconds (last state: '.$outcome['state'].'). ';

        return $this->fail(
            $service,
            $deployment,
            $stack,
            $outcome,
            $elapsed,
            $crash['missing_variables'],
            trim($prefix.$crash['message'].($suggestion !== null ? ' '.$suggestion : '')),
        );
    }

    /**
     * @param  array{ready: bool, reason: string, attempt: int, restart_count: int, state: string, exit_code: int|null, oom_killed: bool}  $outcome
     * @param  list<string>  $missingVariables
     */
    private function fail(
        Service $service,
        ContainerDeployment $deployment,
        string $stack,
        array $outcome,
        int $elapsed,
        array $missingVariables,
        string $message,
    ): \RuntimeException {
        $this->events->record($service, $deployment, 'application_readiness_failed', [
            'stack' => $stack,
            'elapsed_seconds' => $elapsed,
            'restart_count' => $outcome['restart_count'],
            'exit_code' => $outcome['exit_code'],
            'oom_killed' => $outcome['oom_killed'],
            'cause' => $outcome['reason'],
        ]);

        // The container log is never logged here. It can carry customer secrets,
        // it already exists on the node, and it reaches the customer through the
        // pull log, which is their own record. The classification is what a
        // reader of this file needs.
        Log::warning('Application readiness check failed', [
            'service_id' => $service->id,
            'deployment_id' => $deployment->id,
            'container_name' => $deployment->container_name,
            'stack' => $stack,
            'attempt' => $outcome['attempt'],
            'restart_count' => $outcome['restart_count'],
            'elapsed_seconds' => $elapsed,
            'state' => $outcome['state'],
            'exit_code' => $outcome['exit_code'],
            'cause' => $outcome['reason'],
            'missing_variables' => $missingVariables,
        ]);

        return new \RuntimeException(trim($message));
    }

    private function resolveTimeout(?int $timeoutSeconds): int
    {
        if ($timeoutSeconds !== null) {
            return max(5, $timeoutSeconds);
        }

        return max(5, (int) config('containers.application_readiness.timeout_seconds', 180));
    }
}
