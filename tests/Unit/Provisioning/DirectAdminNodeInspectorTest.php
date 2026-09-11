<?php

namespace Tests\Unit\Provisioning;

use App\Models\Node;
use App\Services\Provisioning\DirectAdminNodeInspector;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform polled its shared server every two minutes for months and read
 * six numbers, none of which change when httpd dies.
 *
 * These fixtures are the shapes two real machines answer with. A DirectAdmin
 * box is usually AlmaLinux, where the web server is httpd; some are Debian,
 * where it is apache2. A check that assumes either one reports a healthy server
 * with a dead web server on it.
 */
class DirectAdminNodeInspectorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reads_an_almalinux_box_without_being_told_what_is_on_it(): void
    {
        $result = $this->inspect($this->almaLinux());

        $this->assertTrue($result['reachable']);
        $this->assertSame('systemd', $result['init']);
        $this->assertSame('httpd', $result['services']['web']['unit']);
        $this->assertTrue($result['services']['web']['running']);
        $this->assertSame('mysqld', $result['services']['database']['unit']);
        $this->assertSame('directadmin', $result['services']['panel']['unit']);
    }

    #[Test]
    public function it_reads_a_debian_box_and_finds_apache_where_httpd_would_be(): void
    {
        $result = $this->inspect($this->debian());

        $this->assertSame('apache2', $result['services']['web']['unit']);
        $this->assertSame('mariadb', $result['services']['database']['unit']);
    }

    #[Test]
    public function a_stopped_web_server_is_visible(): void
    {
        $result = $this->inspect(str_replace(
            'httpd.service loaded active running',
            'httpd.service loaded active dead',
            $this->almaLinux(),
        ));

        $this->assertFalse($result['services']['web']['running']);
        $this->assertSame('dead', $result['services']['web']['state']);
    }

    #[Test]
    public function inodes_are_read_alongside_space_because_they_run_out_separately(): void
    {
        // The partition below is 41% full and out of inodes. A check that reads
        // only one of the two calls that healthy, and mail stops being
        // delivered with no error anybody sees.
        $home = collect($this->inspect($this->almaLinux())['filesystems'])
            ->firstWhere('mount', '/home');

        $this->assertSame(41, $home['used_percent']);
        $this->assertSame(98, $home['inode_used_percent']);
    }

    #[Test]
    public function pseudo_filesystems_are_left_out(): void
    {
        $mounts = array_column($this->inspect($this->almaLinux())['filesystems'], 'mount');

        $this->assertContains('/', $mounts);
        $this->assertNotContains('/dev/shm', $mounts);
        $this->assertNotContains('/run', $mounts);
    }

    #[Test]
    public function load_is_reported_against_the_cores_it_was_measured_on(): void
    {
        $load = $this->inspect($this->almaLinux())['load'];

        $this->assertSame(8, $load['cores']);
        $this->assertSame(12.5, $load['one']);
        $this->assertSame(1.56, $load['per_core']);
    }

    #[Test]
    public function swap_in_use_is_visible_before_the_oom_killer_picks_somebody(): void
    {
        $memory = $this->inspect($this->almaLinux())['memory'];

        $this->assertSame(16_777_216_000, $memory['total']);
        $this->assertGreaterThan(0, $memory['swap_used']);
    }

    #[Test]
    public function the_directadmin_task_queue_is_measured(): void
    {
        $queue = $this->inspect($this->almaLinux())['task_queue'];

        $this->assertSame(3, $queue['length']);
        $this->assertNotNull($queue['age_seconds']);
    }

    #[Test]
    public function a_box_without_the_queue_file_reports_nothing_rather_than_zero(): void
    {
        $withoutQueue = preg_replace(
            '/===TALKSASA===queue\n.*?\n===TALKSASA===end/s',
            "===TALKSASA===queue\nskip\n===TALKSASA===end",
            $this->almaLinux(),
        );

        $result = $this->inspect((string) $withoutQueue);

        $this->assertNull($result['task_queue']);
    }

    #[Test]
    public function a_node_that_cannot_be_reached_is_not_called_healthy(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andThrow(new \RuntimeException('SSH connection refused'));

        $result = app(DirectAdminNodeInspector::class)->inspect($this->node(), $ssh);

        $this->assertFalse($result['reachable']);
        $this->assertStringContainsString('refused', (string) $result['error']);
        $this->assertSame([], $result['services']);
    }

    #[Test]
    public function the_whole_inspection_is_one_round_trip(): void
    {
        // Six separate execs every two minutes per node is what the old probe
        // did. A dozen service checks on that shape would outlast the interval.
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->once()->andReturn($this->almaLinux());

        app(DirectAdminNodeInspector::class)->inspect($this->node(), $ssh);

        $this->assertTrue(true, 'exec was called exactly once.');
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(string $output): array
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn($output);

        return app(DirectAdminNodeInspector::class)->inspect($this->node(), $ssh);
    }

    private function node(): Node
    {
        return Node::factory()->directAdmin()->create();
    }

    private function almaLinux(): string
    {
        $now = 900_000_000;
        $queueModified = time() - 3600;

        return <<<OUT
        ===TALKSASA===init
        systemd
        ===TALKSASA===units
        httpd.service loaded active running
        mysqld.service loaded active running
        exim.service loaded active running
        dovecot.service loaded active running
        named.service loaded active running
        directadmin.service loaded active running
        sshd.service loaded active running
        crond.service loaded active running
        ===TALKSASA===started
        httpd {$now}
        mysqld 400000000
        directadmin 300000000
        ===TALKSASA===df
        /dev/vda1 107374182400 42949672960 64424509440 41% /
        /dev/vdb1 536870912000 220200960000 316669952000 41% /home
        tmpfs 8388608000 0 8388608000 0% /dev/shm
        tmpfs 1677721600 1048576 1676673024 1% /run
        ===TALKSASA===inodes
        /dev/vda1 xfs 52428800 1048576 51380224 2% /
        /dev/vdb1 xfs 26214400 25690112 524288 98% /home
        tmpfs tmpfs 2048000 1 2047999 1% /dev/shm
        tmpfs tmpfs 2048000 800 2047200 1% /run
        ===TALKSASA===mem
        Mem: 16777216000 12884901888 1073741824 268435456 2818572288 3221225472
        Swap: 4294967296 1073741824 3221225472
        ===TALKSASA===load
        12.50 9.80 7.20 5/1203 88231
        ===TALKSASA===cores
        8
        ===TALKSASA===uptime
        864000
        ===TALKSASA===queue
        3
        {$queueModified}
        ===TALKSASA===end
        OUT;
    }

    private function debian(): string
    {
        return <<<'OUT'
        ===TALKSASA===init
        systemd
        ===TALKSASA===units
        apache2.service loaded active running
        mariadb.service loaded active running
        exim.service loaded active running
        ssh.service loaded active running
        ===TALKSASA===started
        apache2 900000000
        ===TALKSASA===df
        /dev/sda1 107374182400 10737418240 96636764160 10% /
        ===TALKSASA===inodes
        /dev/sda1 ext4 6553600 200000 6353600 4% /
        ===TALKSASA===mem
        Mem: 8388608000 2147483648 6241124352 0 0 6241124352
        Swap: 0 0 0
        ===TALKSASA===load
        0.40 0.30 0.20 1/200 4321
        ===TALKSASA===cores
        4
        ===TALKSASA===uptime
        3600
        ===TALKSASA===queue
        skip
        ===TALKSASA===end
        OUT;
    }
}
