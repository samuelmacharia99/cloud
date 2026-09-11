<?php

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\User;
use App\Services\Provisioning\DirectAdminNodeInspector;
use App\Services\Provisioning\NodeDoctorService;
use App\Services\Provisioning\NodeIncidentRecorder;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * A poll that alerts on every run is a poll nobody reads.
 *
 * A node down at three in the morning would send thirty Telegram messages
 * before anybody woke up, and the thirty-first would be muted along with
 * everything after it. So the scan acts on the difference between two readings,
 * not on the reading.
 */
class NodeDoctorIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_new_critical_finding_alerts_once_and_is_recorded(): void
    {
        $node = $this->node();
        $telegram = $this->telegramSpy();

        app(NodeIncidentRecorder::class)->reconcile($node, $this->diagnosis([$this->httpdDown()]));

        $telegram->shouldHaveReceived('systemAlert')->once();
        $this->assertDatabaseHas('node_events', [
            'node_id' => $node->id,
            'event' => 'node_issue_opened',
            'severity' => 'critical',
        ]);
    }

    public function test_the_same_finding_on_the_next_scan_says_nothing(): void
    {
        $node = $this->node();
        $telegram = $this->telegramSpy();
        $recorder = app(NodeIncidentRecorder::class);

        $recorder->reconcile($node, $this->diagnosis([$this->httpdDown()]));
        $recorder->reconcile($node, $this->diagnosis([$this->httpdDown()]));
        $recorder->reconcile($node, $this->diagnosis([$this->httpdDown()]));

        $telegram->shouldHaveReceived('systemAlert')->once();
        $this->assertSame(1, NodeEvent::where('event', 'node_issue_opened')->count());
    }

    public function test_recovery_says_so_exactly_once(): void
    {
        $node = $this->node();
        $telegram = $this->telegramSpy();
        $recorder = app(NodeIncidentRecorder::class);

        $recorder->reconcile($node, $this->diagnosis([$this->httpdDown()]));
        $recorder->reconcile($node, $this->diagnosis([]));
        $recorder->reconcile($node, $this->diagnosis([]));

        // One for the problem, one for the all-clear, and nothing after.
        $telegram->shouldHaveReceived('systemAlert')->twice();
        $this->assertSame(1, NodeEvent::where('event', 'node_issue_resolved')->count());
    }

    public function test_a_warning_is_written_down_but_nobody_is_woken_up(): void
    {
        $node = $this->node();
        $telegram = $this->telegramSpy();

        app(NodeIncidentRecorder::class)->reconcile($node, $this->diagnosis([[
            'id' => 'node_swapping',
            'severity' => 'warning',
            'title' => 'The server is swapping',
            'summary' => 'Swap is 40% used.',
            'evidence' => [],
        ]]));

        $telegram->shouldNotHaveReceived('systemAlert');
        $this->assertDatabaseHas('node_events', ['event' => 'node_issue_opened', 'severity' => 'warning']);
    }

    public function test_a_second_problem_appearing_later_gets_its_own_alert(): void
    {
        $node = $this->node();
        $telegram = $this->telegramSpy();
        $recorder = app(NodeIncidentRecorder::class);

        $recorder->reconcile($node, $this->diagnosis([$this->httpdDown()]));
        $recorder->reconcile($node, $this->diagnosis([$this->httpdDown(), [
            'id' => 'node_service_down_database',
            'severity' => 'critical',
            'title' => 'The database server is not running',
            'summary' => 'mysqld is dead.',
            'evidence' => [],
        ]]));

        $telegram->shouldHaveReceived('systemAlert')->twice();
    }

    public function test_an_admin_can_scan_a_node_and_repair_from_the_result(): void
    {
        $node = $this->node();
        $inspector = Mockery::mock(DirectAdminNodeInspector::class);
        $inspector->shouldReceive('inspect')->andReturn([
            'reachable' => true,
            'error' => null,
            'init' => 'systemd',
            'services' => [
                'web' => ['unit' => 'httpd', 'running' => false, 'state' => 'dead', 'seconds_since_start' => null],
            ],
            'filesystems' => [],
            'memory' => ['total' => 0, 'used' => 0, 'swap_total' => 0, 'swap_used' => 0],
            'load' => ['one' => 0.1, 'five' => 0.1, 'fifteen' => 0.1, 'cores' => 8, 'per_core' => 0.01],
            'task_queue' => null,
            'uptime_seconds' => 100,
        ]);
        $this->app->instance(DirectAdminNodeInspector::class, $inspector);
        $this->telegramSpy();

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.nodes.health-scan', $node))
            ->assertOk();

        $response->assertJsonPath('findings.0.id', 'node_service_down_web');
        $response->assertJsonPath('findings.0.treat_action', NodeDoctorService::START_SERVICE_ACTION);
    }

    public function test_a_repair_is_refused_for_a_service_the_node_never_reported(): void
    {
        $node = $this->node();

        $this->actingAs($this->admin())
            ->postJson(route('admin.nodes.health-repair', $node), [
                'action' => NodeDoctorService::START_SERVICE_ACTION,
                'unit' => 'talksasa-queue',
            ])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('node_events', ['event' => 'node_repair_failed']);
    }

    public function test_a_customer_cannot_reach_the_repair_endpoint(): void
    {
        $node = $this->node();

        $this->actingAs(User::factory()->customer()->create())
            ->postJson(route('admin.nodes.health-repair', $node), [
                'action' => NodeDoctorService::RESTART_PANEL_ACTION,
            ])
            ->assertForbidden();
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function diagnosis(array $findings): array
    {
        return ['reachable' => true, 'findings' => $findings, 'checks' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function httpdDown(): array
    {
        return [
            'id' => 'node_service_down_web',
            'severity' => 'critical',
            'title' => 'The web server is not running',
            'summary' => 'httpd is dead on this node.',
            'evidence' => ['httpd is dead'],
        ];
    }

    private function telegramSpy(): TelegramMonitorBridge
    {
        $telegram = Mockery::spy(TelegramMonitorBridge::class);
        $this->app->instance(TelegramMonitorBridge::class, $telegram);

        return $telegram;
    }

    private function node(): Node
    {
        return Node::factory()->directAdmin()->create();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
