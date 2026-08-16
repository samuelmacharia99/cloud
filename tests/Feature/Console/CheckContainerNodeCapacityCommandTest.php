<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CheckContainerNodeCapacityCommand;
use App\Models\Node;
use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class CheckContainerNodeCapacityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(
            ['key' => 'notify_admin_node_scale_out'],
            ['value' => 'true']
        );
        Cache::flush();
    }

    public function test_alerts_when_an_application_host_reaches_scale_out_threshold(): void
    {
        $node = Node::factory()->containerHost()->create([
            'name' => 'app-host-1',
            'hostname' => 'app1.example.test',
            'cpu_cores' => 8,
            'ram_gb' => 16,
            'storage_gb' => 200,
            'cpu_used' => 10,
            'ram_used_gb' => 12,
            'storage_used_gb' => 20,
            'status' => 'online',
            'is_active' => true,
        ]);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('notifyAdminNodeScaleOutNeeded')
            ->once()
            ->with(
                Mockery::on(fn (Node $alerted) => $alerted->is($node)),
                Mockery::on(fn (array $evaluation) => ($evaluation['pressure_percent'] ?? 0) >= 70),
                70
            );
        $notifications->shouldReceive('notifyAdminFleetScaleOutNeeded')->once();
        $this->app->instance(NotificationService::class, $notifications);

        $command = $this->app->make(CheckContainerNodeCapacityCommand::class);
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            $output
        ));

        $this->assertSame(0, $command->handle());
        $this->assertStringContainsString('1 at/above 70% pressure', $output->fetch());
    }

    public function test_respects_alert_cooldown(): void
    {
        Node::factory()->containerHost()->create([
            'cpu_cores' => 8,
            'ram_gb' => 16,
            'storage_gb' => 200,
            'cpu_used' => 10,
            'ram_used_gb' => 12,
            'storage_used_gb' => 20,
            'status' => 'online',
            'is_active' => true,
        ]);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('notifyAdminNodeScaleOutNeeded')->once();
        $notifications->shouldReceive('notifyAdminFleetScaleOutNeeded')->once();
        $this->app->instance(NotificationService::class, $notifications);

        $command = $this->app->make(CheckContainerNodeCapacityCommand::class);
        $command->setLaravel($this->app);
        $style = new OutputStyle(
            new ArrayInput([]),
            new BufferedOutput
        );
        $command->setOutput($style);
        $this->assertSame(0, $command->handle());

        $command->setOutput($style);
        $this->assertSame(0, $command->handle());
    }
}
