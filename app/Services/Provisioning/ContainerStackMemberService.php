<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Restarts one container of a stack on its own: `docker compose restart
 * <service>` from the stack directory, then a bounded poll until it is
 * running again. Only databases and caches qualify; the application
 * containers are restarted with the whole stack, which is what the customer
 * already has under Restart.
 *
 * Every attempt leaves deployment events, and a per-deployment lock keeps two
 * clicks from stacking.
 */
class ContainerStackMemberService
{
    public const RESTART_TIMEOUT_SECONDS = 120;

    public const READY_WAIT_SECONDS = 90;

    public const POLL_INTERVAL_SECONDS = 3;

    public const LOCK_SECONDS = 180;

    public const EVENT_STARTED = 'stack_member_restart_started';

    public const EVENT_RESTARTED = 'stack_member_restarted';

    public const EVENT_FAILED = 'stack_member_restart_failed';

    /**
     * @param  (Closure(Node): SSHService)|null  $sshFactory
     * @param  (Closure(int): void)|null  $sleeper  overridable so tests do not wait
     */
    public function __construct(
        private StackMemberResolver $resolver,
        private ContainerRuntimeInspector $inspector,
        private StackMemberStateService $states,
        private ContainerDeploymentEventRecorder $events,
        private ?Closure $sshFactory = null,
        private ?Closure $sleeper = null,
    ) {}

    /**
     * Resolves and validates the target without touching the node.
     *
     * @throws StackMemberActionException
     */
    public function restartableMember(Service $service, string $composeKey): StackMember
    {
        $service->loadMissing('containerDeployment.node');
        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->node) {
            throw StackMemberActionException::notDeployed();
        }
        if ((string) $deployment->status === 'terminated') {
            throw StackMemberActionException::terminated();
        }

        $member = $this->resolver->member($service, $composeKey);
        if ($member === null) {
            throw StackMemberActionException::unknownMember($composeKey);
        }
        if (! $member->canRestartAlone()) {
            throw StackMemberActionException::notRestartable($member);
        }

        return $member;
    }

    /**
     * @throws StackMemberActionException
     */
    public function restart(Service $service, string $composeKey, ?User $actor = null): StackMemberActionResult
    {
        $member = $this->restartableMember($service, $composeKey);
        $deployment = $service->containerDeployment;
        $node = $deployment->node;

        if (! $node->ssh_username || (! $node->ssh_password && ! $node->da_login_key)) {
            throw StackMemberActionException::nodeNotConfigured();
        }

        $lock = Cache::lock('stack-member-action:'.$deployment->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw StackMemberActionException::busy();
        }

        $ssh = null;
        try {
            $ssh = $this->sshFactory ? ($this->sshFactory)($node) : SSHService::forNode($node);
            app(ContainerDeploymentService::class)->ensureComposeFileExists($ssh, $deployment);

            $this->events->record($service, $deployment, self::EVENT_STARTED, [
                'compose_key' => $member->composeKey,
                'container_name' => $member->containerName,
                'kind' => $member->kind->value,
                'actor_user_id' => $actor?->id,
            ]);

            $path = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
            $before = $this->inspector->inspect($ssh, $member->containerName);
            $command = ($before['missing'] ?? false)
                ? 'up -d --no-deps --pull never '.escapeshellarg($member->composeKey)
                : 'restart -t 30 '.escapeshellarg($member->composeKey);

            $ssh->exec(
                'cd '.escapeshellarg($path).' && docker compose -f docker-compose.yml '.$command,
                self::RESTART_TIMEOUT_SECONDS
            );

            [$inspect, $waited] = $this->waitForMemberRunning($ssh, $member, self::READY_WAIT_SECONDS);
            $this->states->applyInspect($deployment, $member, $inspect);

            try {
                $this->states->refresh($deployment->fresh(), $ssh);
            } catch (\Throwable) {
                // The member's own state is already recorded; siblings catch up on the next tick.
            }

            $state = StackMemberState::fromDocker((string) ($inspect['state'] ?? ''), null, now()->toImmutable());
            $running = ($inspect['running'] ?? false) === true && ($inspect['restarting'] ?? false) !== true;

            if (! $running) {
                $this->events->record($service, $deployment, self::EVENT_FAILED, [
                    'compose_key' => $member->composeKey,
                    'container_name' => $member->containerName,
                    'waited_seconds' => $waited,
                    'state' => $state->state,
                    'exit_code' => $inspect['exit_code'] ?? null,
                ]);

                throw StackMemberActionException::stillNotRunning($member, $waited);
            }

            $this->events->record($service, $deployment, self::EVENT_RESTARTED, [
                'compose_key' => $member->composeKey,
                'container_name' => $member->containerName,
                'waited_seconds' => $waited,
                'state' => $state->state,
            ]);

            return new StackMemberActionResult($member, true, $waited, $state);
        } catch (StackMemberActionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->events->record($service, $deployment, self::EVENT_FAILED, [
                'compose_key' => $member->composeKey,
                'container_name' => $member->containerName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $ssh?->disconnect();
            $lock->release();
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: int} the last inspect and the seconds waited
     */
    private function waitForMemberRunning(SSHService $ssh, StackMember $member, int $deadlineSeconds): array
    {
        $waited = 0;
        $inspect = $this->inspector->inspect($ssh, $member->containerName);

        while ($waited < $deadlineSeconds) {
            if (($inspect['running'] ?? false) === true && ($inspect['restarting'] ?? false) !== true) {
                return [$inspect, $waited];
            }

            ($this->sleeper ?? static fn (int $seconds) => sleep($seconds))(self::POLL_INTERVAL_SECONDS);
            $waited += self::POLL_INTERVAL_SECONDS;
            $inspect = $this->inspector->inspect($ssh, $member->containerName);
        }

        return [$inspect, $waited];
    }
}
