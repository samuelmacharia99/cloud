<?php

namespace Tests\Unit\Console;

use App\Console\Scheduling\ApplicationSchedule;
use App\Models\CronJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ApplicationScheduleMissingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_commands_are_disabled_instead_of_scheduled(): void
    {
        Config::set('scheduler.enabled', true);
        Config::set('scheduler.retired_commands', ['cron:collect-mail-usage-snapshots']);

        $job = CronJob::create([
            'name' => 'Collect Mail Usage Snapshots',
            'description' => 'Orphaned after usage-billing revert',
            'command' => 'cron:collect-mail-usage-snapshots',
            'schedule' => '15 * * * *',
            'enabled' => true,
            'last_status' => 'success',
        ]);

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        app(ApplicationSchedule::class)->configure($schedule);

        $this->assertFalse((bool) $job->fresh()->enabled);

        $scheduled = collect($schedule->events())->map(
            fn ($event) => (string) ($event->command ?? $event->description ?? '')
        );

        $this->assertFalse(
            $scheduled->contains(fn ($name) => str_contains($name, 'collect-mail-usage-snapshots'))
        );
    }
}
