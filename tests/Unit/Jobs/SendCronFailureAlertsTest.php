<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendCronFailureAlerts;
use App\Models\CronJob;
use App\Models\User;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class SendCronFailureAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cron_failure_alerts_telegram_and_never_emails(): void
    {
        Mail::fake();

        User::factory()->create([
            'is_admin' => true,
            'email' => 'admin@example.com',
        ]);

        $job = CronJob::create([
            'name' => 'Mark invoices overdue',
            'command' => 'cron:mark-invoices-overdue',
            'schedule' => '* * * * *',
            'enabled' => true,
        ]);

        $telegram = Mockery::mock(TelegramMonitorBridge::class);
        $telegram->shouldReceive('systemAlert')
            ->once()
            ->with('Cron job failed', Mockery::on(fn (array $fields) => ($fields['Job'] ?? null) === 'Mark invoices overdue'));
        $this->app->instance(TelegramMonitorBridge::class, $telegram);

        (new SendCronFailureAlerts($job->id))->handle($telegram);

        Mail::assertNothingSent();
    }
}
