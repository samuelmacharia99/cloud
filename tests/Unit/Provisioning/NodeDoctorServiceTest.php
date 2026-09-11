<?php

namespace Tests\Unit\Provisioning;

use App\Models\Node;
use App\Services\Provisioning\DirectAdminNodeInspector;
use App\Services\Provisioning\NodeDoctorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every repair offered here restarts something the platform owns. None of them
 * touch a customer's files, database or account, and that boundary is what
 * makes a one-click button safe on a box holding other people's businesses.
 *
 * The tests that matter most are the ones about what is NOT offered: a full
 * disk gets no delete button, a flapping service gets no restart button, and an
 * unreachable node is never reported as healthy.
 */
class NodeDoctorServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_dead_web_server_is_critical_and_can_be_started(): void
    {
        $finding = $this->finding(['services' => [
            'web' => ['unit' => 'httpd', 'running' => false, 'state' => 'dead', 'seconds_since_start' => null],
        ]], 'node_service_down_web');

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame(NodeDoctorService::START_SERVICE_ACTION, $finding['treat_action']);
        $this->assertSame('httpd', $finding['treat_payload']['unit']);
        $this->assertStringContainsString('Every site on this server is down', $finding['summary']);
    }

    #[Test]
    public function a_flapping_service_is_reported_and_offered_nothing(): void
    {
        // Restarting it again is exactly what it is already doing to itself.
        $finding = $this->finding(['services' => [
            'web' => ['unit' => 'httpd', 'running' => true, 'state' => 'running', 'seconds_since_start' => 45],
        ]], 'node_service_flapping_web');

        $this->assertSame('warning', $finding['severity']);
        $this->assertNull($finding['treat_action']);
    }

    #[Test]
    public function a_healthy_service_produces_no_finding(): void
    {
        $this->assertNull($this->find(['services' => [
            'web' => ['unit' => 'httpd', 'running' => true, 'state' => 'running', 'seconds_since_start' => 86_400],
        ]], 'node_service_down_web'));
    }

    #[Test]
    public function a_full_partition_is_never_given_a_delete_button(): void
    {
        // On a shared server the space belongs to customers, and the platform
        // is not the one to decide which of it goes.
        $finding = $this->finding(['filesystems' => [
            ['mount' => '/', 'total_bytes' => 107_374_182_400, 'used_bytes' => 104_000_000_000, 'used_percent' => 97, 'inode_used_percent' => 5],
        ]], 'node_disk_'.md5('/'));

        $this->assertSame('critical', $finding['severity']);
        $this->assertNull($finding['treat_action']);
        $this->assertStringContainsString('Nothing here deletes a file', $finding['summary']);
    }

    #[Test]
    public function inode_exhaustion_is_reported_on_a_partition_with_free_space(): void
    {
        $finding = $this->finding(['filesystems' => [
            ['mount' => '/home', 'total_bytes' => 536_870_912_000, 'used_bytes' => 220_000_000_000, 'used_percent' => 41, 'inode_used_percent' => 98],
        ]], 'node_inodes_'.md5('/home'));

        $this->assertSame('critical', $finding['severity']);
        $this->assertStringContainsString('cannot create a single new file', $finding['summary']);
        $this->assertNull($this->find(['filesystems' => [
            ['mount' => '/home', 'total_bytes' => 1, 'used_bytes' => 0, 'used_percent' => 41, 'inode_used_percent' => 98],
        ]], 'node_disk_'.md5('/home')), 'Space at 41% is not also reported as full.');
    }

    #[Test]
    public function a_stalled_directadmin_queue_offers_the_documented_remedy(): void
    {
        $finding = $this->finding(
            ['task_queue' => ['length' => 12, 'age_seconds' => 7200]],
            'node_task_queue_stalled',
        );

        $this->assertSame(NodeDoctorService::RESTART_PANEL_ACTION, $finding['treat_action']);
        $this->assertStringContainsString('silently', $finding['summary']);
    }

    #[Test]
    public function a_busy_queue_that_is_draining_is_not_an_incident(): void
    {
        $this->assertNull($this->find(
            ['task_queue' => ['length' => 40, 'age_seconds' => 20]],
            'node_task_queue_stalled',
        ));
    }

    #[Test]
    public function load_is_judged_against_the_cores_it_was_measured_on(): void
    {
        $this->assertNull($this->find(
            ['load' => ['one' => 12.0, 'five' => 10.0, 'fifteen' => 9.0, 'cores' => 16, 'per_core' => 0.75]],
            'node_load_high',
        ), 'Twelve across sixteen cores is a busy server, not a broken one.');

        $this->assertNotNull($this->find(
            ['load' => ['one' => 12.0, 'five' => 10.0, 'fifteen' => 9.0, 'cores' => 2, 'per_core' => 6.0]],
            'node_load_high',
        ), 'Twelve across two cores is nobody being served.');
    }

    #[Test]
    public function an_unreachable_node_is_not_reported_as_healthy(): void
    {
        $inspector = Mockery::mock(DirectAdminNodeInspector::class);
        $inspector->shouldReceive('inspect')->andReturn([
            'reachable' => false,
            'error' => 'SSH connection refused',
            'services' => [],
            'filesystems' => [],
        ]);

        $result = (new NodeDoctorService($inspector))->diagnose($this->node());

        $this->assertFalse($result['reachable']);
        $this->assertSame('node_unreachable', $result['findings'][0]['id']);
        $this->assertStringContainsString('could not be asked', $result['findings'][0]['summary']);
    }

    #[Test]
    public function the_worst_news_is_listed_first(): void
    {
        $result = $this->diagnose([
            'services' => [
                'web' => ['unit' => 'httpd', 'running' => true, 'state' => 'running', 'seconds_since_start' => 30],
                'database' => ['unit' => 'mysqld', 'running' => false, 'state' => 'dead', 'seconds_since_start' => null],
            ],
        ]);

        $this->assertSame('critical', $result['findings'][0]['severity']);
        $this->assertSame('node_service_down_database', $result['findings'][0]['id']);
    }

    #[Test]
    public function a_repair_will_only_touch_a_service_this_node_reports(): void
    {
        // A repair that accepts any name from a request is a remote root shell
        // wearing a button.
        $doctor = app(NodeDoctorService::class);
        $node = $this->node();

        foreach (['nginx; rm -rf /', 'talksasa-queue', '../../bin/sh', ''] as $unit) {
            $result = $doctor->treat($node, NodeDoctorService::START_SERVICE_ACTION, ['unit' => $unit]);
            $this->assertFalse($result['success'], "Refused: {$unit}");
        }
    }

    #[Test]
    public function a_repair_is_refused_on_a_node_that_is_not_directadmin(): void
    {
        $node = Node::factory()->containerHost()->create();

        $result = app(NodeDoctorService::class)->treat($node, NodeDoctorService::RESTART_PANEL_ACTION);

        $this->assertFalse($result['success']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function finding(array $overrides, string $id): array
    {
        $finding = $this->find($overrides, $id);
        $this->assertNotNull($finding, "No finding {$id}.");

        return $finding;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>|null
     */
    private function find(array $overrides, string $id): ?array
    {
        foreach ($this->diagnose($overrides)['findings'] as $finding) {
            if ($finding['id'] === $id) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function diagnose(array $overrides): array
    {
        $reading = array_merge([
            'reachable' => true,
            'error' => null,
            'init' => 'systemd',
            'services' => [],
            'filesystems' => [],
            'memory' => ['total' => 0, 'used' => 0, 'swap_total' => 0, 'swap_used' => 0],
            'load' => ['one' => 0.1, 'five' => 0.1, 'fifteen' => 0.1, 'cores' => 8, 'per_core' => 0.01],
            'task_queue' => null,
            'uptime_seconds' => 86_400,
        ], $overrides);

        $inspector = Mockery::mock(DirectAdminNodeInspector::class);
        $inspector->shouldReceive('inspect')->andReturn($reading);

        return (new NodeDoctorService($inspector))->diagnose($this->node());
    }

    private function node(): Node
    {
        return Node::factory()->directAdmin()->create();
    }
}
