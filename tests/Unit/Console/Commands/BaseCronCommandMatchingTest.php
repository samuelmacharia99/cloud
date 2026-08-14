<?php

namespace Tests\Unit\Console\Commands;

use App\Models\CronJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaseCronCommandMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_option_bearing_database_command_is_matched_to_base_artisan_signature(): void
    {
        $job = CronJob::create([
            'name' => 'Prune container metrics',
            'command' => 'cron:prune-container-metrics --days=30',
            'schedule' => '0 2 * * *',
            'enabled' => true,
        ]);

        $this->artisan('cron:prune-container-metrics', ['--days' => 30])
            ->assertSuccessful();

        $this->assertSame('success', $job->fresh()->last_status);
        $this->assertNotNull($job->fresh()->last_ran_at);
    }
}
