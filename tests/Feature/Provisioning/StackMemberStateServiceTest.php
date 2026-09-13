<?php

namespace Tests\Feature\Provisioning;

use App\Enums\StackMemberKind;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ComposeProjectStateProbe;
use App\Services\Provisioning\StackMember;
use App\Services\Provisioning\StackMemberResolver;
use App\Services\Provisioning\StackMemberState;
use App\Services\Provisioning\StackMemberStateService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The snapshot is the only thing the project page reads, so what the tick
 * writes, how it ages, and what an unreachable host leaves behind are the
 * contract.
 */
class StackMemberStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private const YAML = <<<'YAML'
services:
  user-1-service-9-laravel:
    image: talksasa/laravel-runtime:8.3
    container_name: user-1-service-9-laravel
  db:
    image: mysql:8
    container_name: user-1-service-9-laravel-db
YAML;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_node_probe_writes_one_snapshot_per_deployment_and_marks_absent_members_missing(): void
    {
        Carbon::setTestNow('2026-09-13 10:15:02');
        [$a] = $this->deployment('user-1-service-9-laravel');
        [$b] = $this->deployment('user-1-service-10-laravel');

        $ssh = $this->ssh(implode("\n", [
            'user-1-service-9-laravel|user-1-service-9-laravel|user-1-service-9-laravel|running|Up 3 hours|',
            'user-1-service-9-laravel|db|user-1-service-9-laravel-db|exited|Exited (1) 12 minutes ago|',
            'user-1-service-10-laravel|user-1-service-10-laravel|user-1-service-10-laravel|running|Up 1 hour|',
        ]));

        $snapshots = $this->service()->refreshNode($ssh, collect([$a, $b]));

        $this->assertCount(2, $snapshots);
        $stored = $a->fresh()->member_states;
        $this->assertSame(1, $stored['version']);
        $this->assertTrue($stored['reachable']);
        $this->assertSame('2026-09-13T10:15:02+00:00', $stored['checked_at']);
        $this->assertSame('stopped', $stored['containers']['user-1-service-9-laravel-db']['state']);
        $this->assertSame('Exited (1) 12 minutes ago', $stored['containers']['user-1-service-9-laravel-db']['status']);
        $this->assertSame('db', $stored['containers']['user-1-service-9-laravel-db']['service']);
        $this->assertNotNull($a->fresh()->member_states_checked_at);

        // Overlay: the app is running, the db stopped, and a member the probe
        // never saw is "missing" because the host answered.
        $members = $this->service()->overlay($a->fresh(), [
            $this->member('user-1-service-9-laravel', 'user-1-service-9-laravel'),
            $this->member('db', 'user-1-service-9-laravel-db'),
            $this->member('edge', 'user-1-service-9-laravel-edge'),
        ]);
        $this->assertSame([StackMemberState::RUNNING, StackMemberState::STOPPED, StackMemberState::MISSING], array_map(fn (StackMember $m) => $m->state->state, $members));
        $this->assertFalse($members[0]->state->stale);
    }

    #[Test]
    public function a_member_is_matched_by_compose_service_label_when_its_name_differs(): void
    {
        Carbon::setTestNow('2026-09-13 10:15:02');
        [$deployment] = $this->deployment('user-1-service-9-chatwoot');
        $ssh = $this->ssh('user-1-service-9-chatwoot|redis|user-1-service-9-chatwoot_redis_1|running|Up|');

        $this->service()->refresh($deployment, $ssh);

        $members = $this->service()->overlay($deployment->fresh(), [$this->member('redis', 'user-1-service-9-chatwoot-redis-1')]);
        $this->assertSame(StackMemberState::RUNNING, $members[0]->state->state);
    }

    #[Test]
    public function an_old_snapshot_is_stale_and_a_very_old_one_is_unknown(): void
    {
        Carbon::setTestNow('2026-09-13 10:00:00');
        [$deployment] = $this->deployment('user-1-service-9-laravel');
        $this->service()->refresh($deployment, $this->ssh('user-1-service-9-laravel|db|user-1-service-9-laravel-db|running|Up|'));
        $member = $this->member('db', 'user-1-service-9-laravel-db');

        Carbon::setTestNow('2026-09-13 10:20:00');
        $state = $this->service()->overlay($deployment->fresh(), [$member])[0]->state;
        $this->assertSame(StackMemberState::RUNNING, $state->state);
        $this->assertTrue($state->stale);
        $this->assertTrue($this->service()->isStale($deployment->fresh()));

        Carbon::setTestNow('2026-09-13 11:30:00');
        $state = $this->service()->overlay($deployment->fresh(), [$member])[0]->state;
        $this->assertSame(StackMemberState::UNKNOWN, $state->state);
    }

    #[Test]
    public function an_unreachable_host_keeps_the_last_containers_and_records_the_error(): void
    {
        Carbon::setTestNow('2026-09-13 10:00:00');
        [$deployment] = $this->deployment('user-1-service-9-laravel');
        $this->service()->refresh($deployment, $this->ssh('user-1-service-9-laravel|db|user-1-service-9-laravel-db|running|Up|'));

        Carbon::setTestNow('2026-09-13 10:05:00');
        $failing = Mockery::mock(SSHService::class);
        $failing->shouldReceive('exec')->andThrow(new \RuntimeException('connection refused'));
        $this->service()->refresh($deployment->fresh(), $failing);

        $stored = $deployment->fresh()->member_states;
        $this->assertFalse($stored['reachable']);
        $this->assertSame('connection refused', $stored['error']);
        $this->assertSame('2026-09-13T10:00:00+00:00', $stored['checked_at'], 'checked_at is not advanced by a failed probe');
        $this->assertArrayHasKey('user-1-service-9-laravel-db', $stored['containers']);

        // Fresh enough to still trust the last observation.
        $state = $this->service()->overlay($deployment->fresh(), [$this->member('db', 'user-1-service-9-laravel-db')])[0]->state;
        $this->assertSame(StackMemberState::RUNNING, $state->state);

        // Once that observation is stale too, the answer is unknown, never stopped.
        Carbon::setTestNow('2026-09-13 10:30:00');
        $state = $this->service()->overlay($deployment->fresh(), [$this->member('db', 'user-1-service-9-laravel-db')])[0]->state;
        $this->assertSame(StackMemberState::UNKNOWN, $state->state);
    }

    #[Test]
    public function apply_inspect_updates_one_member_without_re_stamping_the_whole_stack(): void
    {
        Carbon::setTestNow('2026-09-13 10:00:00');
        [$deployment] = $this->deployment('user-1-service-9-laravel');
        $this->service()->refresh($deployment, $this->ssh(implode("\n", [
            'user-1-service-9-laravel|user-1-service-9-laravel|user-1-service-9-laravel|running|Up|',
            'user-1-service-9-laravel|db|user-1-service-9-laravel-db|exited|Exited (1)|',
        ])));

        Carbon::setTestNow('2026-09-13 10:02:00');
        $this->service()->applyInspect($deployment->fresh(), $this->member('db', 'user-1-service-9-laravel-db'), ['missing' => false, 'running' => true, 'state' => 'running']);

        $stored = $deployment->fresh()->member_states;
        $this->assertSame('running', $stored['containers']['user-1-service-9-laravel-db']['state']);
        $this->assertSame('2026-09-13T10:02:00+00:00', $stored['containers']['user-1-service-9-laravel-db']['checked_at']);
        $this->assertSame('2026-09-13T10:00:00+00:00', $stored['containers']['user-1-service-9-laravel']['checked_at']);
        $this->assertSame('2026-09-13T10:00:00+00:00', $stored['checked_at']);
    }

    #[Test]
    public function an_unchanged_fresh_snapshot_is_not_rewritten(): void
    {
        Carbon::setTestNow('2026-09-13 10:00:00');
        [$deployment] = $this->deployment('user-1-service-9-laravel');
        $row = 'user-1-service-9-laravel|db|user-1-service-9-laravel-db|running|Up 5 minutes|';
        $this->service()->refresh($deployment, $this->ssh($row));
        $first = $deployment->fresh()->updated_at;

        Carbon::setTestNow('2026-09-13 10:05:00');
        $this->service()->refresh($deployment->fresh(), $this->ssh($row));

        $this->assertEquals($first, $deployment->fresh()->updated_at);
        $this->assertSame('2026-09-13T10:00:00+00:00', $deployment->fresh()->member_states['checked_at']);
    }

    private function service(): StackMemberStateService
    {
        return new StackMemberStateService(new ComposeProjectStateProbe, new StackMemberResolver);
    }

    private function ssh(string $output): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn($output);

        return $ssh;
    }

    private function member(string $key, string $containerName): StackMember
    {
        return new StackMember($key, StackMemberKind::Database, 'Database', $containerName, 9, 'mysql', StackMemberState::unknown());
    }

    /**
     * @return array{0: ContainerDeployment}
     */
    private function deployment(string $containerName): array
    {
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create(['status' => 'active', 'provisioning_driver_key' => 'container']);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => $containerName,
            'docker_compose_content' => self::YAML,
        ]);

        return [$deployment];
    }
}
